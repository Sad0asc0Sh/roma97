<?php
declare(strict_types=1);

/**
 * Legacy admin login entry point — kept as a redirect-only shim.
 * Authentication now happens through the unified login (login.php).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirect(url('admin/index.php'));
}

redirect(url('login.php'));
