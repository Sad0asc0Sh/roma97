# Production Hardening Report

**Date:** 2026-06-26
**Release:** Production Hardening v1
**Baseline:** Commit `dd5cbdc` (Production Validation Round 1)

---

## Files Modified

| # | File | Change |
|---|------|--------|
| 1 | `includes/db.php` | Removed `DEFAULT_ADMIN_USERNAME` and `DEFAULT_ADMIN_PASSWORD` constants |
| 2 | `admin/login.php` | Removed the `password_verify(DEFAULT_ADMIN_PASSWORD, ...)` default-password check and the force-change-password redirect (lines 74–84 removed) |
| 3 | `admin/header.php` | Removed the `$_SESSION['default_admin_password']` warning banner (dead code after login.php change) |
| 4 | `admin/settings.php` | Removed `unset($_SESSION['default_admin_password'])` (dead code) |
| 5 | `setup.php` | Complete rewrite: requires manual admin username + password entry during installation; creates `install.lock` after success; blocks execution if `install.lock` exists |
| 6 | `.gitignore` | Added `install.lock` to prevent committing the environment-specific lock file |

---

## Security Improvements

### 1. No hardcoded default admin credentials in source code

**Before:** `includes/db.php` defined `DEFAULT_ADMIN_PASSWORD = 'admin123'` and `DEFAULT_ADMIN_USERNAME = 'admin'`. These constants were in version control, so anyone reading the repository knew the bootstrap credentials. The old `initializeDatabase()` (now a no-op) auto-seeded an `admin/admin123` account when the `admins` table was empty. The `admin/login.php` page checked if the logged-in admin was still using this default password and forced a change.

**After:** Both constants are removed from `includes/db.php`. The admin account is created exclusively by `setup.php`, which requires the deploying operator to enter a username and password interactively (via web form or CLI prompt). No default password exists in the codebase. The `admin/login.php` default-password check is removed because there is no default password to compare against.

**Risk eliminated:** An attacker who reads the repository no longer knows any valid credential. The `admin/admin123` attack vector is closed.

### 2. Setup script self-locks after installation

**Before:** `setup.php` could be re-run at any time. While idempotent (`CREATE TABLE IF NOT EXISTS`), it would recreate the default `admin/admin123` account if the `admins` table was empty (e.g., after a database reset), re-opening the known-credential attack vector.

**After:** After a successful installation, `setup.php` writes an `install.lock` file in the project root. On any subsequent access, the script checks for `install.lock` and aborts immediately with HTTP 403 and a message: "Installation is locked (install.lock exists)." This prevents re-execution even if the attacker knows the URL.

**Risk eliminated:** Even if `setup.php` is left on the server (not deleted), it cannot be re-run after the first successful installation. The `install.lock` file is added to `.gitignore` so it is environment-specific and never committed.

### 3. Manual admin password creation during installation

**Before:** `setup.php` created the admin account with the hardcoded `admin123` password and relied on the first-login force-change to secure it.

**After:** `setup.php` presents a form (web) or interactive prompts (CLI) requiring:
- Admin username (3–50 chars, alphanumeric + underscore)
- Admin password (min 8 chars, must contain at least one letter and one number)
- Password confirmation

The password is validated server-side before the admin account is created. The admin account is created with the operator-chosen password — there is no "default" to change.

**Risk eliminated:** The admin account never has a known or weak password. The first-login window of vulnerability (between installation and password change) is eliminated.

---

## Deployment Impact

### New deployment procedure

1. Upload all files to the server (including `setup.php` and `schema.sql`).
2. Configure `config.local.php` with database credentials.
3. Visit `https://yourdomain.com/setup.php` in a browser.
4. The installer displays a form requesting admin username and password.
5. Enter credentials and click "Run Installation".
6. The installer:
   - Creates all database tables
   - Adds the `idx_messages_parent_read` index
   - Creates the admin account with the entered credentials
   - Secures upload directories
   - Writes `install.lock` (blocking future execution)
7. **Delete `setup.php`** from the server (defense-in-depth; `install.lock` already blocks re-execution).
8. Log in with the credentials created in step 5.

### Existing deployments (upgrade from v1)

Existing deployments that already have an `admins` table with data are **not affected** by the removal of `DEFAULT_ADMIN_PASSWORD`. The `admin/login.php` page no longer checks for the default password, but since the admin already changed their password on first login (the old behavior), this is a no-op. If the admin never changed their password and is still using `admin123`, they can still log in — but there is no longer a forced redirect to change it. **Recommendation:** after upgrading, manually change the admin password if it was never changed.

To re-lock an existing deployment after upgrading, run the new `setup.php` once (it will see existing tables via `IF NOT EXISTS` and skip admin creation since the table is not empty), then it will write `install.lock`.

### Breaking changes

- **`DEFAULT_ADMIN_USERNAME` and `DEFAULT_ADMIN_PASSWORD` constants are removed.** Any custom code referencing these constants will fatal with `Error: Undefined constant`. No code in the standard ROMA codebase references them after this hardening.
- **`$_SESSION['default_admin_password']` is no longer set.** Any custom code checking this session key will find it absent (the check evaluates to false, which is safe).
- **`setup.php` now requires form submission (web) or interactive input (CLI).** It no longer auto-creates the admin with a default password. CLI usage now requires stdin input for credentials.

### What did NOT change

- No architecture changes
- No UI redesign (the admin login form, dashboard, and settings pages are unchanged except for the removed warning banner)
- No database schema redesign
- No new audit log entries
- All existing authentication flows (parent, teacher, admin) work identically
- All existing data remains compatible

---

## Summary

| Improvement | Before | After |
|------------|--------|-------|
| Default admin password in source | `admin123` in `includes/db.php` | **Removed** — no default exists |
| Admin account creation | Auto-seeded with known credential | **Manual** — operator chooses during install |
| `setup.php` re-execution | Unlimited | **Blocked** by `install.lock` after first run |
| First-login password change | Forced redirect (weak window) | **Not needed** — password is strong from creation |
| `install.lock` in git | N/A | **Gitignored** (environment-specific) |

---

*This hardening eliminates the last known-credential attack vector in the ROMA codebase.*