# ROMA — Production Readiness & Bug Audit Report

**Project:** ROMA (Daycare Management System)
**Codebase:** `d:\roma-finaly\roma97` (PHP 8.x, procedural)
**Audit Date:** 2026-06-26
**Auditor Role:** Senior QA Engineer & Production Readiness Auditor
**Status:** Analysis only — no code modified

---

## Table of Contents

1. [Critical Bugs](#1-critical-bugs)
2. [High Bugs](#2-high-bugs)
3. [Medium Bugs](#3-medium-bugs)
4. [Low Bugs](#4-low-bugs)
5. [Summary Matrix](#5-summary-matrix)

---

## 1. Critical Bugs

### BUG-C01 — Fatal SQL error: non-existent table name `کودک` in teacher dashboard
- **File:** `teacher/index.php`
- **Line:** 52
- **Category:** Fatal error / Invalid SQL
- **Root cause:** The children-fetching query uses `INNER JOIN کودک ch ON ch.id = cc.child_id` — the Persian word `کودک` (meaning "child") is used as the table alias/name instead of the actual table `children`. MySQL will throw `Table 'rooma_db.کودک' doesn't exist` (or a syntax error depending on identifier quoting), and the exception is caught at line 72, setting `$error` and leaving `$children = []`. The entire teacher dashboard (children list, today's attendance, daily report widget) is **permanently broken** for every teacher on every request.
- **Impact:** Teacher portal dashboard is non-functional. Teachers cannot see their classroom, children, attendance, or daily reports.
- **Fix:** Replace `INNER JOIN کودک ch` with `INNER JOIN children ch` (and update any reference if the alias differs). The correct query already exists in `teacher/report.php` line 44 (`INNER JOIN children c ON c.id = cc.child_id`) — copy that pattern.

### BUG-C02 — Broadcast messaging is broken: "send to all parents" creates a single shared row
- **File:** `admin/messages.php` (line 27, 35-36) and `parent/messages.php` (lines 25, 43-44, 54)
- **Category:** Logic defect / Data integrity
- **Root cause:** When an admin selects "ارسال به همه والدین" (send to all parents), `$recipientId` becomes `null`, and **one** row is inserted with `parent_id = NULL`. The parent inbox query (`parent/messages.php` line 43) uses `WHERE parent_id IS NULL OR parent_id = ?`, so every parent sees that single shared row. When **any** parent opens it, `parent/messages.php` line 25 runs `UPDATE messages SET is_read = 1 WHERE id = ? AND (parent_id IS NULL OR parent_id = ?)` — but because the row's `parent_id` is `NULL`, the `parent_id IS NULL` branch matches, so the single row is marked read for **all** parents simultaneously. One parent reading the broadcast marks it read for every other parent.
- **Impact:** Broadcast messages appear "read" for all parents as soon as one parent opens them. Teachers using `teacher/messages.php` have the same design flaw. Parents miss announcements.
- **Fix:** Either (a) insert one row **per parent** when sending a broadcast (fan-out), or (b) introduce a `message_recipients` junction table (`message_id`, `parent_id`, `is_read`) so read-state is per-recipient. Option (b) is the correct relational design.

### BUG-C03 — Runtime DDL executes on every request including for locked-out users
- **Files:** `login.php` (line 25), `admin/login.php` (line 25), `teacher/login.php` (line 40), `parent/index.php` (line 29), `parent/profile.php` (line 160), `parent/add-child.php` (line 161), `admin/attendance.php` (line 133), `admin/settings.php` (line 149), `admin/news.php` (line 149), `includes/auth.php` (lines 64, 118, 167, 201), `includes/audit.php` (line 21)
- **Category:** Performance / Scalability / Concurrency
- **Root cause:** `initializeXxxTables()` functions run `CREATE TABLE IF NOT EXISTS` (and in `includes/db.php` `initializeTeachersTables()`, swallowed `ALTER TABLE ... ADD COLUMN`) on **every request** that touches the feature. In `login.php`, this even runs *before* the brute-force lockout check — a locked-out attacker still triggers DDL. Under concurrent traffic this acquires MySQL metadata locks, serialising requests and causing lock-wait timeouts.
- **Impact:** Latency on every request; intermittent 500 errors under load; metadata-lock contention; potential lock-wait timeouts during traffic spikes.
- **Fix:** Remove all `initializeXxxTables()` calls from request paths. Generate a baseline `schema.sql` and use a migration tool (Phinx / Doctrine Migrations) executed via CLI only. Gate any seeding behind an explicit `bin/install` command.

---

## 2. High Bugs

### BUG-H01 — `lastInsertId()` returns 0 on UPSERT, logging wrong audit entity ID
- **File:** `admin/tuition.php`
- **Line:** 73
- **Category:** Invalid audit data
- **Root cause:** The tuition payment insert uses `INSERT ... ON DUPLICATE KEY UPDATE`. On an update (same `child_id` + `month_year`), MySQL does **not** insert a new row, so `PDO::lastInsertId()` returns `0` (or the existing row's id depending on driver mode — with `ATTR_EMULATE_PREPARES => false` it returns 0). The audit call `recordAudit('tuition.payment', 'tuition_payment', (int) $pdo->lastInsertId())` therefore logs `entity_id = 0` for every update, making it impossible to trace which tuition record was modified.
- **Impact:** Audit trail is unreliable for tuition updates; security/compliance forensics broken.
- **Fix:** Detect insert-vs-update via `$pdo->rowCount()` (1 = insert, 2 = update on MySQL) or query `SELECT id FROM tuition_payments WHERE child_id = ? AND month_year = ?` after the upsert to get the real id.

### BUG-H02 — Duplicate attendance form controls submit conflicting values when JS is disabled
- **File:** `admin/attendance.php`
- **Lines:** 295–424 (table) and 365–424 (mobile cards)
- **Category:** Broken forms / Data corruption
- **Root cause:** The page renders **two** complete sets of attendance inputs — one in a `<table>` for desktop, one in `<div class="attendance-cards">` for mobile — both inside the **same `<form>`**. Each child has two `name="attendance[$cid][status]"` radio groups, two `name="attendance[$cid][check_in]"` inputs, etc. A JavaScript block (lines 434–463) disables the inactive set based on `matchMedia('(min-width: 768px)')`. **If JavaScript is disabled or fails to load**, both sets are enabled and submit. PHP receives the last value in `$_POST` (browsers send both; PHP keeps the last), which is **non-deterministic** (DOM order varies) — desktop edits can be silently overwritten by the mobile card's default value.
- **Impact:** Attendance data corruption when JS fails; silent overwrites; inconsistent records.
- **Fix:** Render only one set of controls and use CSS to restyle for mobile, OR give the two sets distinct `name` attributes and merge explicitly server-side, OR move the mobile card inputs `disabled` by default in HTML (so they only submit when JS enables them).

### BUG-H03 — Currency symbol mismatch: `$` shown instead of تومان
- **File:** `admin/index.php`
- **Line:** 96
- **Category:** Display defect / Localization
- **Root cause:** The monthly tuition metric renders `$<?= e(number_format($metrics['monthly_tuition'], 2)) ?>`, hardcoding the US dollar sign. The rest of the application (`parent/payments.php` line 70, 111, 138) formats money as `<amount> تومان`. This is inconsistent and confusing for Persian-language administrators.
- **Impact:** Incorrect currency display; potential misinterpretation of financial figures.
- **Fix:** Replace `$` with `تومان` (or use a shared money-formatting helper: `persianNumber(number_format($amount, 2)) . ' تومان'`).

### BUG-H04 — `month_year` filter uses `LIKE :month . '%'` defeating indexes
- **File:** `admin/index.php`
- **Line:** 46-47
- **Category:** Performance / Invalid SQL pattern
- **Root cause:** `$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM tuition_payments WHERE payment_date LIKE :month");` with `:month => $currentMonth . '%'`. The `LIKE '2026-06%'` predicate prevents MySQL from using any index on `payment_date` (a range scan with leading-wildcard-free prefix *can* use an index, but the column is `DATE` not `VARCHAR`, so the implicit type coercion defeats it). More importantly, `payment_date` is a DATE, and comparing with a string prefix is semantically wrong — it works only because MySQL coerces.
- **Impact:** Full table scan as `tuition_payments` grows; slow dashboard.
- **Fix:** Use an explicit date range: `WHERE payment_date >= :start AND payment_date < :next_month` with computed `DateTimeImmutable` boundaries, or `WHERE YEAR(payment_date) = :y AND MONTH(payment_date) = :m`.

### BUG-H05 — Missing index on `messages.is_read` / `(parent_id, is_read)` for unread count
- **File:** `includes/db.php` (schema, `initializeMessagingTable`) and `parent/header.php` (line 23)
- **Category:** Missing indexes / Performance
- **Root cause:** The unread-count query `SELECT COUNT(*) FROM messages WHERE (parent_id IS NULL OR parent_id = ?) AND is_read = 0` runs on **every parent portal page load** (in `parent/header.php`). The `messages` table (DDL in `includes/db.php` line 440-451) has only `INDEX idx_messages_parent_id (parent_id)` — there is **no index on `is_read`** and no composite `(parent_id, is_read)` index. As the messages table grows, this becomes a full scan per page load.
- **Impact:** Parent portal pages slow down linearly with message volume; potential lock contention.
- **Fix:** Add `INDEX idx_messages_parent_read (parent_id, is_read)` via a migration.

### BUG-H06 — `teacher/index.php` uses `e()` on already-escaped `photo` URL causing double-encoding
- **File:** `teacher/index.php`
- **Line:** 210, 215
- **Category:** Broken links / Display defect
- **Root cause:** Line 210: `$photoUrl = !empty($child['photo']) ? e((string) $child['photo']) : '';` then line 215: `<img src="<?= $photoUrl ?>" ...>`. The photo path (e.g. `assets/uploads/children/file.jpg`) is escaped with `e()` (htmlspecialchars) and stored in `$photoUrl`, then printed **without** `url()`. Compare to every other page which uses `e(url($child['photo']))`. The path is relative and missing the `SITE_URL` base, so if the teacher dashboard is served from a sub-path the image may 404. Additionally, the `alt` attribute on line 215 uses `<?= $childName ?>` where `$childName` was **already escaped** at line 206 (`$childName = e($child['first_name'] . ' ' . $child['last_name']);`) — so it's fine, but the `src` is wrong.
- **Impact:** Teacher avatars may not load; inconsistent URL construction.
- **Fix:** Use `src="<?= e(url((string) $child['photo'])) ?>"` like the other pages.

### BUG-H07 — `teacher/report.php` unique constraint allows only one daily report per child per day globally
- **File:** `includes/db.php` (schema lines 317-337) and `teacher/report.php` (line 66-74)
- **Category:** Data integrity / Logic defect
- **Root cause:** The `daily_reports` table has `UNIQUE KEY unique_child_report (child_id, report_date)` (after the migration in `initializeTeachersTables()`). `teacher/report.php` inserts `(teacher_id, classroom_id, child_id, report_date, ...)` with `ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), classroom_id = VALUES(classroom_id), ...`. Because the unique key is only `(child_id, report_date)`, if a child is **moved to a different classroom** or a different teacher takes over, the new teacher's upsert will **overwrite** the previous teacher's report for that day (changing `teacher_id` and `classroom_id`). The report is silently reassigned rather than preserved.
- **Impact:** Loss of historical report attribution; a teacher's report can be clobbered by another teacher editing "today's" report for the same child.
- **Fix:** Either change the unique key to `(classroom_id, child_id, report_date)` so reports are per-classroom-per-day, or add a separate historical report table and never overwrite.

### BUG-H08 — Brute-force pre-check runs after DDL initialization in login pages
- **Files:** `login.php` (lines 24-35), `admin/login.php` (lines 24-35), `teacher/login.php` (lines 20-23)
- **Category:** Security / Performance
- **Root cause:** In `login.php`, `initializeParentTables()` (line 25) runs **before** `checkBruteForce('parent_login')` (line 33). A locked-out attacker hitting the login page still triggers `CREATE TABLE IF NOT EXISTS` DDL on every request. The lockout check should be first (cheap, no DDL).
- **Impact:** Locked-out users still cause DDL load; minor security ordering issue.
- **Fix:** Move `checkBruteForce()` before `initializeParentTables()`, and remove DDL from the request path entirely (see BUG-C03).

### BUG-H09 — `admin/messages.php` hardcodes `$adminId` fallback to 1
- **File:** `admin/messages.php`
- **Line:** 17
- **Category:** Security / Logic defect
- **Root cause:** `$adminId = (int) ($_SESSION['admin_id'] ?? 1);` — if the session key is missing (e.g. session expired mid-request, or a code path that didn't set it), the code falls back to `admin_id = 1`. This means messages could be attributed to the wrong admin, and the "sent messages" list query (`WHERE sender_id = ?`) would show admin #1's messages to whoever is currently hitting the page.
- **Impact:** Misattributed messages; potential cross-admin data leakage if multiple admins exist.
- **Fix:** If `$_SESSION['admin_id']` is absent, redirect to login. Do not fall back to a hardcoded id.

---

## 3. Medium Bugs

### BUG-M01 — `parent/payments.php` payment-method enum mismatch with database
- **File:** `parent/payments.php`
- **Lines:** 99-106, 126-133
- **Category:** Invalid data / Display defect
- **Root cause:** The `match` expression maps `'card'`, `'bank_card'`, `'card_to_card'`, `'transfer'`, `'online'` to labels, but the database `tuition_payments.payment_method` ENUM (defined in `includes/db.php` line 414) only allows `('cash','bank_transfer','check')`. So `'card'`, `'online'`, etc. will never occur and fall through to `default => ucfirst($paymentMethod)` (line 105) or `default => $paymentMethod` (line 132). Conversely, `admin/tuition.php` line 175 only offers `cash`, `bank_transfer`, `check`. The extra cases are dead code.
- **Impact:** Not a crash, but misleading code; if the enum is ever extended inconsistently, labels break silently.
- **Fix:** Align the `match` arms with the actual ENUM values (`cash`, `bank_transfer`, `check`) and add a shared helper.

### BUG-M02 — `admin/tuition.php` payment date not validated
- **File:** `admin/tuition.php`
- **Line:** 33
- **Category:** Input validation / Data integrity
- **Root cause:** `$paymentDate = (string) ($_POST['payment_date'] ?? date('Y-m-d'));` — there is no validation that `$_POST['payment_date']` is a valid `Y-m-d` date. A malformed value (or an empty string) is sent straight to the `INSERT`. MySQL's `STRICT_ALL_TABLES` mode (set in `includes/db.php` line 33) will reject an invalid date with an exception, but the error is caught and shown as a generic "خطایی در بارگذاری اطلاعات شهریه رخ داد" — confusing for the admin.
- **Impact:** Poor UX; silent failure on bad input.
- **Fix:** Validate `payment_date` with the same `parseAttendanceDateString()`-style helper used in `admin/attendance.php`.

### BUG-M03 — `admin/attendance.php` POST handler runs inside the same `try` block as the GET list query
- **File:** `admin/attendance.php`
- **Lines:** 132-258
- **Category:** Logic defect
- **Root cause:** The entire POST processing (lines 136-224) and the GET list query (lines 226-251) are inside one `try` block. If the POST upsert throws (e.g. due to an invalid time format slipping past `normalizeAttendanceTime()`), the catch at line 252 sets `$errorMessage` but `$rows` is left as `[]`, so the attendance form renders empty — the admin loses the chance to re-edit the failed submission's values.
- **Impact:** On a save error, the form blanks out; admin must re-enter all data.
- **Fix:** Separate the POST handling `try/catch` from the GET list query, and on POST failure preserve `$_POST` values for the form.

### BUG-M04 — `parent/messages.php` requires `db.php` but not `parent_children_helpers.php` and has include-order side effects
- **File:** `parent/messages.php`
- **Lines:** 6-14
- **Category:** Missing includes / Side effects
- **Root cause:** The file `require_once`s `db.php` (line 6) **before** `auth.php` (line 7), `functions.php` (line 8), and `csrf.php` (line 9). `db.php` itself `require_once`s `config.php`, and `auth.php` starts a session at include scope. The order works here but is fragile. More importantly, `initializeMessagingTable()` (line 12) runs **before** `requireParentLogin()` (line 14) — an unauthenticated user hitting `parent/messages.php` triggers DDL before being redirected to login.
- **Impact:** Unauthenticated requests cause DDL; fragile include order.
- **Fix:** Call `requireParentLogin()` before `initializeMessagingTable()`, and remove DDL from the request path (BUG-C03).

### BUG-M05 — `admin/messages.php` and `teacher/messages.php` use double-quoted string literals in SQL
- **File:** `admin/messages.php` (lines 52, 58), `teacher/messages.php`, `parent/messages.php` (lines 32, 50)
- **Category:** SQL correctness / Portability
- **Root cause:** Queries use `WHERE sender_type = "admin"` with **double quotes** for string literals. MySQL accepts this by default (with `ANSI_QUOTES` disabled), but it violates the SQL standard (where double quotes denote identifiers). If `sql_mode` ever includes `ANSI_QUOTES` (or the app moves to PostgreSQL), these queries break. The rest of the codebase correctly uses single quotes.
- **Impact:** Portability risk; inconsistency.
- **Fix:** Change `"admin"` → `'admin'` everywhere in SQL.

### BUG-M06 — `teacher/index.php` `attendanceBadge()` returns raw HTML, echoed unescaped
- **File:** `teacher/index.php`
- **Lines:** 96-105, 235
- **Category:** XSS risk (low — internal data)
- **Root cause:** `attendanceBadge(?string $status): string` returns an HTML string like `'<span class="badge badge-success">حاضر</span>'` and it is echoed with `<?= attendanceBadge(...) ?>` (no `e()`). The `$status` values come from the database ENUM and are constrained, so this is not currently exploitable, but it establishes an unsafe pattern: if a future developer passes user-controlled data into a similar helper, it becomes an XSS vector.
- **Impact:** Low immediate risk; unsafe pattern.
- **Fix:** Either escape the label inside the helper and return safe HTML, or build the badge in the template with `e()`.

### BUG-M08 — `admin/settings.php` logo upload does not check `class_exists('finfo')` before `new finfo()`
- **File:** `admin/settings.php`
- **Lines:** 117-118
- **Category:** Fatal error (environment)
- **Root cause:** `$finfo = new finfo(FILEINFO_MIME_TYPE);` is called unconditionally, unlike `parent/profile.php` (line 119: `if (class_exists('finfo'))`) and `admin/news.php` (line 112: `if (class_exists('finfo'))`). If the `fileinfo` extension is not installed, this throws a fatal `Error: Class "finfo" not found`, caught by the outer `try/catch` at line 249, resulting in a generic "تنظیمات ذخیره نشد" error — but the logo upload always fails on such systems.
- **Impact:** Logo upload impossible without the fileinfo extension; inconsistent with other upload handlers.
- **Fix:** Wrap in `if (class_exists('finfo'))` like the other handlers (note: `fileinfo` is enabled by default since PHP 5.3, but the inconsistency is a real defect).

### BUG-M09 — `parent/payments.php` displays English date labels via `ucfirst()` for unknown payment methods
- **File:** `parent/payments.php`
- **Line:** 105
- **Category:** Localization
- **Root cause:** `default => ucfirst($paymentMethod)` shows an English-capitalised string (e.g. `Bank_transfer`) in an otherwise fully-Persian UI. Line 132 (mobile view) uses `default => $paymentMethod` (no ucfirst) — inconsistent.
- **Impact:** English text appears in Persian UI for unexpected enum values.
- **Fix:** Provide a Persian label for the `default` case (e.g. `پیش‌فرض` or the raw value).

### BUG-M10 — `admin/index.php` references `$event['location']` which does not exist in the `events` schema
- **File:** `admin/index.php`
- **Lines:** 223-225
- **Category:** PHP notice / Undefined variable
- **Root cause:** The upcoming-events loop checks `<?php if (!empty($event['location'])): ?>` and echoes `<?= e($event['location']) ?>`, but the `events` table (defined in `includes/db.php` lines 245-257) has **no `location` column** — only `title`, `description`, `event_date`, `start_time`, `end_time`, `category`, `status`. The query at line 55 selects only `id, title, event_date`. With `error_reporting(E_ALL)` (set in `error_handler.php`), accessing `$event['location']` emits `Warning: Undefined array key "location"` on every dashboard load (twice per event).
- **Impact:** PHP warnings pollute logs; noisy; potential display in dev mode.
- **Fix:** Remove the `location` check, or add a `location` column via migration if the feature is desired.

### BUG-M11 — `admin/index.php` uses `date('M j, Y', ...)` and `date('l, M j, Y', ...)` (English) in a Persian UI
- **File:** `admin/index.php`
- **Lines:** 189, 222
- **Category:** Localization
- **Root cause:** Recent registrations show `date('M j, Y', strtotime($child['created_at']))` (e.g. "Jun 26, 2026") and events show `date('l, M j, Y', ...)` — English month/day names in an otherwise Persian dashboard. The rest of the app uses `shamsiDate()` / `formatPersianDate()`.
- **Impact:** Inconsistent localization; English text in Persian UI.
- **Fix:** Use `shamsiDate($child['created_at'])` and `persianDayName($event['event_date']) . ' ' . shamsiDate($event['event_date'])`.

### BUG-M12 — `index.php` (public homepage) slide and news image `src` missing `url()` base
- **File:** `index.php`
- **Lines:** 102, 283
- **Category:** Broken assets
- **Root cause:** Line 102: `<img src="<?= e($slide['image']) ?>" ...>` and line 283: `<img src="<?= e($newsItem['image']) ?>" ...>`. The `image` column stores a relative path like `assets/uploads/news_xxx.jpg`. The `src` is printed **without** `url()`, so it resolves relative to the current page URL. On the homepage (`index.php`) this happens to work, but if the page were served from a sub-path it would 404. Compare to `admin/news.php` line 306 which correctly uses `e(url($editNewsItem['image']))`.
- **Impact:** Images may not load if `SITE_URL` is not the web root; inconsistent URL construction.
- **Fix:** Use `src="<?= e(url((string) $slide['image'])) ?>"` and `src="<?= e(url((string) $newsItem['image'])) ?>"`.

---

## 4. Low Bugs

### BUG-L01 — `teacher/index.php` `$childName` and `$parentName` are pre-escaped, then echoed unescaped
- **File:** `teacher/index.php`
- **Lines:** 206-207, 215, 224, 239
- **Category:** XSS (low — DB data)
- **Root cause:** Lines 206-207: `$childName = e($child['first_name'] . ' ' . $child['last_name']);` and `$parentName = e(...)`. These are then echoed as `<?= $childName ?>` (no double-escape, correct) and `alt="<?= $childName ?>"` (fine). However on line 235 `attendanceBadge($child['attendance_status'] !== '' ? $child['attendance_status'] : null)` passes a raw DB value into a helper that returns HTML — fine because it's an ENUM. This is **not exploitable** today but mixes escaping strategies (pre-escape vs escape-at-output).
- **Fix:** Standardise on escape-at-output: store raw names, echo with `e()` at the point of use.

### BUG-L02 — `parent/messages.php` message preview uses `mb_substr` without encoding flag
- **File:** `parent/messages.php`
- **Line:** 90
- **Category:** PHP warning / Encoding
- **Root cause:** `<?= e(mb_substr($msg['body'], 0, 100)) ?>` and `<?= mb_strlen($msg['body']) > 100 ? '...' : '' ?>` call `mb_substr`/`mb_strlen` **without** the `'UTF-8'` encoding argument. They rely on `mbstring.internal_encoding` (deprecated since PHP 5.6, removed-default in 8.x) — in PHP 8.3 this falls back to the default charset, which is usually UTF-8, but is not guaranteed. If `mbstring.internal_encoding` is overridden, Persian text could be truncated mid-character.
- **Impact:** Potential mojibake in previews depending on server config.
- **Fix:** Pass `'UTF-8'` explicitly: `mb_substr($msg['body'], 0, 100, 'UTF-8')` and `mb_strlen($msg['body'], 'UTF-8')`.

### BUG-L03 — `admin/attendance.php` `attendanceAgeFromDob()` returns English digits, inconsistent with `parentChildDisplayAge()`
- **File:** `admin/attendance.php`
- **Lines:** 65-86
- **Category:** Localization inconsistency
- **Root cause:** `attendanceAgeFromDob()` returns `$months . ' ماه'` and `$years . ' سال'` with **Latin** digits, while `parent_children_helpers.php::parentChildDisplayAge()` wraps with `persianNumber()`. The attendance admin UI therefore shows mixed-digit ages.
- **Impact:** Minor visual inconsistency.
- **Fix:** Wrap return values in `persianNumber()`, or reuse `parentChildDisplayAge()`.

### BUG-L04 — `admin/messages.php` mixes English and Persian text in modal
- **File:** `admin/messages.php`
- **Lines:** 173, 145
- **Category:** Localization
- **Root cause:** Line 173: `<strong>To:</strong>` (English) and line 145 uses `'<strong>همه والدین</strong>'` (Persian) for the same concept in different places. The modal view says "To:" while the table says "همه والدین".
- **Impact:** Inconsistent UI language.
- **Fix:** Standardise on Persian: `گیرنده:` instead of `To:`.

### BUG-L06 — `admin/teachers.php` `login_as` IIFE uses `: never` but `redirect()` may not execute if an inner check fails
- **File:** `admin/teachers.php`
- **Lines:** 105-134
- **Category:** Logic (works, but fragile)
- **Root cause:** The closure `(function () use (...): never { ... redirect(...); })()` is invoked inside `match`. If the teacher is inactive (`$row['status'] !== 'active'`), it calls `setFlash` + `redirect` (which `exit`s), so `never` is satisfied. But if neither `redirect` nor `throw` runs, PHP will complain that a `never`-typed function returned. Currently all paths redirect, so it works — but it's brittle.
- **Impact:** None today; future edits could trigger a TypeError.
- **Fix:** Ensure every branch `redirect()`s or `throw`s; consider restructuring outside `match`.

### BUG-L07 — `parent/profile.php` `uploadAvatar()` does not generate a `.htaccess` in the avatars directory
- **File:** `parent/profile.php`
- **Lines:** 132-151
- **Category:** Security hardening
- **Root cause:** `parent/add-child.php` (line 106) and `parent/profile.php` (line 138) create an `index.html` to prevent directory listing, but **only** `admin/teachers.php` (line 54) creates a `.htaccess` that blocks PHP execution in uploads. The `avatars/` and `children/` upload dirs do **not** get a `.htaccess` denying PHP execution. If an attacker uploads a `.php` disguised as an image (and bypasses MIME checks), it could be executed if the webserver misroutes.
- **Impact:** Defense-in-depth gap; depends on the `assets/uploads/.htaccess` at the root (if present) covering subdirs.
- **Fix:** Create a `.htaccess` with `Deny from all` for `.php` in every upload subdirectory, or rely on the project-root `.htaccess`.

### BUG-L08 — `parent/attendance.php` weekly summary `match` uses `default => null` (statement has no effect)
- **File:** `parent/attendance.php`
- **Lines:** 170-176
- **Category:** PHP notice / Logic
- **Root cause:** `match($status) { 'present' => $totalPresent++, ..., default => null };` — `$totalPresent++` returns the **old** value (0 on first), not the incremented value, and the `match` result is discarded. The side-effect (increment) works, but `match` returning `null` for `default` is a no-op. This is **functionally correct** (the counters increment via the `++` side effect) but reads confusingly.
- **Impact:** None functionally; readability/maintainability.
- **Fix:** Use a plain `if/elseif` or `match` with `$totalPresent` (pre-increment `++$totalPresent`) for clarity.

### BUG-L09 — `admin/teachers.php` salary stored as `(float)` losing precision
- **File:** `admin/teachers.php`
- **Line:** 192
- **Category:** Data integrity
- **Root cause:** `$salaryVal = $fields['salary'] !== '' ? (float) $fields['salary'] : null;` casts the input to PHP `float` (IEEE 754 double) before sending to PDO. The DB column is `DECIMAL(12,2)`. Float→DECIMAL is safe, but `0.1 + 0.2`-style rounding can occur for inputs like `12345.678` (truncated to `12345.68` by MySQL). More importantly, very large salaries exceed float precision.
- **Impact:** Minor rounding; unlikely to matter for typical salary ranges.
- **Fix:** Pass the string directly to PDO (MySQL will coerce) or validate with a regex and store as string.

### BUG-L10 — `admin/tuition.php` `amount` stored as `(float)` with no upper-bound check
- **File:** `admin/tuition.php`
- **Line:** 32
- **Category:** Data integrity
- **Root cause:** `$amount = (float) ($_POST['amount'] ?? 0);` — only checks `$amount <= 0` (line 38). No max check; `DECIMAL(12,2)` allows up to 99999999999.99. A typo (e.g. `1e20`) would be stored as `99999999999.99` after truncation, or throw under `STRICT_ALL_TABLES` if it overflows.
- **Impact:** Possible silent truncation of absurd values; no UX feedback.
- **Fix:** Add a reasonable upper bound (e.g. `< 1000000000`) and a precision check.

---

## 5. Summary Matrix

| ID | Severity | File | Line | Category | One-line summary |
|---|---|---|---|---|---|
| BUG-C01 | 🔴 Critical | `teacher/index.php` | 52 | Fatal SQL | `INNER JOIN کودک` instead of `children` — teacher dashboard broken |
| BUG-C02 | 🔴 Critical | `admin/messages.php`, `parent/messages.php` | 27,35 / 25,43 | Data integrity | "Send to all" = 1 shared row; read-state leaks across parents |
| BUG-C03 | 🔴 Critical | many | many | Performance/Scale | Runtime DDL on every request incl. locked-out users |
| BUG-H01 | 🟠 High | `admin/tuition.php` | 73 | Audit | `lastInsertId()` returns 0 on upsert → wrong audit id |
| BUG-H02 | 🟠 High | `admin/attendance.php` | 295-424 | Broken forms | Duplicate form controls submit conflicting data without JS |
| BUG-H03 | 🟠 High | `admin/index.php` | 96 | Localization | `$` shown instead of تومان |
| BUG-H04 | 🟠 High | `admin/index.php` | 46-47 | Performance | `LIKE :month.'%'` defeats indexes |
| BUG-H05 | 🟠 High | `includes/db.php`, `parent/header.php` | schema / 23 | Missing index | No index on `messages.is_read`/`(parent_id,is_read)` |
| BUG-H06 | 🟠 High | `teacher/index.php` | 210,215 | Broken links | Avatar `src` missing `url()` base |
| BUG-H07 | 🟠 High | `includes/db.php`, `teacher/report.php` | schema / 66 | Data integrity | `daily_reports` unique key allows report clobbering on classroom change |
| BUG-H08 | 🟠 High | `login.php` et al. | 24-35 | Security/Perf | Brute-force check after DDL init |
| BUG-H09 | 🟠 High | `admin/messages.php` | 17 | Security | `$adminId` fallback to 1 |
| BUG-M01 | 🟡 Medium | `parent/payments.php` | 99-133 | Data mismatch | Payment-method enum cases don't match DB |
| BUG-M02 | 🟡 Medium | `admin/tuition.php` | 33 | Validation | `payment_date` not validated |
| BUG-M03 | 🟡 Medium | `admin/attendance.php` | 132-258 | Logic | POST+GET in one `try`; form blanks on error |
| BUG-M04 | 🟡 Medium | `parent/messages.php` | 6-14 | Includes/Side effects | DDL before auth check; fragile include order |
| BUG-M05 | 🟡 Medium | `admin/messages.php`, `teacher/messages.php`, `parent/messages.php` | 52,58 / — | SQL portability | Double-quoted string literals in SQL |
| BUG-M06 | 🟡 Medium | `teacher/index.php` | 96-105 | XSS pattern | `attendanceBadge()` returns unescaped HTML |
| BUG-M08 | 🟡 Medium | `admin/settings.php` | 117-118 | Fatal (env) | `new finfo()` without `class_exists` check |
| BUG-M09 | 🟡 Medium | `parent/payments.php` | 105,132 | Localization | English `ucfirst()` in Persian UI |
| BUG-M10 | 🟡 Medium | `admin/index.php` | 223-225 | PHP notice | `$event['location']` column doesn't exist |
| BUG-M11 | 🟡 Medium | `admin/index.php` | 189,222 | Localization | English `date()` in Persian dashboard |
| BUG-M12 | 🟡 Medium | `index.php` | 102,283 | Broken assets | Slide/news `img src` missing `url()` |
| BUG-L01 | 🟢 Low | `teacher/index.php` | 206-207 | XSS pattern | Pre-escaped variables echoed unescaped |
| BUG-L02 | 🟢 Low | `parent/messages.php` | 90 | Encoding | `mb_substr`/`mb_strlen` without `'UTF-8'` |
| BUG-L03 | 🟢 Low | `admin/attendance.php` | 65-86 | Localization | Age returns Latin digits |
| BUG-L04 | 🟢 Low | `admin/messages.php` | 145,173 | Localization | Mixed "To:" / "همه والدین" |
| BUG-L06 | 🟢 Low | `admin/teachers.php` | 105-134 | Logic (fragile) | `: never` IIFE relies on all paths redirecting |
| BUG-L07 | 🟢 Low | `parent/profile.php`, `parent/add-child.php` | 132-151 | Security | No `.htaccess` in `avatars/`,`children/` upload dirs |
| BUG-L08 | 🟢 Low | `parent/attendance.php` | 170-176 | Readability | `match` with `++` side-effect; `default => null` no-op |
| BUG-L09 | 🟢 Low | `admin/teachers.php` | 192 | Data integrity | Salary cast to `float` |
| BUG-L10 | 🟢 Low | `admin/tuition.php` | 32 | Validation | No upper bound on `amount` |

---

## PHP 8.3 Compatibility Notes

- **`declare(strict_types=1)`** is used consistently ✅ — no implicit type coercion issues.
- **`match` expressions** are used correctly (all have `default` arms) ✅.
- **`readonly` / enums / first-class callable syntax** — not used, so no 8.3-specific breakage.
- **`mb_substr` without encoding** (BUG-L02) — not a PHP 8.3 fatal, but `mbstring.internal_encoding` is deprecated; pass `'UTF-8'` explicitly.
- **`utf8_encode()`/`utf8_decode()`** — not used (good; they're deprecated in 8.2+).
- **`${var}` string interpolation** — not used (deprecated in 8.2+).
- **`FILTER_SANITIZE_STRING` / `FILTER_SANITIZE_STRIPPED`** — not used (removed in 8.1+) ✅.
- No use of `mysql_*`, `create_function`, `each`, etc. ✅

**Overall PHP 8.3 compatibility:** Good. The main risk is BUG-C01 (fatal SQL) which is a logic bug, not a version-compat bug.

---

## Recommended Immediate Fix Order (next 7 days)

1. **BUG-C01** — Fix `teacher/index.php` line 52 (`کودک` → `children`). This is a one-line fix that restores the entire teacher dashboard.
2. **BUG-C02** — Redesign broadcast messaging (fan-out per parent or `message_recipients` table).
3. **BUG-H09** — Remove the `$adminId ?? 1` fallback; redirect to login instead.
4. **BUG-H03** — Replace `$` with `تومان` on the admin dashboard.
5. **BUG-M10** — Remove the `$event['location']` reference (emits warnings on every dashboard load).
6. **BUG-H02** — Disable one attendance form set by default so non-JS users don't corrupt data.
7. **BUG-C03 / BUG-H08** — Plan migration removal (larger effort, but stop the DDL-on-every-request bleeding).

---

*End of report. No source code was modified during this audit.*