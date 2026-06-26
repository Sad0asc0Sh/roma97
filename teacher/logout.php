<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!isPostRequest()) {
    redirect(url('teacher/login.php'));
}

$token = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($token)) {
    redirect(url('teacher/login.php'));
}

// Fully destroy the session for security
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => (bool) ($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

// Start a fresh session so flash messages / redirects work safely
session_start();
session_regenerate_id(true);

redirect(url('teacher/login.php'));
