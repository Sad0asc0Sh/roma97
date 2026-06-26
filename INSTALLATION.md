# Rooma Installation Guide (Technical Deployment)

This guide provides step-by-step instructions for deploying the **Rooma Daycare Management System** to a shared hosting environment (cPanel, DirectAdmin, etc.) or a VPS.

---

## 1. System Requirements

- **PHP:** 8.1 or higher
- **Extensions:** PDO, pdo_mysql, mbstring, gd, json, openssl
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **Web Server:** Apache with `mod_rewrite` enabled
- **Secure Connection:** SSL Certificate (recommended for production)

---

## 2. File Deployment

1. **Prepare Package:** Zip your project folder contents (ensure `.htaccess` and all subdirectories are included).
2. **Upload:** Use the cPanel **File Manager** or FTP to upload the zip file to your `public_html` directory (or a subfolder if preferred).
3. **Extract:** Extract the files directly into the destination folder.

---

## 3. Database Setup

1. **Create Database:** Use cPanel **MySQL Database Wizard** to create a new database (e.g., `rooma_db`).
2. **Create User:** Create a new database user and assign a strong password.
3. **Privileges:** Assign the user to the database with **ALL PRIVILEGES**.
4. **Note Credentials:** Save the DB Name, DB User, and DB Password for the next step.

---

## 4. Configuration

Configuration uses a two-file pattern so that environment-specific credentials are **never** committed to version control:

- `config.php` — committed. Holds safe defaults and loads `config.local.php` when present.
- `config.local.php` — **NOT** committed (git-ignored). Holds your environment's credentials and overrides.

**Steps:**

1. Copy the template:
   ```bash
   cp config.local.php.example config.local.php
   ```
2. Edit `config.local.php` and set your values:
   ```php
   <?php
   declare(strict_types=1);

   // Database
   define('DB_HOST', 'localhost'); // Usually 'localhost'
   define('DB_NAME', 'your_database_name');
   define('DB_USER', 'your_database_user');
   define('DB_PASS', 'your_database_password');

   // Public base URL (no trailing slash)
   define('SITE_URL', 'https://yourdomain.com');

   // Production: keep false. Local dev only: true to see detailed errors.
   define('DEVELOPMENT_MODE', false);

   // Force HTTPS for all traffic (requires a valid SSL certificate)
   define('FORCE_HTTPS', true);
   ```

> You only need to define the constants you want to override. Anything you omit
> falls back to the safe defaults in `config.php` (e.g. `DEVELOPMENT_MODE`
> defaults to `false`, so production stays safe even if you forget it).

### Configuration Definitions:
- `DB_HOST`: The hostname of your database server.
- `SITE_URL`: The full URL where the app is accessible (including `http://` or `https://`).
- `DEVELOPMENT_MODE`: Shows detailed errors when `true`. **Must be `false` in production.**
- `FORCE_HTTPS`: Redirects all `http` traffic to `https`.
- `ERROR_LOG_PATH`: Path to the error log file (default is `__DIR__ . '/logs/error.log'`).

> For production hardening, SSL, backups, and update procedures, see **`DEPLOYMENT.md`**.

---

## 5. Security & Permissions

### File Permissions
Ensure the following directories are writable by the web server (usually permission **0755** or **0775**):
- `assets/uploads/`
- `logs/`

### HTACCESS
Ensure the `.htaccess` file in the root is present. It handles routing and prevents direct access to protected directories like `includes/`.

---

## 6. First Run & Initialization

1. **Visit Site:** Open your browser and navigate to your `SITE_URL`.
2. **Automatic Setup:** The system is designed to self-initialize. Upon first visit, it will create all necessary MySQL tables automatically.
3. **Admin Login:** Navigate to `SITE_URL/admin/login.php`.
4. **Default Credentials:**
   - **Username:** `admin`
   - **Password:** `admin123`
5. **Immediate Action:** Log in and go to **Settings** to update the admin password.

---

## 7. Post-Installation Steps

1. **Branding:** In the Admin Panel, go to **Settings** to set your **Site Name** and upload your **Logo**.
2. **Structure:**
   - Create **Classrooms** via `admin/classrooms.php`.
   - Add **Teachers** and assign them to classrooms.
   - **Approve Parents** and **Children** as they register to grant them portal access.

---

## 8. Troubleshooting

| Issue | Potential Cause | Solution |
| :--- | :--- | :--- |
| **Blank Page** | PHP Error | Check `logs/error.log` for details. Ensure PHP 8.1+ is active. |
| **DB Connection Error** | Wrong Credentials | Verify `config.local.php` matches your cPanel DB settings. |
| **404 on Menu Links** | mod_rewrite | Ensure `.htaccess` is present and `mod_rewrite` is enabled on Apache. |
| **Uploads Failing** | Permissions | Ensure `assets/uploads/` 755 permission and `MAX_UPLOAD_SIZE` in `config.php`. |

---

## 9. Backup Recommendations

- **Database:** Schedule weekly backups via cPanel **Backup Wizard** or set up a Cron Job for `mysqldump`.
- **Files:** Backup the `assets/uploads/` directory regularly as it contains parent-uploaded documents and photos.

---
*Created by Rooma Technical Team - 2026*
