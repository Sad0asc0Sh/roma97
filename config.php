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
defined('SITE_URL') || define('SITE_URL', 'http://localhost/roma');

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
