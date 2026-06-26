<?php
declare(strict_types=1);

// Application constant to prevent direct file access
define('ROOMA_APP', true);

/*
 * Environment-specific overrides.
 *
 * Copy config.local.php.example to config.local.php and adjust the values for
 * your environment (database credentials, SITE_URL, DEVELOPMENT_MODE, ...).
 * config.local.php is NOT committed to version control (see .gitignore) and is
 * loaded BEFORE the defaults below, so any constant it defines wins.
 */
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

// ─── Database configuration ────────────────────────────────────────────────
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'rooma_db');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

// Full base URL where this folder is served, without a trailing slash.
//
// Auto-detection: if config.local.php has not already defined SITE_URL, we
// compute it from the relationship between the Apache document root and this
// project directory. This makes the app work on any localhost subpath
// (e.g. http://localhost/roma, http://localhost/roma-final/roma, or a vhost)
// without requiring a manual config.local.php for local development.
if (!defined('SITE_URL')) {
    $detectedSiteUrl = '';

    // Build the scheme://host:port portion of the current request.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';

    $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($httpHost !== '') {
        $detectedSiteUrl = $scheme . '://' . $httpHost;

        // Derive the base URL path from the relationship between the URL path
        // of the current script (SCRIPT_NAME) and its filesystem path
        // (SCRIPT_FILENAME). This is robust against Apache Alias directives,
        // custom document roots, vhosts, and any subfolder deployment — it does
        // NOT rely on DOCUMENT_ROOT, which can be wrong when the project lives
        // outside the default htdocs (e.g. XAMPP with an Alias).
        //
        // Example:
        //   SCRIPT_NAME      = /roma/admin/news.php
        //   SCRIPT_FILENAME  = D:/roma-final/roma/admin/news.php
        //   __DIR__          = D:/roma-final/roma
        //   -> relative script = admin/news.php
        //   -> base URL path   = /roma
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptFilename = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $appRoot = str_replace('\\', '/', __DIR__);

        if ($scriptName !== '' && $scriptFilename !== '' && $appRoot !== '') {
            // Find where the app root ends in SCRIPT_FILENAME, then take the
            // remainder as the script's path relative to the project.
            $relativeScript = '';
            $rootEnd = strpos($scriptFilename, $appRoot);
            if ($rootEnd !== false) {
                $afterRoot = substr($scriptFilename, $rootEnd + strlen($appRoot));
                $relativeScript = ltrim($afterRoot, '/');
            }

            // Strip the relative script path from the end of SCRIPT_NAME to
            // obtain the base URL path.
            if ($relativeScript !== '' && str_ends_with($scriptName, $relativeScript)) {
                $basePath = substr($scriptName, 0, strlen($scriptName) - strlen($relativeScript));
            } else {
                // Fallback: assume SCRIPT_NAME points directly into the root.
                $basePath = $scriptName;
            }

            $basePath = rtrim($basePath, '/');
            $detectedSiteUrl .= $basePath;
        }
    }

    if ($detectedSiteUrl === '' || $detectedSiteUrl === 'http://' || $detectedSiteUrl === 'https://') {
        $detectedSiteUrl = 'http://localhost/' . basename(__DIR__);
    }

    define('SITE_URL', $detectedSiteUrl);
}

// ─── Security configuration ────────────────────────────────────────────────
// Force HTTPS redirects. Enable in production with a valid SSL certificate.
defined('FORCE_HTTPS') || define('FORCE_HTTPS', false);

// Show detailed errors. MUST stay false in production; enable only locally
// (preferably via config.local.php).
defined('DEVELOPMENT_MODE') || define('DEVELOPMENT_MODE', false);

// ─── Error logging ─────────────────────────────────────────────────────────
defined('ERROR_LOG_PATH') || define('ERROR_LOG_PATH', __DIR__ . '/logs/error.log');

// ─── Session configuration ─────────────────────────────────────────────────
defined('SESSION_LIFETIME') || define('SESSION_LIFETIME', 0); // 0 = until browser closes

// ─── Upload configuration ──────────────────────────────────────────────────
defined('MAX_UPLOAD_SIZE') || define('MAX_UPLOAD_SIZE', 512000);   // 500KB in bytes
defined('UPLOAD_PERMISSIONS') || define('UPLOAD_PERMISSIONS', 0644);
defined('UPLOAD_DIR_PERMISSIONS') || define('UPLOAD_DIR_PERMISSIONS', 0755);
