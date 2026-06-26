<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Already logged in → go to dashboard
if (isTeacherLoggedIn()) {
    redirect(url('teacher/index.php'));
}

$error = '';

// Check brute-force lockout before processing login (IP-based pre-check).
if (!checkBruteForce('teacher_login')) {
    $error = 'تعداد تلاش‌های ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
}

if (isPostRequest() && $error === '') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } else {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'لطفاً ایمیل و رمز عبور خود را وارد کنید.';
        } elseif (!checkBruteForce('teacher_login', $email)) {
            // Per-account lockout, independent of the IP-based pre-check above.
            $error = 'تعداد تلاش‌های ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
        } else {
            try {
                initializeTeachersTables();
                $pdo = getDb();
                $stmt = $pdo->prepare(
                    'SELECT id, first_name, last_name, password, status FROM teachers WHERE email = :email LIMIT 1'
                );
                $stmt->execute([':email' => $email]);
                $teacher = $stmt->fetch();

                if (
                    $teacher === false
                    || !password_verify($password, (string) $teacher['password'])
                ) {
                    $error = 'ایمیل یا رمز عبور نامعتبر است.';
                    recordFailedAttempt('teacher_login', $email);
                } elseif ((string) $teacher['status'] !== 'active') {
                    $error = 'حساب شما فعال نیست. لطفاً با مدیر تماس بگیرید.';
                } else {
                    // Successful login
                    resetLoginAttempts('teacher_login', $email);
                    session_regenerate_id(true);
                    $_SESSION['teacher_id']   = (int) $teacher['id'];
                    recordAudit('auth.login', 'teacher', (int) $teacher['id']);
                    $_SESSION['teacher_name'] = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
                    redirect(url('teacher/index.php'));
                }
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $error = 'خطایی در سرور رخ داد. لطفاً بعداً دوباره تلاش کنید.';
            }
        }
    }
}

$pageTitle = 'ورود معلم | ' . e(siteName());
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="auth-page teacher-auth-page">
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo">
            <span class="auth-logo-icon">🏫</span>
            <h1><?= e(siteName()) ?></h1>
            <p class="auth-subtitle">پنل معلم</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('teacher/login.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

            <div class="form-group">
                <label for="email">آدرس ایمیل</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    value="<?= e($_POST['email'] ?? '') ?>"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">رمز عبور</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary btn-block">ورود</button>
        </form>

        <p class="auth-footer-note">
            <a href="<?= e(url('index.php')) ?>">← بازگشت به سایت</a>
        </p>
    </div>
</div>
<script src="<?= e(url('assets/js/script.js')) ?>" defer></script>
</body>
</html>
