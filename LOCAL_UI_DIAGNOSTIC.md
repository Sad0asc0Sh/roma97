# ROMA — Local Deployment UI Diagnostic

**Date:** 2026-06-26  
**Environment:** XAMPP localhost (Windows 11, Apache + MySQL + PHP 8.1+)  
**Project root:** `d:\roma-final\roma`  
**Scope:** Deployment / asset-path / configuration issues only — no UI redesign.

---

## 1. Configuration Errors

### 1.1 `config.local.php` is missing

| Field | Value |
|---|---|
| **File** | `config.local.php` |
| **Status** | Missing |
| **Impact** | The two-file configuration pattern (`config.local.php` overrides `config.php`) is not active. All defaults from `config.php` are used. |
| **Root cause** | User deployed without copying `config.local.php.example` to `config.local.php`. |
| **Fix** | Auto-detect `SITE_URL` in `config.php` so the app works on any localhost subpath without requiring a manual `config.local.php`. |

### 1.2 `SITE_URL` is hardcoded to `http://localhost/roma`

| Field | Value |
|---|---|
| **File** | `config.php` line 27 |
| **Current value** | `define('SITE_URL', 'http://localhost/roma');` |
| **Generated URL (CSS)** | `http://localhost/roma/assets/css/style.css` |
| **Expected URL** | Depends on actual XAMPP deployment path (e.g. `http://localhost/roma`, `http://localhost/roma-final/roma`, `http://localhost`, or a vhost) |
| **Root cause** | `SITE_URL` is a static string that cannot adapt to the actual deployment path. If the project is served from any path other than `/roma`, every `url()` call generates a wrong URL → 404 on CSS, JS, images, and all links. |
| **Fix** | Replace the hardcoded default with runtime auto-detection using `$_SERVER['DOCUMENT_ROOT']` and `__DIR__`. This computes the correct base URL from the filesystem relationship between the Apache document root and the project root, regardless of the subpath. |

### 1.3 Content-Security-Policy blocks the Vazirmatn font CDN

| Field | Value |
|---|---|
| **File** | `includes/functions.php` lines 125–133 |
| **Directive** | `style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;` |
| **Blocked resource** | `https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css` (referenced in all 5 header templates + `teacher/login.php`) |
| **Generated URL** | `https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css` |
| **Expected URL** | Same — but it must be *allowed* by CSP. |
| **Root cause** | The CSP `style-src` whitelist includes `https://fonts.googleapis.com` but omits `https://cdn.jsdelivr.net`. Browsers silently block the font stylesheet. |
| **Also affected** | `font-src 'self' https://fonts.gstatic.com;` — the actual `.woff2` font files are served from `cdn.jsdelivr.net`, not `fonts.gstatic.com`, so font files are also blocked. |
| **Fix** | Add `https://cdn.jsdelivr.net` to both `style-src` and `font-src` in the CSP header. |

### 1.4 `FORCE_HTTPS` default is safe for localhost

| Field | Value |
|---|---|
| **File** | `config.php` line 31 |
| **Value** | `false` |
| **Status** | OK — no HTTPS redirect loop on localhost. |

---

## 2. Assets Loading Status

### 2.1 Physical asset files — all present

| Asset | Path | Exists |
|---|---|---|
| Main CSS | `assets/css/style.css` | ✅ |
| Main JS | `assets/js/script.js` | ✅ |
| Uploads dir | `assets/uploads/` | ✅ |
| Uploads `.htaccess` | `assets/uploads/.htaccess` | ✅ |

### 2.2 CSS references — all use `url()` correctly

| File | Line | Reference | Status |
|---|---|---|---|
| `templates/header.php` | 33 | `url('assets/css/style.css')` | ✅ |
| `admin/header.php` | 29 | `url('assets/css/style.css')` | ✅ |
| `parent/header.php` | 39 | `url('assets/css/style.css')` | ✅ |
| `teacher/header.php` | 28 | `url('assets/css/style.css')` | ✅ |
| `teacher/login.php` | 81 | `url('assets/css/style.css')` | ✅ |

> CSS references are structurally correct but will still 404 if `SITE_URL` is wrong.

### 2.3 JS references — all use `url()` correctly

| File | Line | Reference | Status |
|---|---|---|---|
| `templates/footer.php` | 77 | `url('assets/js/script.js')` | ✅ |
| `admin/footer.php` | 18 | `url('assets/js/script.js')` | ✅ |
| `parent/footer.php` | 16 | `url('assets/js/script.js')` | ✅ |
| `teacher/footer.php` | 18 | `url('assets/js/script.js')` | ✅ |
| `teacher/login.php` | 133 | `url('assets/js/script.js')` | ✅ |

> JS references are structurally correct but will still 404 if `SITE_URL` is wrong.

### 2.4 `url()` helper — implementation is correct

```php
function url(string $path = ''): string {
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}
```

The helper itself is fine. The problem is that `SITE_URL` can be wrong.

### 2.5 `asset()` helper — does not exist

The task references an `asset()` helper. It is **not defined** anywhere in the codebase. All asset references use `url()` directly. This is not a bug (the existing code works), but the helper should be added for clarity and to centralise asset-path logic.

---

## 3. Image Errors — DB-stored paths rendered without `url()`

Upload handlers store **relative** paths (e.g. `assets/uploads/news_xxx.jpg`) in the database. When rendering, these must be wrapped in `url()` to produce an absolute URL. Several files output the raw DB value, causing 404s when the page is in a subdirectory.

### 3.1 `index.php` — slide image (line 102)

