<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

if (isLoggedIn()) {
    redirect(url('admin/index.php'));
}

if (isParentLoggedIn()) {
    redirect(url('parent/index.php'));
}

$error = '';
$successMessage = getFlash('success');
$email = '';

// Check brute-force lockout before any DB work
if (!checkBruteForce('parent_login')) {
    $error = 'تعداد تلاشهای ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
}

try {
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $pdo = null;
}

if (isPostRequest() && $error === '') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (
        $pdo === null
        || $email === ''
        || strlen($email) > 150
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $password === ''
    ) {
        $error = 'ایمیل یا رمز عبور نادرست است.';
    } elseif (!checkBruteForce('parent_login', $email)) {
        $error = 'تعداد تلاشهای ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT id, first_name, last_name, email, password, status FROM parents WHERE email = :email LIMIT 1'
            );
            $statement->execute([':email' => $email]);
            $parent = $statement->fetch();

            if (!$parent || !password_verify($password, $parent['password'])) {
                $error = 'ایمیل یا رمز عبور نادرست است.';
                recordFailedAttempt('parent_login', $email);
            } elseif ($parent['status'] === 'pending') {
                $error = 'حساب شما در انتظار تأیید است. لطفاً منتظر تأیید مدیر بمانید.';
            } elseif ($parent['status'] === 'suspended') {
                $error = 'حساب شما مسدود شده است. با ما تماس بگیرید.';
            } elseif ($parent['status'] !== 'active') {
                $error = 'ایمیل یا رمز عبور نادرست است.';
                recordFailedAttempt('parent_login', $email);
            } else {
                resetLoginAttempts('parent_login', $email);

                session_regenerate_id(true);
                $_SESSION['parent_id'] = (int) $parent['id'];
                recordAudit('auth.login', 'parent', (int) $parent['id']);
                $_SESSION['parent_name'] = trim($parent['first_name'] . ' ' . $parent['last_name']);
                $_SESSION['parent_first_name'] = (string) $parent['first_name'];

                redirect(url('parent/index.php'));
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'ورود موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.';
        }
    }
}

$siteNameValue = e(siteName());
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ورود به پنل والدین مهد کودک <?= $siteNameValue ?>">
    <meta name="theme-color" content="#3D8B63">
    <title>ورود | <?= $siteNameValue ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>">
