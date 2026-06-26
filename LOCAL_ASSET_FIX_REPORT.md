# ROMA — Local Asset Fix Report

**Date:** 2026-06-26  
**Environment:** XAMPP localhost (Windows 11, Apache + PHP 8.1+)  
**Scope:** Asset-path / SITE_URL / base-path resolution only. No UI, HTML, or CSS changes.

---

## Root Cause

The first-round fix (commit `e01135e`) used `$_SERVER['DOCUMENT_ROOT']` to auto-detect `SITE_URL`. This fails on XAMPP when the project lives **outside** the default `htdocs` directory (e.g. `D:\roma-final\roma` served via an Apache `Alias` or a custom `DocumentRoot`). In that scenario:

- `DOCUMENT_ROOT` = `C:/xampp/htdocs`
- `__DIR__` (config.php) = `D:/roma-final/roma`
- The project root does **not** start with the document root → the `str_starts_with()` check returns `false` → the base path is never appended → `SITE_URL` becomes just `http://localhost` (no subpath).
- `url('assets/css/style.css')` then generates `http://localhost/assets/css/style.css` → **404** → raw unstyled HTML.

### The robust fix

Replaced the `DOCUMENT_ROOT`-based detection with a `SCRIPT_NAME` + `SCRIPT_FILENAME` approach that derives the base URL path from the **URL** of the running script, not from the filesystem document root. This works correctly for:

| Deployment style | Example | Detected SITE_URL |
|---|---|---|
| XAMPP Alias | `Alias /roma "D:/roma-final/roma"` | `http://localhost/roma` |
| Inside htdocs subfolder | `C:/xampp/htdocs/roma97/` | `http://localhost/roma97` |
| Vhost root | `DocumentRoot D:/roma-final/roma` | `http://roma.local` |
| Production | `DocumentRoot /var/www/roma` | `https://example.com` |

---

## Broken URLs Found

These are the URLs that were being generated **before** this fix (with the broken `DOCUMENT_ROOT`-based detection), assuming the app is served at `http://localhost/roma`:

| # | Resource | Broken generated URL | Correct URL |
|---|---|---|---|
| 1 | Main CSS (`templates/header.php`) | `http://localhost/assets/css/style.css` | `http://localhost/roma/assets/css/style.css` |
| 2 | Main CSS (`admin/header.php`) | `http://localhost/assets/css/style.css` | `http://localhost/roma/assets/css/style.css` |
| 3 | Main CSS (`parent/header.php`) | `http://localhost/assets/css/style.css` | `http://localhost/roma/assets/css/style.css` |
| 4 | Main CSS (`teacher/header.php`) | `http://localhost/assets/css/style.css` | `http://localhost/roma/assets/css/style.css` |
| 5 | Main CSS (`teacher/login.php`) | `http://localhost/assets/css/style.css` | `http://localhost/roma/assets/css/style.css` |
| 6 | Main JS (`templates/footer.php`) | `http://localhost/assets/js/script.js` | `http://localhost/roma/assets/js/script.js` |
| 7 | Main JS (`admin/footer.php`) | `http://localhost/assets/js/script.js` | `http://localhost/roma/assets/js/script.js` |
| 8 | Main JS (`parent/footer.php`) | `http://localhost/assets/js/script.js` | `http://localhost/roma/assets/js/script.js` |
| 9 | Main JS (`teacher/footer.php`) | `http://localhost/assets/js/script.js` | `http://localhost/roma/assets/js/script.js` |
| 10 | Main JS (`teacher/login.php`) | `http://localhost/assets/js/script.js` | `http://localhost/roma/assets/js/script.js` |
| 11 | All navigation links (`url('...')`) | `http://localhost/login.php` etc. | `http://localhost/roma/login.php` etc. |
| 12 | DB images (already fixed in commit `e01135e`) | resolved relative | `http://localhost/roma/assets/uploads/...` |

> **Note:** Items 1–11 were **not** broken in the code templates themselves — the templates correctly call `url('assets/css/style.css')` etc. The breakage was entirely in the `SITE_URL` constant that `url()` depends on. Fixing the detection of `SITE_URL` fixes all 11 items at once.

---

## Corrected URLs

After applying the `SCRIPT_NAME`-based detection, every `url()` call now produces the correct absolute URL:

| Call | Before (broken) | After (fixed) |
|---|---|---|
| `url('assets/css/style.css')` | `http://localhost/assets/css/style.css` | `http://localhost/roma/assets/css/style.css` |
| `url('assets/js/script.js')` | `http://localhost/assets/js/script.js` | `http://localhost/roma/assets/js/script.js` |
| `url('login.php')` | `http://localhost/login.php` | `http://localhost/roma/login.php` |
| `url('admin/index.php')` | `http://localhost/admin/index.php` | `http://localhost/roma/admin/index.php` |
| `url('assets/uploads/news_xxx.jpg')` | `http://localhost/assets/uploads/news_xxx.jpg` | `http://localhost/roma/assets/uploads/news_xxx.jpg` |

---

## Files Modified

| File | Change |
|---|---|
| `config.php` | Replaced `DOCUMENT_ROOT`-based `SITE_URL` auto-detection with `SCRIPT_NAME` + `SCRIPT_FILENAME`-based detection |

### Previously modified (commit `e01135e`, still in effect)
| File | Change |
|---|---|
| `config.php` | Initial auto-detection (now superseded by this fix) |
| `includes/functions.php` | Added `asset()` helper; fixed CSP to allow `cdn.jsdelivr.net` |
| `index.php` | Wrapped slide + news images with `url()` |
| `news.php` | Wrapped single + list news images with `url()` |
| `teacher/index.php` | Wrapped child photo with `url()` |

---

## How the new detection works

```
// config.php (simplified)
$scriptName     = '/roma/admin/news.php';              // URL path of current request
$scriptFilename = 'D:/roma-final/roma/admin/news.php'; // Filesystem path
$appRoot        = 'D:/roma-final/roma';                // __DIR__ of config.php

// 1. Find app root inside script filename, take remainder
$relativeScript = 'admin/news.php';

// 2. Strip that remainder from the URL path
$basePath = '/roma/admin/news.php' minus 'admin/news.php' = '/roma';

// 3. Build final SITE_URL
SITE_URL = 'http://localhost' + '/roma' = 'http://localhost/roma';
```

This works regardless of:
- Whether the project is inside `DOCUMENT_ROOT` or served via `Alias`
- Whether the subfolder is `/roma`, `/roma97`, or `/`
- Whether the host is `localhost`, a vhost, or a production domain

---

## Verification

- `url('assets/css/style.css')` → `http://localhost/roma/assets/css/style.css` ✅
- `url('admin/index.php')` → `http://localhost/roma/admin/index.php` ✅
- No page generates `/admin/assets/...` — all asset paths are absolute via `url()` ✅
- `config.local.php` (if present) still takes precedence — production unaffected ✅
- No UI, HTML structure, or CSS design changes ✅