| Field | Value |
|---|---|
| **File** | `index.php` |
| **Line** | 102 |
| **Code** | `<img src="<?= e($slide['image']) ?>" ...>` |
| **Generated URL** | `assets/uploads/slide_xxx.jpg` (relative) |
| **Expected URL** | `http://<host>/<base>/assets/uploads/slide_xxx.jpg` |
| **Root cause** | DB value is a relative path rendered without `url()`. On the root page this resolves correctly by accident, but it is fragile and inconsistent with the rest of the codebase. |
| **Fix** | `<?= e(url($slide['image'])) ?>` |

### 3.2 `index.php` — news image (line 283)

| Field | Value |
|---|---|
| **File** | `index.php` |
| **Line** | 283 |
| **Code** | `<img src="<?= e($newsItem['image']) ?>" ...>` |
| **Generated URL** | `assets/uploads/news_xxx.jpg` (relative) |
| **Expected URL** | `http://<host>/<base>/assets/uploads/news_xxx.jpg` |
| **Root cause** | Same as 3.1. |
| **Fix** | `<?= e(url($newsItem['image'])) ?>` |

### 3.3 `news.php` — single news image (line 111)

| Field | Value |
|---|---|
| **File** | `news.php` |
| **Line** | 111 |
| **Code** | `<img src="<?= e($singleNewsItem['image']) ?>" ...>` |
| **Generated URL** | `assets/uploads/news_xxx.jpg` (relative) |
| **Expected URL** | `http://<host>/<base>/assets/uploads/news_xxx.jpg` |
| **Root cause** | Same as 3.1. |
| **Fix** | `<?= e(url($singleNewsItem['image'])) ?>` |

### 3.4 `news.php` — news list image (line 146)

| Field | Value |
|---|---|
| **File** | `news.php` |
| **Line** | 146 |
| **Code** | `<img src="<?= e($newsItem['image']) ?>" ...>` |
| **Generated URL** | `assets/uploads/news_xxx.jpg` (relative) |
| **Expected URL** | `http://<host>/<base>/assets/uploads/news_xxx.jpg` |
| **Root cause** | Same as 3.1. |
| **Fix** | `<?= e(url($newsItem['image'])) ?>` |

### 3.5 `teacher/index.php` — child photo (line 210/215) — **CONFIRMED 404**

| Field | Value |
|---|---|
| **File** | `teacher/index.php` |
| **Line** | 210 (assignment) / 215 (render) |
| **Code** | `$photoUrl = !empty($child['photo']) ? e((string) $child['photo']) : '';`<br>`<img src="<?= $photoUrl ?>" ...>` |
| **Generated URL** | `assets/uploads/children/xxx.jpg` (relative) |
| **Expected URL** | `http://<host>/<base>/assets/uploads/children/xxx.jpg` |
| **Root cause** | The page is served from `/teacher/`, so the browser resolves the relative path to `http://<host>/<base>/teacher/assets/uploads/children/xxx.jpg` → **404**. |
| **Fix** | `$photoUrl = !empty($child['photo']) ? e(url((string) $child['photo'])) : '';` |

---

## 4. Path Errors — Summary

| # | File | Line | Issue | Severity |
|---|---|---|---|---|
| P1 | `config.php` | 27 | `SITE_URL` hardcoded — cannot adapt to actual deployment path | **Critical** |
| P2 | `includes/functions.php` | 127 | CSP `style-src` omits `cdn.jsdelivr.net` | **High** |
| P3 | `includes/functions.php` | 129 | CSP `font-src` omits `cdn.jsdelivr.net` | **High** |
| P4 | `index.php` | 102 | Slide image rendered without `url()` | Medium |
| P5 | `index.php` | 283 | News image rendered without `url()` | Medium |
| P6 | `news.php` | 111 | News image rendered without `url()` | Medium |
| P7 | `news.php` | 146 | News list image rendered without `url()` | Medium |
| P8 | `teacher/index.php` | 210 | Child photo rendered without `url()` → 404 in `/teacher/` | **High** |

---

## 5. `.htaccess` / mod_rewrite Audit

| File | Rule | Status |
|---|---|---|
| `.htaccess` | `Options -Indexes` | ✅ OK |
| `.htaccess` | `RewriteRule ^(includes\|templates)(/\|$) - [F,L]` | ✅ Correctly blocks direct access to `includes/` and `templates/`; does **not** block `assets/`. |
| `.htaccess` | `RewriteRule ^logs(/|$) - [F,L]` | ✅ OK |
| `.htaccess` | Cache-control + expires headers | ✅ OK |
| `assets/uploads/.htaccess` | Blocks PHP execution in uploads | ✅ OK |
| `includes/.htaccess` | Present | ✅ OK |
| `templates/.htaccess` | Present | ✅ OK |

> No `.htaccess` rule blocks CSS, JS, or image assets. The root cause is **not** Apache config.

---

## 6. Root Cause Summary

The broken UI is caused by **two independent issues** working together:

1. **`SITE_URL` is hardcoded** — If the project is served from any path other than `/roma` (e.g. `http://localhost`, `http://localhost/roma-final/roma`, or a vhost), every `url()` call produces a wrong absolute URL. Browsers request `http://localhost/roma/assets/css/style.css` and get a 404, so no CSS/JS loads.

2. **CSP blocks the CDN font** — Even when CSS loads, the Vazirmatn web-font stylesheet from `cdn.jsdelivr.net` is silently blocked by the Content-Security-Policy, making text render in a fallback font and the layout appear broken.

A secondary issue (DB image paths without `url()`) causes broken images in subdirectory pages like `teacher/index.php`.