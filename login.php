<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/auth_flow.php';
require_once __DIR__ . '/includes/unified_auth.php';

if (isLoggedIn()) { redirect(url('admin/index.php')); }
if (isParentLoggedIn()) { redirect(url('parent/index.php')); }
if (isTeacherLoggedIn()) { redirect(url('teacher/index.php')); }
header('Cache-Control: no-store');

$error = getFlash('error') ?? '';
$successMessage = getFlash('success');
$phone = '';
$fieldErrors = [];
$pdo = null;

// Unified login: identifier is a phone (parent/teacher) or username (admin).
// The hidden `panel` field is a UI hint ONLY and is never trusted —
// unifiedAttemptLogin() decides the panel from the credential server-side.
if (isPostRequest()) {
    try {
        initializeFinancialTables();
        $pdo = getDb();
    } catch (Throwable $exception) {
        error_log('Login storage unavailable: ' . $exception->getMessage());
        $error = 'سامانه ورود موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
    }
}

if (isPostRequest()) {
    $error = '';
    $identifier = trim(authInput($_POST, 'identifier'));
    $phone = $identifier;
    $password = authInput($_POST, 'password');
    $kind = unifiedIdentifierKind($identifier);

    if (!validateCsrfToken(authInput($_POST, 'csrf_token'))) {
        $error = 'زمان اعتبار فرم تمام شده است. لطفاً دوباره تلاش کنید.';
    } elseif ($kind === 'invalid') {
        $fieldErrors['identifier'] = 'شماره موبایل یا نام کاربری معتبر وارد کنید.';
        $error = 'شماره موبایل یا نام کاربری معتبر وارد کنید.';
    } elseif ($password === '' || strlen($password) > 4096 || str_contains($password, "\0")) {
        $fieldErrors['password'] = 'رمز عبور خود را وارد کنید.';
        $error = 'رمز عبور خود را وارد کنید.';
    } elseif ($pdo === null) {
        $error = 'سامانه ورود موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
    } else {
        // Per-panel brute-force buckets keyed by identifier.
        $bucket = $kind === 'username' ? 'admin_login' : 'unified_phone_login';
        $throttleKey = $kind === 'username' ? mb_strtolower($identifier, 'UTF-8') : normalizeIranianPhone($identifier);
        try {
            if (!checkBruteForce($bucket, $throttleKey)) {
                $error = 'تعداد تلاش‌ها بیش از حد مجاز است. ۱۵ دقیقه دیگر تلاش کنید.';
            } else {
                $attempt = unifiedAttemptLogin($pdo, $identifier, $password);
                if ($attempt['error'] === 'ok') {
                    resetLoginAttempts($bucket, $throttleKey);
                    unifiedEstablishSession($attempt['panel'], $attempt['user']);
                    recordAudit('auth.login', $attempt['panel'], (int) $attempt['user']['id']);
                    redirect(unifiedPanelHome($attempt['panel']));
                }
                recordFailedAttempt($bucket, $throttleKey);
                if ($attempt['error'] === 'ambiguous') {
                    recordAudit('auth.login_ambiguous', 'unified', null, ['identifier_kind' => $kind]);
                }
                $error = $attempt['message'];
                if ($attempt['error'] === 'invalid_input') {
                    $fieldErrors['identifier'] = $error;
                }
            }
        } catch (Throwable $exception) {
            error_log('Unified login failed: ' . $exception->getMessage());
            $error = 'ورود موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
        }
    }
}

$authMode = 'login';
$pageTitle = 'ورود | ' . siteName();
require __DIR__ . '/templates/unified-auth-page.php';
