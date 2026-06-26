# Test Checklist — Production Critical Fixes v1

**Date:** 2026-06-26
**Tester:** _______________
**Environment:** ☐ Local ☐ Staging ☐ Production (cPanel)

---

## 0. Pre-flight — Setup Script

- [ ] `schema.sql` exists in project root
- [ ] `setup.php` exists in project root
- [ ] Database credentials correct in `config.php` (or `config.local.php`)
- [ ] Run `php setup.php` — completes with "Setup complete! ROMA is ready."
- [ ] Verify all tables created (check via phpMyAdmin / `SHOW TABLES`)
- [ ] Verify `idx_messages_parent_read` index exists on `messages` table
- [ ] Verify default admin `admin` / `admin123` created (if DB was empty)
- [ ] Verify upload directories exist: `assets/uploads/avatars`, `children`, `certificates`
- [ ] Verify `.htaccess` exists in each upload subdirectory blocking `.php`
- [ ] **DELETE `setup.php`** from the server after setup

---

## 1. BUG-C01 — Teacher Dashboard (Critical)

**Goal:** Teacher dashboard loads without errors and shows children.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 1.1 | Log in as a teacher with an assigned classroom | Redirect to `teacher/index.php` | ☐ |
| 1.2 | Dashboard loads | No error message; classroom name shown | ☐ |
| 1.3 | Children list appears | Children of that classroom shown with names/photos | ☐ |
| 1.4 | Today's attendance shown per child | Badge per child (حاضر/غایب/تأخیر/ثبت نشده) | ☐ |
| 1.5 | Daily report widget shows today's report or "create" prompt | Correct | ☐ |
| 1.6 | Teacher with NO classroom logs in | "هیچ کلاسی به شما اختصاص داده نشده است" message | ☐ |
| 1.7 | Check `logs/error.log` | No `Table 'rooma_db.کودک'` errors | ☐ |

---

## 2. BUG-C02 — Broadcast Messaging (Critical)

**Goal:** Broadcast messages have independent read-state per parent.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 2.1 | Log in as admin → Messages → Send "Test Broadcast" to "همه والدین" | Success message shows count sent | ☐ |
| 2.2 | Check DB: multiple `messages` rows created (one per parent) with same subject | Multiple rows, distinct `parent_id` | ☐ |
| 2.3 | Log in as Parent A → Messages | Broadcast appears as unread | ☐ |
| 2.4 | Parent A opens the broadcast | Marked read for Parent A only | ☐ |
| 2.5 | Log in as Parent B (different parent) | Broadcast still shows as **unread** | ☐ |
| 2.6 | Parent B opens it → marked read for B only | Correct | ☐ |
| 2.7 | Teacher sends "Classroom Broadcast" to "همه والدین کلاس من" | Fan-out to classroom parents | ☐ |
| 2.8 | Verify DB: one row per classroom parent | Correct | ☐ |
| 2.9 | Single-recipient message still works (admin → one parent) | Only one row created | ☐ |

---

## 3. BUG-C03 — No Runtime DDL (Critical)

**Goal:** No `CREATE TABLE` or `ALTER TABLE` runs during page loads.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 3.1 | Load homepage `index.php` | Page renders; no DB errors | ☐ |
| 3.2 | Load `login.php` (parent login page) | Page renders | ☐ |
| 3.3 | Load `admin/login.php` | Page renders | ☐ |
| 3.4 | Load `teacher/login.php` | Page renders | ☐ |
| 3.5 | Enable MySQL query log / general log; load a page | No `CREATE TABLE` / `ALTER TABLE` / `SHOW COLUMNS` statements | ☐ |
| 3.6 | Load `parent/messages.php` (DDL was here before) | No DDL; page works | ☐ |
| 3.7 | Load `admin/messages.php` (DDL was here before) | No DDL; page works | ☐ |
| 3.8 | Check page load time vs before | Measurably faster (no metadata locks) | ☐ |

---

## 4. BUG-H01 — Tuition Audit ID (High)

**Goal:** Audit log records the correct tuition payment ID.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 4.1 | Admin → Tuition → Record new payment for Child X, Month 2026-06 | Success | ☐ |
| 4.2 | Check `audit_log` table → last row | `entity_type='tuition_payment'`, `entity_id` = real payment ID (not 0) | ☐ |
| 4.3 | Record **same** child + month again (upsert) | Success (updates existing) | ☐ |
| 4.4 | Check `audit_log` → latest tuition entry | `entity_id` = real payment ID (not 0) | ☐ |

---

## 5. BUG-H02 — Attendance Duplicate Controls (High)

**Goal:** Only one set of attendance controls submits.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 5.1 | Admin → Attendance (desktop, ≥768px) | Table visible; mobile cards hidden/disabled | ☐ |
| 5.2 | Set attendance for a child; save | Correct values persisted | ☐ |
| 5.3 | Reload; verify values persisted | Correct | ☐ |
| 5.4 | Resize to <768px (mobile) | Cards enabled; table disabled | ☐ |
| 5.5 | Set attendance on mobile; save | Correct values persisted | ☐ |
| 5.6 | **Disable JS in browser**; load attendance page | Mobile card controls are `disabled` | ☐ |
| 5.7 | With JS off, submit attendance | Only desktop table values submitted; no corruption | ☐ |

