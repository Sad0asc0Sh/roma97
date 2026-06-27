# LOCAL_ROUTING_FIX_REPORT.md

## Summary

**Issue:** All links in the ROMA application redirected to `https://example.com` instead of local application pages.

**Root Cause:** `config.local.php` defined `SITE_URL` as `'https://example.com'`, which overrode the correct auto-detection logic in `config.php`. Since `config.local.php` is loaded **before** the defaults in `config.php` (line 15-18), and the auto-detection only runs when `SITE_URL` is **not** already defined (`if (!defined('SITE_URL'))`), every call to `url()` generated links starting with `https://example.com`.

**Severity:** Critical — the entire application was unreachable via navigation.

---

## Root Cause Analysis

### How SITE_URL resolution works:

1. `config.php` loads `config.local.php` first (if it exists)
2. `config.local.php` defines `SITE_URL = 'https://example.com'`
3. `config.php` checks `if (!defined('SITE_URL'))` — this is **false**, so auto-detection is **skipped**
4. The `url()` helper in `includes/functions.php` uses `SITE_URL` to build all links:
   ```php
   function url(string $path = ''): string {
       return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
   }
   ```
5. Every link becomes `https://example.com/admin/index.php`, `https://example.com/login.php`, etc.

### Why auto-detection was already correct:

The auto-detection logic in `config.php` (lines 33-93) is robust and handles:
- Any localhost subfolder (e.g., `/roma`, `/roma97`, `/roma-final/roma`)
- Apache Alias directives
- Custom document roots / vhosts
- Fallback to `http://localhost/<project-folder-name>` when detection fails

The auto-detection did **not** need any code changes — it was simply being bypassed.

---

## Broken URLs Found

| URL Pattern | Where Generated | Purpose |
|---|---|---|
| `https://example.com/index.php` | `templates/header.php` | Logo link |
| `https://example.com/page.php?slug=about` | `templates/header.php` | About page nav link |
| `https://example.com/news.php` | `templates/header.php` | News page nav link |
| `https://example.com/page.php?slug=classes` | `templates/header.php` | Classes page nav link |
| `https://example.com/page.php?slug=contact` | `templates/header.php` | Contact page nav link |
| `https://example.com/login.php` | `templates/header.php` | Login button |
| `https://example.com/parent/index.php` | `templates/header.php` | Parent panel button |
| `https://example.com/admin/logout.php` | `templates/header.php` | Logout form action |
| `https://example.com/admin/index.php` | `admin/header.php` | All admin navigation links |
| `https://example.com/teacher/index.php` | `teacher/header.php` | Teacher navigation links |
| `https://example.com/parent/index.php` | `parent/header.php` | Parent navigation links |
| `https://example.com/register.php` | `login.php` | Register link |
| `https://example.com/assets/css/style.css` | All headers | Stylesheet link |
| `https://example.com/assets/js/script.js` | All footers | Script link |

**Total affected URLs:** Every single `url()` call site across all templates (40+ link/form targets).

---

## Corrected URLs

After the fix, `SITE_URL` is auto-detected from the HTTP request, so all URLs will resolve correctly:

| Before | After (auto-detected) |
|---|---|
| `https://example.com/index.php` | `http://localhost/roma/index.php` |
| `https://example.com/admin/index.php` | `http://localhost/roma/admin/index.php` |
| `https://example.com/login.php` | `http://localhost/roma/login.php` |
| `https://example.com/assets/css/style.css` | `http://localhost/roma/assets/css/style.css` |

*(The actual auto-detected value depends on the subfolder name, e.g., `/roma` or `/roma97`)*

---

## File Modified

### `config.local.php`

**Change:** Removed the hardcoded `define('SITE_URL', 'https://example.com');` line so the auto-detection in `config.php` runs instead.

**Also corrected:**
- `DB_USER`: Changed from `'your_db_user'` to `'root'` (XAMPP default)
- `DB_PASS`: Changed from `'your_db_password'` to `''` (XAMPP default empty password)
- `DEVELOPMENT_MODE`: Set to `true` for local development
- `FORCE_HTTPS`: Set to `false` (no SSL on localhost)

### Before:
```php
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('SITE_URL', 'https://example.com');
define('DEVELOPMENT_MODE', false);
define('FORCE_HTTPS', true);
```

### After:
```php
define('DB_USER', 'root');
define('DB_PASS', '');
// SITE_URL removed — auto-detected by config.php
define('DEVELOPMENT_MODE', true);
define('FORCE_HTTPS', false);
```

---

## Files NOT Modified (verified clean)

All template and page files were audited and confirmed to use `url()` helper correctly:

| File | Status |
|---|---|
| `templates/header.php` | ✅ Uses `url()` for all links |
| `templates/footer.php` | ✅ Uses `url()` for all links |
| `admin/header.php` | ✅ Uses `url()` for all links |
| `admin/footer.php` | ✅ Uses `url()` for script src |
| `parent/header.php` | ✅ Uses `url()` for all links |
| `parent/footer.php` | ✅ Uses `url()` for script src |
| `teacher/header.php` | ✅ Uses `url()` for all links |
| `teacher/footer.php` | ✅ Uses `url()` for script src |
| `login.php` | ✅ Uses `url()` for form action and links |
| `register.php` | ✅ Uses `url()` |
| All admin/*.php | ✅ Use `url()` |
| All parent/*.php | ✅ Use `url()` |
| All teacher/*.php | ✅ Use `url()` |
| `includes/functions.php` | ✅ `url()` helper is correct |
| `config.php` | ✅ Auto-detection logic is robust |
| `.htaccess` | ✅ No URL issues |
| `assets/js/*` | ✅ No hardcoded URLs |

**Note:** External URLs to `cdn.jsdelivr.net` and `fonts.googleapis.com` in templates are legitimate CDN references for the Vazirmatn font and should NOT be changed.

---

## Verification

After applying the fix, verify by:
1. Opening `http://localhost/roma/` in browser
2. Clicking any navigation link — should stay within the app
3. Checking that CSS loads correctly (styles should appear)
4. Logging in as parent/admin/teacher — redirects should work
5. Checking browser DevTools Network tab for any 404s or external redirects

---

*Report generated: 2026-06-27*