</head>
<body class="auth-standalone">
<div class="auth-wrapper">

    <aside class="auth-brand auth-brand-anim">
        <div class="brand-circle-1"></div>
        <div class="brand-circle-2"></div>
        <div class="auth-brand-content">
            <div class="auth-brand-logo">
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                    <path d="M20 11c-3 0-5.5 1.5-5.5 4.2 0 1.8 1 3 2.5 3.9-.6.4-1.4 1.2-1.8 2.2-.4 1-.2 1.8.4 2.4.6.6 1.4.6 2.2.3.7-.2 1.2-1 1.5-1.5h.8c.3.5.8 1.3 1.5 1.5.8.3 1.6.3 2.2-.3.6-.6.8-1.4.4-2.4-.4-1-1.2-1.8-1.8-2.2 1.5-.9 2.5-2.1 2.5-3.9 0-2.7-2.5-4.2-5.5-4.2z" fill="white"/>
                    <circle cx="17.5" cy="15.5" r="1" fill="rgba(255,255,255,0.7)"/>
                    <circle cx="22.5" cy="15.5" r="1" fill="rgba(255,255,255,0.7)"/>
                    <path d="M17.5 19.5c0 0 1.2 1.8 2.5 1.8s2.5-1.8 2.5-1.8" stroke="rgba(255,255,255,0.7)" stroke-width="1" stroke-linecap="round" fill="none"/>
                </svg>
            </div>
            <h2 class="auth-brand-title">به <span><?= $siteNameValue ?></span> خوش آمدید</h2>
            <p class="auth-brand-subtitle">پنل هوشمند والدین برای پیگیری وضعیت فرزندان</p>
            <ul class="auth-brand-features">
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    <span>پیگیری لحظه‌ای وضعیت حضور و غیاب</span>
                </li>
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
                    <span>مشاهده گزارش‌های روزانه مربیان</span>
                </li>
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
                    <span>ارتباط مستقیم با مربیان و مدیریت</span>
                </li>
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
                    <span>مدیریت پرداخت شهریه آنلاین</span>
                </li>
            </ul>
        </div>
    </aside>
    <main class="auth-form-panel">
        <div class="auth-form-container">
            <div class="auth-mobile-logo auth-anim-1">
                <div class="auth-mobile-logo-icon">
                    <svg width="28" height="28" viewBox="0 0 40 40" fill="none">
                        <path d="M20 11c-3 0-5.5 1.5-5.5 4.2 0 1.8 1 3 2.5 3.9-.6.4-1.4 1.2-1.8 2.2-.4 1-.2 1.8.4 2.4.6.6 1.4.6 2.2.3.7-.2 1.2-1 1.5-1.5h.8c.3.5.8 1.3 1.5 1.5.8.3 1.6.3 2.2-.3.6-.6.8-1.4.4-2.4-.4-1-1.2-1.8-1.8-2.2 1.5-.9 2.5-2.1 2.5-3.9 0-2.7-2.5-4.2-5.5-4.2z" fill="white"/>
                        <circle cx="17.5" cy="15.5" r="1" fill="rgba(255,255,255,0.7)"/>
                        <circle cx="22.5" cy="15.5" r="1" fill="rgba(255,255,255,0.7)"/>
                        <path d="M17.5 19.5c0 0 1.2 1.8 2.5 1.8s2.5-1.8 2.5-1.8" stroke="rgba(255,255,255,0.7)" stroke-width="1" stroke-linecap="round" fill="none"/>
                    </svg>
                </div>
                <span class="auth-mobile-logo-text"><?= $siteNameValue ?></span>
            </div>

            <div class="auth-header auth-anim-2">
                <h1>ورود به پنل والدین</h1>
                <p>لطفاً اطلاعات حساب خود را وارد کنید</p>
            </div>

            <?php if ($successMessage !== null): ?>
                <div class="auth-alert auth-alert--success auth-anim-2" role="status">
                    <svg class="auth-alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span><?= e($successMessage) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--danger auth-anim-2" role="alert">
                    <svg class="auth-alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('login.php')) ?>" novalidate class="auth-form" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="auth-field auth-anim-3">
                    <label for="email" class="auth-field-label">ایمیل</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" id="email" name="email" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>" maxlength="150" value="<?= e($email) ?>" placeholder="example@email.com" autocomplete="email" required autofocus>
                    </div>
                </div>

                <div class="auth-field auth-anim-4">
                    <label for="password" class="auth-field-label">رمز عبور</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" id="password" name="password" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>" placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password" required>
                        <button type="button" class="auth-toggle-pwd" aria-label="نمایش رمز عبور" data-toggle="password">
                            <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit auth-anim-5" id="submitBtn">
                    <span class="btn-text">ورود به حساب</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                    <span class="btn-spinner"></span>
                </button>
            </form>

            <div class="auth-divider auth-anim-6">یا</div>
            <a href="<?= e(url('register.php')) ?>" class="auth-secondary-btn auth-anim-6">ساخت حساب جدید</a>
            <div class="auth-back auth-anim-6">
                <a href="<?= e(url('index.php')) ?>">
                    بازگشت به صفحه اصلی
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>

            <div class="auth-footer auth-anim-6">
                <p>&copy; <?= e(date('Y')) ?> <?= $siteNameValue ?>. تمامی حقوق محفوظ است</p>
            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.querySelector('.auth-toggle-pwd');
    if (toggleBtn) {
        var eyeOpen = '<svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var eyeClosed = '<svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        toggleBtn.addEventListener('click', function() {
            var input = document.getElementById('password');
            if (input.type === 'password') {
                input.type = 'text'; toggleBtn.innerHTML = eyeClosed;
                toggleBtn.setAttribute('aria-label', 'مخفی کردن رمز عبور');
            } else {
                input.type = 'password'; toggleBtn.innerHTML = eyeOpen;
                toggleBtn.setAttribute('aria-label', 'نمایش رمز عبور');
            }
        });
    }
    var form = document.getElementById('loginForm');
    var submitBtn = document.getElementById('submitBtn');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.classList.add('is-loading');
            submitBtn.querySelector('.btn-text').textContent = 'در حال ورود...';
            submitBtn.querySelector('svg:not(.btn-spinner)').style.display = 'none';
        });
    }
    document.querySelectorAll('.auth-input.has-error').forEach(function(input) {
        input.addEventListener('focus', function() { this.classList.remove('has-error'); });
    });
});
</script>
</body>
</html>