---

## 6. BUG-H05 — Messages Index (High)

**Goal:** `idx_messages_parent_read` index exists and query uses it.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 6.1 | Run `SHOW INDEX FROM messages` | `idx_messages_parent_read` listed | ☐ |
| 6.2 | `EXPLAIN SELECT COUNT(*) FROM messages WHERE (parent_id IS NULL OR parent_id = 1) AND is_read = 0` | Uses `idx_messages_parent_read` (possible_keys) | ☐ |
| 6.3 | Load `parent/index.php` (triggers unread count via header) | Fast; no slow query | ☐ |

---

## 7. BUG-H08 — Brute-force Check Ordering (High)

**Goal:** Locked-out users don't trigger DB connection before check.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 7.1 | Fail parent login 5 times (trigger lockout) | Lockout message appears | ☐ |
| 7.2 | On 6th attempt, check server/DB load | No DDL; minimal DB activity before lockout rejection | ☐ |
| 7.3 | Wait 15 min; login succeeds | Correct | ☐ |

---

## 8. BUG-H09 — Admin ID Fallback (High)

**Goal:** No hardcoded admin ID fallback.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 8.1 | Log in as admin normally → Messages | Works; sent list shows your messages | ☐ |
| 8.2 | (Dev test) Manually unset `$_SESSION['admin_id']` and load `admin/messages.php` | Redirects to `admin/login.php` | ☐ |
| 8.3 | Send a message as admin | `sender_id` = correct admin ID | ☐ |

---

## 9. Regression — Authentication

**Goal:** All auth flows still work after fixes.

| # | Step | Expected | Pass/Fail |
|---|------|----------|-----------|
| 9.1 | Parent login (valid) | Redirect to `parent/index.php` | ☐ |
| 9.2 | Parent login (wrong password) | Error; failed attempt recorded | ☐ |
| 9.3 | Parent logout | Session destroyed; redirect to `login.php` | ☐ |
| 9.4 | Admin login (valid) | Redirect to `admin/index.php` | ☐ |
| 9.5 | Admin logout | Redirect to `admin/login.php` | ☐ |
| 9.6 | Teacher login (valid) | Redirect to `teacher/index.php` | ☐ |
| 9.7 | Teacher logout | Redirect to `teacher/login.php` | ☐ |
| 9.8 | Teacher auto-login token | Creates session; marks token used | ☐ |
| 9.9 | CSRF token validation on all POST forms | Rejects missing/invalid token | ☐ |

---

## 10. Regression — Core Features

| # | Feature | Pass/Fail |
|---|---------|-----------|
| 10.1 | Parent registration | ☐ |
| 10.2 | Parent add child | ☐ |
| 10.3 | Parent view child detail | ☐ |
| 10.4 | Parent view payments | ☐ |
| 10.5 | Parent view attendance weekly | ☐ |
| 10.6 | Admin dashboard metrics | ☐ |
| 10.7 | Admin manage news (CRUD) | ☐ |
| 10.8 | Admin manage pages (CRUD) | ☐ |
| 10.9 | Admin manage slides (CRUD) | ☐ |
| 10.10 | Admin manage teachers (CRUD) | ☐ |
| 10.11 | Admin manage children | ☐ |
| 10.12 | Admin manage classrooms | ☐ |
| 10.13 | Admin record tuition | ☐ |
| 10.14 | Admin record salary | ☐ |
| 10.15 | Admin manage events | ☐ |
| 10.16 | Admin view audit log | ☐ |
| 10.17 | Admin settings (site + password change) | ☐ |
| 10.18 | Teacher daily report | ☐ |
| 10.19 | Teacher messages | ☐ |
| 10.20 | Public homepage (slides + news) | ☐ |
| 10.21 | Public news detail page | ☐ |
| 10.22 | Public CMS page (`page.php?slug=about`) | ☐ |

---

## 11. PHP 8.3 Compatibility

| # | Check | Pass/Fail |
|---|-------|-----------|
| 11.1 | No deprecation warnings in error log | ☐ |
| 11.2 | `declare(strict_types=1)` present in all files | ☐ |
| 11.3 | `mb_substr` / `mb_strlen` calls have `'UTF-8'` arg (except known L02) | ☐ |
| 11.4 | No use of removed functions (`utf8_encode`, `FILTER_SANITIZE_STRING`) | ☐ |

---

## 12. cPanel Deployment

| # | Step | Pass/Fail |
|---|------|-----------|
| 12.1 | Upload all files via cPanel File Manager or FTP | ☐ |
| 12.2 | Configure `config.local.php` with cPanel DB credentials | ☐ |
| 12.3 | Run `setup.php` via browser | ☐ |
| 12.4 | Delete `setup.php` | ☐ |
| 12.5 | Set `FORCE_HTTPS=true` in `config.local.php` (if SSL active) | ☐ |
| 12.6 | Set `DEVELOPMENT_MODE=false` in `config.local.php` | ☐ |
| 12.7 | Verify `.htaccess` rules active (mod_rewrite) | ☐ |
| 12.8 | Verify `logs/` directory writable | ☐ |
| 12.9 | Verify `assets/uploads/` writable | ☐ |

---

**Sign-off:** _______________ Date: _________