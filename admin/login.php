<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

if (isLoggedIn()) {
    redirect(url('admin/index.php'));
}

$error = '';
$setupUnavailable = false;

try {
    initializeDatabase();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $setupUnavailable = true;
}

$lockedOut = !checkBruteForce('admin_login');

if ($lockedOut) {
    $error = 'تعداد تلاشهای ناموفق برای ورود زیاد است. لطفاً ۱۵ دقیقه صبر کنید و دوباره تلاش کنید.';
} elseif (isPostRequest()) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif ($setupUnavailable) {
        $error = 'ورود موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
    } elseif (
        !preg_match('/\A[A-Za-z0-9_]{3,50}\z/', $username)
        || strlen($password) < 1
        || strlen($password) > 255
    ) {
        $error = 'نام کاربری یا رمز عبور نامعتبر است.';
    } elseif (!checkBruteForce('admin_login', $username)) {
        $error = 'تعداد تلاشهای ناموفق برای ورود زیاد است. لطفاً ۱۵ دقیقه صبر کنید و دوباره تلاش کنید.';
    } else {
        try {
            $pdo = getDb();
            $statement = $pdo->prepare(
                'SELECT id, username, password FROM admins WHERE username = :username LIMIT 1'
            );
            $statement->execute([':username' => $username]);
            $admin = $statement->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                resetLoginAttempts('admin_login', $username);
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                recordAudit('auth.login', 'admin', (int) $admin['id']);
                redirect(url('admin/index.php'));
            }

            $error = 'نام کاربری یا رمز عبور نامعتبر است.';
            recordFailedAttempt('admin_login', $username);
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'ورود موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
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
    <meta name="description" content="ورود مدیر مهد کودک <?= $siteNameValue ?>">
    <meta name="theme-color" content="#3D8B63">
    <title>ورود مدیر | <?= $siteNameValue ?></title>
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
                    <circle cx="20" cy="20" r="18" stroke="url(#aGrad)" stroke-width="2"/>
                    <rect x="11" y="14" width="18" height="13" rx="2" stroke="url(#aGrad)" stroke-width="1.5" fill="none"/>
                    <path d="M14 14v-3a6 6 0 0112 0v3" stroke="url(#aGrad)" stroke-width="1.5" fill="none"/>
                    <circle cx="20" cy="21" r="2" fill="url(#aGrad)"/>
                    <line x1="20" y1="23" x2="20" y2="25" stroke="url(#aGrad)" stroke-width="1.5" stroke-linecap="round"/>
                    <defs>
                        <linearGradient id="aGrad" x1="0" y1="0" x2="40" y2="40">
                            <stop stop-color="#3D8B63"/>
                            <stop offset="1" stop-color="#C4724A"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <h2 class="auth-brand-title">پنل مدیریت <span><?= $siteNameValue ?></span></h2>
            <p class="auth-brand-subtitle">مدیریت جامع مهد کودک با دسترسی کامل به تمام بخشها</p>
            <ul class="auth-brand-features">
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                    </div>
                    <span>مدیریت کودکان، معلمان و والدین</span>
                </li>
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <span>مدیریت مالی و شهریهها</span>
                </li>
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <span>گزارشگیری و آمار پیشرفته</span>
                </li>
                <li>
                    <div class="auth-feature-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    </div>
                    <span>تنظیمات و پیکربندی سیستم</span>
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
                        <rect x="11" y="14" width="18" height="13" rx="2" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M14 14v-3a6 6 0 0112 0v3" stroke="white" stroke-width="1.5" fill="none"/>
                        <circle cx="20" cy="21" r="2" fill="white"/>
                        <line x1="20" y1="23" x2="20" y2="25" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <span class="auth-mobile-logo-text"><?= $siteNameValue ?></span>
            </div>

            <div class="auth-header auth-anim-2">
                <span class="auth-role-badge auth-role-badge--admin">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    پنل مدیریت
                </span>
                <h1>ورود مدیر</h1>
                <p>برای دسترسی به پنل مدیریت وارد شوید</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--danger auth-anim-2" role="alert">
                    <svg class="auth-alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('admin/login.php')) ?>" novalidate class="auth-form" id="authForm">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="auth-field auth-anim-3">
                    <label for="username" class="auth-field-label">نام کاربری</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="username" name="username" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>" maxlength="50" placeholder="نام کاربری خود را وارد کنید" autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="auth-field auth-anim-4">
                    <label for="password" class="auth-field-label">رمز عبور</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" id="password" name="password" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>" maxlength="255" placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password" required>
                        <button type="button" class="auth-toggle-pwd" aria-label="نمایش رمز عبور">
                            <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit auth-anim-5" id="submitBtn">
                    <span class="btn-text">ورود به پنل مدیریت</span>
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