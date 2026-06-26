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

        // Derive the base path by stripping the document root from this file's path.
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docRoot !== '') {
            // Normalise separators so str_replace works on both Windows and Unix.
            $normalizedDocRoot = str_replace('\\', '/', realpath($docRoot));
            $normalizedAppRoot = str_replace('\\', '/', __DIR__);

            if ($normalizedDocRoot !== '' && $normalizedAppRoot !== '' && str_starts_with($normalizedAppRoot, $normalizedDocRoot)) {
                $basePath = substr($normalizedAppRoot, strlen($normalizedDocRoot));
                // Leading/trailing slashes are trimmed; a root deployment yields ''.
                $basePath = trim($basePath, '/');
                if ($basePath !== '') {
                    $detectedSiteUrl .= '/' . $basePath;
                }
            }
        }
    }

    // Fall back to the historical default if auto-detection failed (e.g. CLI).
    if ($detectedSiteUrl === '') {
        $detectedSiteUrl = 'http://localhost/roma';
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
