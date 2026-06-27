<?php
/**
 * DIAGNOSTIC FILE — DELETE AFTER USE
 * Access via: http://localhost/roma/diagnose_url.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== ROMA URL DIAGNOSTIC ===\n\n";

echo "1. SITE_URL constant: " . (defined('SITE_URL') ? SITE_URL : '** NOT DEFINED **') . "\n";
echo "2. url(''): " . url('') . "\n";
echo "3. url('index.php'): " . url('index.php') . "\n";
echo "4. url('admin/index.php'): " . url('admin/index.php') . "\n\n";

echo "5. \$_SERVER['HTTP_HOST']: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "\n";
echo "6. \$_SERVER['SCRIPT_NAME']: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A') . "\n";
echo "7. \$_SERVER['SCRIPT_FILENAME']: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') . "\n";
echo "8. __DIR__: " . __DIR__ . "\n\n";

echo "9. config.local.php exists: " . (is_file(__DIR__ . '/config.local.php') ? 'YES' : 'NO') . "\n";
echo "10. PHP version: " . PHP_VERSION . "\n";
echo "11. OPcache enabled: " . (function_exists('opcache_get_status') ? (opcache_get_status(false)['opcache_enabled'] ? 'YES' : 'NO') : 'N/A (function not available)') . "\n";
echo "12. OPcache validate_timestamps: " . ini_get('opcache.validate_timestamps') . "\n";
echo "13. OPcache revalidate_freq: " . ini_get('opcache.revalidate_freq') . "\n\n";

echo "=== GENERATED NAVIGATION LINKS ===\n";
echo "Logo:       " . url('index.php') . "\n";
echo "About:      " . url('page.php?slug=about') . "\n";
echo "News:       " . url('news.php') . "\n";
echo "Classes:    " . url('page.php?slug=classes') . "\n";
echo "Contact:    " . url('page.php?slug=contact') . "\n";
echo "Login:      " . url('login.php') . "\n";
echo "Register:   " . url('register.php') . "\n";
echo "Parent:     " . url('parent/index.php') . "\n";
echo "Admin:      " . url('admin/index.php') . "\n";
echo "CSS:        " . url('assets/css/style.css') . "\n";
echo "JS:         " . url('assets/js/script.js') . "\n\n";

echo "=== CHECKING FOR EXAMPLE.COM IN ALL LAYERS ===\n";
echo "SITE_URL contains 'example.com': " . (str_contains(SITE_URL, 'example.com') ? 'YES *** PROBLEM ***' : 'NO (clean)') . "\n";
echo "url() result contains 'example.com': " . (str_contains(url('index.php'), 'example.com') ? 'YES *** PROBLEM ***' : 'NO (clean)') . "\n";