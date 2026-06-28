<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

if (isTeacherLoggedIn()) {
    redirect(url('teacher/index.php'));
}

$error = '';

if (!checkBruteForce('teacher_login')) {
    $error = 'تعداد تلاشهای ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
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
            $error = 'تعداد تلاشهای ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
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
                    resetLoginAttempts('teacher_login', $email);
                    session_regenerate_id(true);
                    $_SESSION['last_activity'] = time();
                    $_SESSION['teacher_id']   = (int) $teacher['id'];
                    recordAudit('auth.login', 'teacher', (int) $teacher['id']);
                    $_SESSION['teacher_name'] = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
                    rotateCsrfToken();
                    redirect(url('teacher/index.php'));
                }
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $error = 'خطایی در سرور رخ داد. لطفاً بعداً دوباره تلاش کنید.';
            }
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
    <meta name="description" content="ورود معلم مهد کودک <?= $siteNameValue ?>">
    <meta name="theme-color" content="#E8A838">
    <title>ورود معلم | <?= $siteNameValue ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <?php
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $appRootDir = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: __DIR__ . '/..');
    $appRootDir = realpath($appRootDir) ?: $appRootDir;
    $scriptDirReal = realpath($scriptDir) ?: $scriptDir;
    $relativePrefix = '';
    $tmp = $scriptDirReal;
    $appNorm = str_replace('\\', '/', $appRootDir);
    while (strlen($tmp) > strlen($appNorm) && str_starts_with(str_replace('\\', '/', $tmp), $appNorm)) {
        $relativePrefix .= '../';
        $tmp = dirname($tmp);
    }
    if ($relativePrefix === '') { $relativePrefix = './'; }
    ?>
    <link rel="stylesheet" href="<?= e($relativePrefix . 'assets/css/auth.css') ?>">
</head>
<body class="auth-standalone">
<div class="auth-wrapper">
    <!-- Brand Panel -->
    <aside class="auth-brand">
        <div class="brand-circle-1"></div>
        <div class="brand-circle-2"></div>
        <div class="auth-brand-content auth-brand-anim">
            <div class="auth-brand-logo">
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                    <circle cx="20" cy="20" r="18" stroke="url(#tGrad)" stroke-width="2"/>
                    <path d="M12 16l8-4 8 4-8 4-8-4z" stroke="url(#tGrad)" stroke-width="1.5" fill="none" stroke-linejoin="round"/>
                    <path d="M12 16v6c0 2 3.6 4 8 4s8-2 8-4v-6" stroke="url(#tGrad)" stroke-width="1.5" fill="none"/>
                    <line x1="20" y1="20" x2="20" y2="28" stroke="url(#tGrad)" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M28 16v4" stroke="url(#tGrad)" stroke-width="1.5" stroke-linecap="round"/>
                    <defs>
                        <linearGradient id="tGrad" x1="0" y1="0" x2="40" y2="40">
                            <stop stop-color="#3D8B63"/>
                            <stop offset="1" stop-color="#C4724A"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <h2 class="auth-brand-title">پنل معلم <span><?= $siteNameValue ?></span></h2>
            <p class="auth-brand-subtitle">ابزارهای مدیریت کلاس، ثبت گزارش روزانه و ارتباط با والدین</p>
            <ul class="auth-brand-features">
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                    </div>
                    <span>مشاهده لیست کودکان کلاس</span>
                </li>
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <span>ثبت گزارش روزانه هر کودک</span>
                </li>
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <span>حضور و غیاب آنلاین</span>
                </li>
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    </div>
                    <span>ارتباط مستقیم با والدین</span>
                </li>
            </ul>
        </div>
    </aside>

    <!-- Form Panel -->
    <main class="auth-form-panel">
        <div class="auth-form-container">
            <div class="auth-mobile-logo auth-anim-1">
                <div class="auth-mobile-logo-icon">
                    <svg width="28" height="28" viewBox="0 0 40 40" fill="none">
                        <path d="M12 16l8-4 8 4-8 4-8-4z" stroke="white" stroke-width="1.5" fill="none" stroke-linejoin="round"/>
                        <path d="M12 16v6c0 2 3.6 4 8 4s8-2 8-4v-6" stroke="white" stroke-width="1.5" fill="none"/>
                        <line x1="20" y1="20" x2="20" y2="28" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M28 16v4" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <span class="auth-mobile-logo-text"><?= $siteNameValue ?></span>
            </div>

            <div class="auth-header auth-anim-2">
                <span class="auth-role-badge auth-role-badge--teacher">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
                    پنل معلم
                </span>
                <h1>ورود معلم</h1>
                <p>برای دسترسی به پنل معلمی وارد شوید</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--danger auth-anim-2" role="alert">
                    <svg class="auth-alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('teacher/login.php')) ?>" novalidate class="auth-form" id="authForm">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="auth-field auth-anim-3">
                    <label for="email" class="auth-field-label">آدرس ایمیل</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" id="email" name="email" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>" value="<?= e($_POST['email'] ?? '') ?>" placeholder="example@email.com" autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="auth-field auth-anim-4">
                    <label for="password" class="auth-field-label">رمز عبور</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" id="password" name="password" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>" placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password" required>
                        <button type="button" class="auth-toggle-pwd" aria-label="نمایش رمز عبور">
                            <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit auth-anim-5" id="submitBtn">
                    <span class="btn-text">ورود به پنل معلم</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                    <span class="auth-spinner"></span>
                </button>
            </form>

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
                input.type = 'text';
                toggleBtn.innerHTML = eyeClosed;
                toggleBtn.setAttribute('aria-label', 'مخفی کردن رمز عبور');
            } else {
                input.type = 'password';
                toggleBtn.innerHTML = eyeOpen;
                toggleBtn.setAttribute('aria-label', 'نمایش رمز عبور');
            }
        });
    }
    var form = document.getElementById('authForm');
    var submitBtn = document.getElementById('submitBtn');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.classList.add('is-loading');
            submitBtn.querySelector('.btn-text').textContent = 'در حال ورود...';
            submitBtn.querySelector('svg:not(.auth-spinner)').style.display = 'none';
        });
    }
    document.querySelectorAll('.auth-input.has-error').forEach(function(input) {
        input.addEventListener('focus', function() { this.classList.remove('has-error'); });
    });
});
</script>
</body>
</html>