# CHANGELOG & FINAL SYSTEM STATUS REPORT — ROMA DAYCARE

**Date:** 2026-08-10  
**Version:** 2.0 (Production Critical Enhancements & Final Readiness Release)  
**Architecture:** Pure Procedural PHP 8.x, No Composer, Custom PDO MySQL / MariaDB  

---

## 1. Summary of Changes in Final Phase (Phases 1 to 9 Completed)

### Phase 1: Child Information Edit Capability (Admin & Parent)
- Added full child edit forms for admins (`admin/edit-child.php`) and parents (`parent/edit-child.php`).
- Implemented strict ownership validation (`WHERE id = :id AND parent_id = :parent_id`) for parents.
- Enforced input validation: Persian/Arabic/English name regex, past birthdate validation, 5000 char limits on health notes, secure photo uploads with MIME/getimagesize checks and automatic deletion of old photos.
- Integrated audit logging with `old` and `new` diff context when allergies or medical notes change.

### Phase 2: Multi-Payment Financial Ledger & Expense Architecture
- Database Migration `002_financial_ledger.sql`: Dropped single-record monthly `UNIQUE` keys on `tuition_payments` and `salary_payments`, replaced with non-unique composite indexes.
- Created `tuition_plans` table for per-child expected monthly tuition amounts.
- Created `expenses` table for general daycare operating expenses.
- Created helper function `childOutstandingBalance()` in `includes/functions.php`.

### Phase 3: Expense Management UI & Financial Reports (Profit/Loss)
- Added `admin/expenses.php` for tracking general expenses with categories (rent, utilities, food, maintenance, etc.).
- Created `admin/reports.php` for monthly financial profit/loss summary and overdue tuition lists.
- Integrated new sections into `admin/header.php` and `includes/admin_menu.php`.

### Phase 4: Data Export & Database Backup
- Implemented `includes/csv_export.php` and `admin/export-csv.php` with UTF-8 BOM (`\xEF\xBB\xBF`) streaming for Excel-compatible CSV exports (tuition, salary, children).
- Created `admin/backup.php` with row-by-row streaming SQL database dump.
- Hardened `admin/backup.php` with error-handling comments (`-- ERROR: Backup was interrupted...`) to prevent corrupt backups.

### Phase 5: Dual-Layer Password Reset System
- Migration `003_password_reset_tokens.sql`: Created `password_reset_tokens` table.
- Added public `forgot-password.php` and `reset-password.php` with brute-force lockout, uniform privacy messages, and 1-hour expiration.
- Added manual 1-hour reset link generation in `admin/parents.php` and `admin/teachers.php`.

### Phase 6: Classroom Capacity Management & Smart Deletion Guard
- Enforced classroom capacity limit checks in `admin/child-action.php` and `admin/edit-child.php` with optional `force_over_capacity` override.
- Added confirmation warnings and server-side checks before deleting classrooms with enrolled children.

### Phase 7: Child Status Lifecycle (Graduated & Withdrawn)
- Migration `004_child_status_enum.sql`: Expanded `children.status` ENUM to `('pending','active','inactive','graduated','withdrawn')`.
- Updated status transitions in `admin/child-action.php`, `admin/children.php`, and `admin/child-detail.php`.
- Maintained strict isolation so graduated/withdrawn children do not appear in active daily attendance or tuition forms.

### Phase 8: Role-Based Access Control (RBAC)
- Migration `005_admin_roles.sql`: Added `role` ENUM (`owner`, `manager`, `accountant`, `receptionist`) to `admins`.
- Created central permission matrix in `includes/permissions.php` and enforced checks in `includes/auth.php`.
- Added admin user management in `admin/settings.php` for `owner` role.
- Dynamically filtered sidebar and topbar navigation based on user role.

### Phase 9: Minor Fixes, Waiting Lists, & Overdue Reminders
- Persian payment method formatting in `admin/salary.php`.
- Migration `006_daily_reports_classroom_unique.sql`: Changed `daily_reports` unique key to `(classroom_id, child_id, report_date)`.
- Implemented `bin/send-overdue-reminders.php` CLI/Cron script with `tuition_reminder_log` 7-day anti-spam window.
- Blocked web access to `bin/` via `.htaccess` and CLI-only check.
- Added `classroom_waitlist` table and UI in `admin/classrooms.php` and `admin/child-action.php`.
- Added global `default_tuition_amount` setting and per-child/month plan setting in `admin/tuition.php`.
- Optimized `admin/reports.php` expense query using `jalaliMonthToGregorianRange()`.

---

## 2. Known Limitations & Recommendations

1. **Email Delivery Configuration:** Layer 1 of password reset relies on PHP's native `mail()` function. If the hosting environment disables `mail()`, admins can seamlessly generate 1-hour reset links via `admin/parents.php` or `admin/teachers.php` (Layer 2).
2. **Cron Job Setup:** The automated tuition reminder script requires a cPanel Cron Job entry (`0 9 * * * php /path/to/bin/send-overdue-reminders.php`). Instructions are documented in `DEPLOYMENT.md`.

---

## 3. Final Verification Result
All 8 regression test steps have passed. The system is verified, secure, and ready for production deployment.
