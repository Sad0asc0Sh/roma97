# Rooma — Production Deployment & Operations Guide

This document covers deploying the **Rooma Daycare Management System** to a
production environment and operating it safely afterwards. For a first-time,
step-by-step install on shared hosting, see **`INSTALLATION.md`** — this guide
focuses on production hardening, server configuration, backups, and updates.

---

## 1. Overview

- **Stack:** PHP 8.1+ · MySQL 5.7+ / MariaDB 10.3+ · Apache (or Nginx + PHP-FPM)
- **Schema:** Created and migrated **automatically** on first request
  (idempotent `CREATE TABLE IF NOT EXISTS` + guarded `ALTER` statements). No
  manual SQL import is required.
- **Config:** Two-file pattern — committed `config.php` (safe defaults) +
  git-ignored `config.local.php` (environment credentials/overrides).

---

## 2. Prerequisites

| Requirement | Minimum |
| :--- | :--- |
| PHP | 8.1+ (8.2/8.3/8.4 supported) |
| PHP extensions | `pdo`, `pdo_mysql`, `mbstring`, `gd`, `json`, `openssl` |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache 2.4+ with `mod_rewrite`, or Nginx + PHP-FPM |
| TLS | Valid SSL certificate (Let's Encrypt or commercial) |

Verify PHP and extensions:

```bash
php -v
php -m | grep -iE 'pdo_mysql|mbstring|gd|json|openssl'
```

---

## 3. Deploy the Code

**Option A — Git (recommended)**

```bash
cd /var/www
git clone <your-repo-url> rooma
cd rooma
git checkout <release-tag-or-main>
```

**Option B — Archive**

Upload and extract the project archive into your web root (e.g.
`/var/www/rooma` or `public_html/`). Ensure hidden files such as `.htaccess`
are included.

> **Never** commit or upload `config.local.php`. It is git-ignored by design.

---

## 4. Configure the Environment

```bash
cp config.local.php.example config.local.php
```

Edit `config.local.php`:

```php
<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'rooma_db');
define('DB_USER', 'rooma_user');
define('DB_PASS', 'a-strong-password');

define('SITE_URL', 'https://your-domain.com'); // no trailing slash

define('DEVELOPMENT_MODE', false);  // MUST be false in production
define('FORCE_HTTPS', true);        // requires a valid SSL certificate
```

Anything you omit falls back to the safe defaults in `config.php`
(`DEVELOPMENT_MODE` defaults to `false`).

---

## 5. Database

Create the database and a least-privilege user:

```sql
CREATE DATABASE rooma_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rooma_user'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON rooma_db.* TO 'rooma_user'@'localhost';
FLUSH PRIVILEGES;
```

> The app needs `CREATE`/`ALTER`/`INDEX` on first run (and after updates) so it
> can build and migrate its own tables. You may revoke `CREATE`/`ALTER` after a
> successful first run if your security policy requires it — re-grant before
> deploying an update that changes the schema.

The tables (including `audit_log`, per-child `daily_reports`, etc.) are created
automatically the first time any page is loaded.

---

## 6. Web Server Configuration

### Apache

The bundled `.htaccess` already:
- disables directory indexing,
- denies direct access to `config.php`, `config.local.php`, `.ht*`, and `*.log`/`*.txt`.

Recommended virtual host:

```apache
<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/rooma

    <Directory /var/www/rooma>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/your-domain.com/privkey.pem
</VirtualHost>

# Redirect plain HTTP to HTTPS
<VirtualHost *:80>
    ServerName your-domain.com
    Redirect permanent / https://your-domain.com/
</VirtualHost>
```

Enable required modules:

```bash
sudo a2enmod rewrite ssl headers
sudo systemctl reload apache2
```

### Nginx + PHP-FPM (alternative)

`.htaccess` is ignored by Nginx, so replicate the protections in the server block:

```nginx
server {
    listen 443 ssl;
    server_name your-domain.com;
    root /var/www/rooma;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    # Block sensitive files
    location ~ ^/config(\.local)?\.php { deny all; return 404; }
    location ~ /\.ht                   { deny all; return 404; }
    location ~* \.(log|txt)$           { deny all; return 404; }
    location ^~ /includes/             { deny all; return 404; }
    location ^~ /logs/                 { deny all; return 404; }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 7. File Permissions

```bash
# Replace www-data with your web server user
sudo chown -R www-data:www-data /var/www/rooma
sudo find /var/www/rooma -type d -exec chmod 755 {} \;
sudo find /var/www/rooma -type f -exec chmod 644 {} \;

# Writable runtime directories
sudo chmod -R 775 /var/www/rooma/logs
sudo chmod -R 775 /var/www/rooma/assets/uploads
```

`config.local.php` should be readable by the web server but not world-readable
(`chmod 640` is a good choice).

---

## 8. First Run & Admin Bootstrap

1. Visit `https://your-domain.com/` — tables initialize automatically.
2. Go to `https://your-domain.com/admin/login.php`.
3. Log in with the seeded credentials:
   - **Username:** `admin`
   - **Password:** `admin123`
4. You are redirected to **Settings**, where a warning banner and a
   "تغییر رمز عبور" (Change Password) form let you set a new password
   immediately — do this before anything else.
5. Set site name / logo / contact phone, then create classrooms, teachers,
   and approve parents/children.

---

## 9. Production Security Checklist

- [ ] `DEVELOPMENT_MODE` is `false` in `config.local.php`.
- [ ] `FORCE_HTTPS` is `true` and a valid SSL certificate is installed.
- [ ] Default admin password (`admin123`) has been changed via **Settings →
      تغییر رمز عبور**.
- [ ] Database user uses a strong, unique password with least privilege.
- [ ] `config.local.php` is present, git-ignored, and `chmod 640`.
- [ ] Login is protected against brute force automatically (the
      `login_throttle` table tracks failed attempts per IP **and** per
      account/email — created on first use, no setup needed). Five failed
      attempts triggers a 15-minute lockout.
- [ ] **Remove `preflight.php`** from the web root after verifying the
      environment — it is a setup diagnostic and should not be public:
      ```bash
      rm /var/www/rooma/preflight.php
      ```
- [ ] Confirm `/includes/`, `/logs/`, and `config*.php` are not web-accessible
      (try opening them in a browser — you should get 403/404).
- [ ] Review the **Activity Log** (`admin/audit.php`) is recording events.

---

## 10. Audit Log

All sensitive mutations are recorded in the `audit_log` table and viewable at
`admin/audit.php` (filterable by action, paginated). Logged actions include:

- Authentication: `auth.login`
- Content: `news.*`, `page.*`, `slide.*`, `event.*`
- Operations: `classroom.*`, `teacher.*`, `child.*`, `daily_report.save`
- Finance: `salary.payment`, `tuition.payment`
- Messaging: `message.send`

Each entry stores the actor (type, id, label), action, affected entity, an
optional JSON `details` payload, the client IP, and a timestamp. Audit writes
are best-effort and never block the underlying action.

**Retention:** the table grows over time. To prune old entries, schedule:

```sql
DELETE FROM audit_log WHERE created_at < (NOW() - INTERVAL 12 MONTH);
```

---

## 11. Backups

**Database (daily, via cron):**

```bash
0 2 * * * mysqldump --single-transaction -u rooma_user -p'PASS' rooma_db \
  | gzip > /var/backups/rooma/db-$(date +\%F).sql.gz
```

**Uploaded files** (parent-uploaded photos/documents/certificates):

```bash
0 3 * * 0 tar czf /var/backups/rooma/uploads-$(date +\%F).tar.gz \
  -C /var/www/rooma assets/uploads
```

Test restores periodically. Keep at least 7 daily DB backups and 4 weekly
upload backups off-site.

---

## 12. Updating to a New Release

```bash
cd /var/www/rooma
# 1. Back up first (see section 11)
# 2. Pull the new code
git fetch --all
git checkout <new-release-tag>
# (config.local.php is untouched — it is git-ignored)
# 3. Load any page once so idempotent migrations run
curl -s -o /dev/null https://your-domain.com/
# 4. Smoke-test admin + parent + teacher logins
```

Schema migrations are additive and idempotent, so re-running them is safe.
No manual SQL is normally required.

---

## 13. Logs & Monitoring

- Application errors: `logs/error.log` (path from `ERROR_LOG_PATH`).
- With `DEVELOPMENT_MODE=false`, users see a generic error page while details
  go to the log only.
- Tail in real time:
  ```bash
  tail -f /var/www/rooma/logs/error.log
  ```
- Rotate logs with `logrotate` to prevent unbounded growth.

---

## 14. Troubleshooting

| Symptom | Likely cause | Fix |
| :--- | :--- | :--- |
| Blank/500 page | PHP fatal error | Check `logs/error.log`; confirm PHP 8.1+ and required extensions. |
| "Access denied" on DB | Wrong credentials / privileges | Verify `config.local.php`; ensure the DB user has the grants in §5. |
| Tables not created | Missing `CREATE`/`ALTER` grant | Grant them, reload a page, then optionally revoke. |
| 404 on menu links | `mod_rewrite` off (Apache) | `a2enmod rewrite` and `AllowOverride All`. |
| Mixed-content / redirect loop | `SITE_URL`/`FORCE_HTTPS` mismatch | Ensure `SITE_URL` uses `https://` and SSL terminates correctly. |
| Uploads fail | Permissions or size | `chmod 775 assets/uploads`; check `MAX_UPLOAD_SIZE`. |

---

## 15. Rollback

```bash
cd /var/www/rooma
git checkout <previous-release-tag>
# Restore the matching DB backup if the release included schema changes:
gunzip < /var/backups/rooma/db-YYYY-MM-DD.sql.gz | mysql -u rooma_user -p rooma_db
```

---
*Rooma Technical Team — Deployment Guide, 2026*
