# Changelog — Production Critical Fixes v1

**Date:** 2026-06-26
**Scope:** Critical and High bug fixes from `docs/PRODUCTION_BUG_REPORT.md`

---

## Critical Fixes

### BUG-C01 — Teacher dashboard fatal SQL error
- **File:** `teacher/index.php` (line 52)
- **Change:** Replaced `INNER JOIN کودک ch` (Persian word for "child") with `INNER JOIN children ch` in the children-fetching query. The Persian word was being used as a table identifier, causing a fatal SQL `Table doesn't exist` error on every teacher dashboard load.
- **Impact:** Teacher portal dashboard now loads correctly — children list, attendance, and daily reports are visible.

### BUG-C02 — Broadcast messaging read-state leak
- **Files:** `admin/messages.php`, `teacher/messages.php`
- **Change:** "Send to all parents" and "Send to all classroom parents" previously inserted a single row with `parent_id = NULL`, shared across all recipients. Now the send logic **fans out** one row per parent, so each parent has an independent `is_read` state. Reading a broadcast no longer marks it read for everyone.
- **Impact:** Broadcast messages now behave correctly — each parent sees and dismisses their own copy.

### BUG-C03 — Runtime DDL removed from request path
- **Files:** `includes/db.php`, `includes/auth.php`, `includes/audit.php`, `schema.sql` (new), `setup.php` (new)
- **Change:** All `initialize*Tables()` functions have been converted to no-ops. Schema creation is now performed exclusively by `setup.php` (or `schema.sql` via `mysql` CLI) at install time. The runtime `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE ... ADD COLUMN` calls that ran on every request have been eliminated.
- **Impact:** Eliminates per-request metadata-lock contention, latency, and concurrency issues. Enables horizontal scaling. A `schema.sql` file is now the authoritative schema source.

---

## High Fixes

### BUG-H01 — Wrong audit entity ID on tuition upsert
- **File:** `admin/tuition.php` (line 73)
- **Change:** Replaced `$pdo->lastInsertId()` (which returns 0 on `ON DUPLICATE KEY UPDATE`) with an explicit `SELECT id FROM tuition_payments WHERE child_id = ? AND month_year = ?` query to fetch the real record ID after the upsert.
- **Impact:** Audit trail now correctly references the affected tuition payment record.

### BUG-H02 — Duplicate attendance form controls causing data corruption
- **File:** `admin/attendance.php`
- **Change:** The page rendered two complete sets of attendance inputs (desktop table + mobile cards) inside the same `<form>`, both with identical `name` attributes. If JavaScript failed, both sets submitted conflicting values. Now the mobile card controls are **disabled by default** via the `data-attendance-mobile` marker and an inline script that disables them on load; JS enables the appropriate set based on viewport. Without JS, only the desktop table submits.
- **Impact:** Attendance data is no longer corrupted when JS is unavailable.

### BUG-H05 — Missing index on messages(parent_id, is_read)
- **File:** `schema.sql` (new), `setup.php` (new)
- **Change:** Added composite index `idx_messages_parent_read (parent_id, is_read)` to the `messages` table. The unread-count query runs on every parent portal page load. The migration is applied by `setup.php` (idempotent: checks `information_schema` before adding).
- **Impact:** Parent portal message queries remain fast as the messages table grows.

### BUG-H08 — Brute-force check ordered after DDL initialization
- **File:** `login.php`
- **Change:** Moved `checkBruteForce('parent_login')` before `getDb()` so a locked-out user does not trigger any database connection work. (DDL is now a no-op, but the ordering fix remains correct.)
- **Impact:** Locked-out users no longer cause unnecessary database load.

### BUG-H09 — Hardcoded `$adminId` fallback to 1
- **File:** `admin/messages.php` (line 17)
- **Change:** Replaced `$adminId = (int) ($_SESSION['admin_id'] ?? 1)` with an explicit session check that redirects to `admin/login.php` if `admin_id` is absent.
- **Impact:** Eliminates the risk of messages being misattributed to admin ID 1 when the session is invalid.

---

## New Files

| File | Purpose |
|---|---|
| `schema.sql` | Authoritative database schema (all tables + indexes + seeds) |
| `setup.php` | One-time installer: runs schema.sql, creates default admin, secures upload dirs |

---

## Breaking Changes

- **Database tables must exist before the app runs.** Previously, tables were auto-created on first request. Now you **must** run `php setup.php` (or import `schema.sql`) once after deploying. Existing databases are unaffected because all statements use `CREATE TABLE IF NOT EXISTS`.
- **The `initialize*Tables()` functions still exist** as no-ops for backward compatibility, so existing code that calls them will not fatal — but they no longer create tables.

---

## Migration Steps for Existing Deployments

1. Back up the database.
2. Upload the new files.
3. Run `php setup.php` (or visit `https://yourdomain/setup.php` in a browser). This will:
   - Create any missing tables (`IF NOT EXISTS`).
   - Add the `idx_messages_parent_read` index (BUG-H05).
   - Seed default settings if missing.
   - Create the default admin if the `admins` table is empty.
4. **Delete `setup.php`** from the server.
5. Test: log in as admin, teacher, and parent.