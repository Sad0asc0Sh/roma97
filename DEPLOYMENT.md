# Roma — Production Deployment & Operations Guide

This document covers deploying the **Roma Daycare Management System** to a
production environment and operating it safely afterwards. For a first-time,
step-by-step install on shared hosting, see **`INSTALLATION.md`** — this guide
focuses on production hardening, server configuration, backups, and updates.

---

## 1. Overview

- **Stack:** PHP 8.1+ · MySQL 5.7+ / MariaDB 10.3+ · Apache (or Nginx + PHP-FPM)
- **Schema:** Created and initialized via `setup.php` on initial deployment. Additive migrations run automatically.
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
git clone <your-repo-url> roma
cd roma
git checkout <release-tag-or-main>
```

**Option B — Archive**

Upload and extract the project archive into your web root (e.g.
`/var/www/roma` or `public_html/`). Ensure hidden files such as `.htaccess`
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
define('DB_NAME', 'roma_db');
define('DB_USER', 'roma_user');
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
CREATE DATABASE roma_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'roma_user'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON roma_db.* TO 'roma_user'@'localhost';
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
    DocumentRoot /var/www/roma

    <Directory /var/www/roma>
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
    root /var/www/roma;
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
sudo chown -R www-data:www-data /var/www/roma
sudo find /var/www/roma -type d -exec chmod 755 {} \;
sudo find /var/www/roma -type f -exec chmod 644 {} \;

# Writable runtime directories
sudo chmod -R 775 /var/www/roma/logs
sudo chmod -R 775 /var/www/roma/assets/uploads
```

`config.local.php` should be readable by the web server but not world-readable
(`chmod 640` is a good choice).

---

## 8. First Run & Admin Bootstrap

1. Run the hardened web installer or CLI setup:
   - **Web:** Visit `https://your-domain.com/setup.php`
   - **CLI:** `php setup.php`
2. Complete the setup form to initialize all tables and configure the primary Owner admin account.
3. Upon completion, `setup.php` automatically writes `install.lock` and blocks any future execution.
4. Log into the admin portal at `https://your-domain.com/admin/login.php`.
5. Navigate to **تنظیمات (Settings) → پنل پیامک ملی‌پیامک** to enter your API key and pattern IDs (OTP, Daily Report, Attendance, Tuition Reminder, Announcements).
6. Configure site name, logo, contact information, classrooms, and teachers.

---

## 9. Production Security Checklist

- [ ] `DEVELOPMENT_MODE` is `false` in `config.local.php`.
- [ ] `FORCE_HTTPS` is `true` and a valid SSL certificate is installed.
- [ ] Primary admin password is strong and set during `setup.php` execution.
- [ ] Database user uses a strong, unique password with least privilege.
- [ ] `config.local.php` is present, git-ignored, and `chmod 640`.
- [ ] Login is protected against brute force automatically (the
      `login_throttle` table tracks failed attempts per IP **and** per
      account/email — created on first use, no setup needed). Five failed
      attempts triggers a 15-minute lockout.
- [ ] `install.lock` exists in the web root after installation.
- [ ] Confirm `/includes/`, `/templates/`, `/dist/`, `/bin/`, `/logs/`, and `config*.php` are not web-accessible
      (opening them in a browser returns 403 Forbidden).
- [ ] Review the **Activity Log** (`admin/audit.php`) and **SMS Log** (`admin/settings.php#sms-section`) are recording events.

---

## 10. SMS Gateway & Automation Setup (MeliPayamak Shared Pattern)

ROMA integrates with **MeliPayamak Shared Pattern Service** to deliver instant OTP verification codes and kindergarten notifications without SMS black-list blocking.

### Pattern Variables Configuration (Child-Friendly & Sweet Tone):
- **1. OTP Code (کد تایید ورود و ثبت‌نام):** 1 variable: `{0}=کد تایید ۵ رقمی`
  - *نمونه متن:* `کد ورود شما به خانه پرمهر مهدکودک روما: {0} با آرزوی روزی شاد برای دلبندتان`
- **2. Welcome (خوش‌آمدگویی و فعال‌سازی والد):** 1 variable: `{0}=نام والد`
  - *نمونه متن:* `{0} عزیز، با عشق و افتخار به جمع پرمهر مهدکودک روما خوش آمدید.`
- **3. Brief Daily Report (گزارش روزانه خلاصه / ارجاع به پنل):** 2 variables: `{0}=نام کودک`, `{1}=نام مهدکودک`
  - *نمونه متن:* `مامان و بابای مهربون {0}، گزارش روز پر از بازی دلبندتان در پنل {1} ثبت شد.`
- **4. Detailed Daily Report (گزارش تفصیلی روزانه):** 3 variables: `{0}=نام کودک`, `{1}=تاریخ`, `{2}=احوال/روحی`
  - *نمونه متن:* `گزارش روز {1} فرشته کوچولومون {0}: {2}. مهدکودک روما`
- **5. Attendance (اعلام حضور و غیاب):** 3 variables: `{0}=نام کودک`, `{1}=تاریخ`, `{2}=وضعیت حضور`
  - *نمونه متن:* `دلبند قشنگمون {0} در تاریخ {1} {2}. مهدکودک روما`
- **6. Tuition Reminder (یادآوری دوستانه و مهربانانه شهریه):** 4 variables: `{0}=نام والد`, `{1}=نام کودک`, `{2}=ماه`, `{3}=مبلغ بدهی`
  - *نمونه متن:* `{0} مهربان، یادآوری دوستانه شهریه ماه {2} فرشته قشنگمون {1}: {3} تومان. مهد روما`
- **7. Announcements (اطلاعیه‌ها و پیام‌های مهد):** 2 variables: `{0}=نام والد`, `{1}=عنوان پیام`
  - *نمونه متن:* `{0} مهربان، اطلاعیه جدید مهد با عنوان «{1}» در پنل شما قرار گرفت.`

---

## 11. Automatic Overdue Tuition Reminders (Cron Job Setup)

ROMA includes an automated background script to send polite in-app message reminders and SMS pattern alerts to parents who have overdue tuition for the current Shamsi month (with built-in anti-spam logic restricting reminders to once every 7 days).

### cPanel Cron Job Configuration:
1. Log into your **cPanel** dashboard.
2. Navigate to **Advanced → Cron Jobs**.
3. Under **Add New Cron Job**, set the schedule (recommended: Daily at 9:00 AM — `0 9 * * *`).
4. Set the command to run PHP CLI against the script:
   ```bash
   php /home/username/public_html/bin/send-overdue-reminders.php >/dev/null 2>&1
   ```
   *(Replace `/home/username/public_html` with the absolute path to your site installation).*

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
0 2 * * * mysqldump --single-transaction -u roma_user -p'PASS' roma_db \
  | gzip > /var/backups/roma/db-$(date +\%F).sql.gz
```

**Uploaded files** (parent-uploaded photos/documents/certificates):

```bash
0 3 * * 0 tar czf /var/backups/roma/uploads-$(date +\%F).tar.gz \
  -C /var/www/roma assets/uploads
```

Test restores periodically. Keep at least 7 daily DB backups and 4 weekly
upload backups off-site.

---

## 12. Updating to a New Release

```bash
cd /var/www/roma
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
  tail -f /var/www/roma/logs/error.log
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
cd /var/www/roma
git checkout <previous-release-tag>
# Restore the matching DB backup if the release included schema changes:
gunzip < /var/backups/roma/db-YYYY-MM-DD.sql.gz | mysql -u roma_user -p roma_db
```

---
*Roma Technical Team — Deployment Guide, 2026*
