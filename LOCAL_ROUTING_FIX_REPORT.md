# LOCAL ROUTING & RESOLUTION FIX REPORT

This report details the routing and URL generation analysis and the modifications applied to resolve broken navigation links and asset paths within the ROMA application under local environments (such as XAMPP) using subfolders like `/roma/` or `/roma97/`.

---

## 1. Root Cause Analysis

In local development environments (e.g., XAMPP, WAMP, or Apache aliases), applications are commonly deployed in subfolders under the localhost root (for example, `http://localhost/roma/` or `http://localhost/roma97/`).

The application had the following resolution vulnerabilities:
1. **Root-relative Links**: The generic error template in `includes/error_handler.php` used a hardcoded root-relative link (`href="/"`) to return to the home page. When the application was served from a subdirectory, this link bypassed the subfolder path and sent users to `http://localhost/`, which broke navigation.
2. **CLI Fallback URL Resolution**: In the default `config.php` file, the fallback `SITE_URL` (which is applied when running via CLI, cron jobs, or when environment headers are not populated) was hardcoded to `http://localhost/roma`. If the user renamed the folder or ran the site under a different subfolder name (e.g., `roma97`), URL helper methods executed in these contexts would construct incorrect URLs.
3. **Precedence Overrides**: If a user copied `config.local.php.example` to `config.local.php` without modifying `SITE_URL`, the hardcoded production placeholder `https://example.com` would override the dynamic localhost auto-detection, breaking all local links.

---

## 2. Dynamic Solution Highlights

Rather than hardcoding directory paths or relying on manual updates in `config.local.php` for local testing:
1. **Directory-Based CLI Fallback**: Modified the fallback `SITE_URL` calculation in `config.php` to dynamically determine the directory name using `basename(__DIR__)`. If the directory is `roma97`, it falls back to `http://localhost/roma97`. If it is `roma`, it falls back to `http://localhost/roma`.
2. **Dynamic Error Page Link**: Replaced the root-relative `href="/"` link in `includes/error_handler.php` with a dynamic check using `SITE_URL` (safely handling fallback options if the helper functions are not yet loaded).
3. **Dynamic URL Helper Resolution**: Validated all layout headers (`templates/header.php`, `admin/header.php`, `parent/header.php`, `teacher/header.php`) to confirm they rely fully on the dynamic `url()` helper function rather than hardcoded URLs.

---

## 3. Broken vs. Corrected URLs

| Target Page / Context | Original / Broken URL (Subfolder deployment) | Corrected Dynamic Resolution (e.g., under `/roma97/`) |
| :--- | :--- | :--- |
| **Error Page Home Link** | `/` (Resolves to `http://localhost/`) | `http://localhost/roma97/` (via `SITE_URL` resolution) |
| **CLI / cron / setup fallback** | `http://localhost/roma` | `http://localhost/roma97` (via `basename(__DIR__)`) |
| **Local config override template** | `https://example.com` | Dynamic auto-detection (with option to override in `config.local.php` if needed) |

---

## 4. Files Modified

1. **`config.php`** (Lines 88–91):
   - Replaced the hardcoded fallback `'http://localhost/roma'` with `'http://localhost/' . basename(__DIR__)`. This ensures that CLI executions or fallback states auto-adapt to folder renames like `roma97`.
2. **`includes/error_handler.php`** (Lines 143–144):
   - Replaced `<a href="/">` with `<a href="' . (defined('SITE_URL') ? htmlspecialchars(SITE_URL, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '/') . '">`.

---

## 5. Verification

1. **Routing and Asset Paths**:
   - Accessing `index.php` or sub-dashboards (under `admin/`, `parent/`, or `teacher/`) resolves the base path dynamically from `SCRIPT_NAME` and `SCRIPT_FILENAME`.
   - Asset loading (CSS/JS) links dynamically resolved via `url('assets/css/style.css')` render with correct paths (e.g., `http://localhost/roma97/assets/css/style.css`).
2. **CLI Fallback**:
   - Running scripts from the command line resolves `SITE_URL` dynamically using the current folder's name.
