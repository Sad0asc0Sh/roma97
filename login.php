<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/sms.php';

if (isLoggedIn()) {
    redirect(url('admin/index.php'));
}

if (isParentLoggedIn()) {
    redirect(url('parent/index.php'));
}

$error = '';
$successMessage = getFlash('success');
$phone = '';

try {
    initializeFinancialTables();
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $pdo = null;
}

if (isPostRequest()) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $rawPhone  = trim((string) ($_POST['phone'] ?? ''));
    $password  = (string) ($_POST['password'] ?? '');
    $phone     = normalizeIranianPhone($rawPhone);

    if (!validateCsrfToken($csrfToken)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (!isValidIranianMobile($phone)) {
        $error = 'لطفاً شماره موبایل معتبر ۱۱ رقمی وارد کنید (مثال: 09123456789).';
    } elseif (!checkBruteForce('parent_login', $phone)) {
        $error = 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
    } elseif ($password === '') {
        $error = 'لطفاً رمز عبور خود را وارد کنید.';
    } elseif ($pdo === null) {
        $error = 'سامانه ورود موقتاً در دسترس نیست.';
    } else {
        try {
            $statement = $pdo->prepare('SELECT id, first_name, last_name, phone, password, status FROM parents WHERE phone = :phone LIMIT 1');
            $statement->execute([':phone' => $phone]);
            $parent = $statement->fetch();

            if (!$parent || !password_verify($password, (string) $parent['password'])) {
                $error = 'شماره موبایل یا رمز عبور نادرست است.';
                recordFailedAttempt('parent_login', $phone);
            } elseif ($parent['status'] === 'pending') {
                $error = 'حساب کاربری شما در انتظار تأیید مدیریت مهدکودک است. لطفاً منتظر بمانید.';
            } elseif ($parent['status'] === 'suspended') {
                $error = 'حساب کاربری شما مسدود شده است. لطفاً با مدیریت مهد تماس بگیرید.';
            } elseif ($parent['status'] !== 'active') {
                $error = 'امکان ورود به این حساب وجود ندارد.';
                recordFailedAttempt('parent_login', $phone);
            } else {
                resetLoginAttempts('parent_login', $phone);

                session_regenerate_id(true);
                $_SESSION['last_activity'] = time();
                $_SESSION['parent_id'] = (int) $parent['id'];
                recordAudit('auth.login', 'parent', (int) $parent['id']);
                $_SESSION['parent_name'] = trim($parent['first_name'] . ' ' . $parent['last_name']);
                $_SESSION['parent_first_name'] = (string) $parent['first_name'];

                rotateCsrfToken();
                redirect(url('parent/index.php'));
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'ورود موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.';
        }
    }
}

$siteNameValue = e(siteName());
$pageTitle = 'ورود به پنل والدین | ' . $siteNameValue;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ورود به پنل والدین مهد کودک <?= $siteNameValue ?>">
    <meta name="theme-color" content="#3D8B63">
    <title><?= e($pageTitle) ?></title>
    <link rel="preload" href="<?= e(url('assets/fonts/Vazirmatn-Regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>">
</head>
<body class="auth-standalone">
<div class="auth-wrapper">

    <!-- Right Brand Sidebar -->
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
            <p class="auth-brand-subtitle">سامانه هوشمند مهدکودک برای پیگیری وضعیت روزانه دلبندان شما</p>
            <ul class="auth-brand-features">
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-check-circle')) ?>"/></svg></div>
                    <span>مشاهده وضعیت حضور و غیاب کودک</span>
                </li>
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-file-text')) ?>"/></svg></div>
                    <span>گزارش روزانه تغذیه، خواب و احوال کودک</span>
                </li>
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-message-square')) ?>"/></svg></div>
                    <span>ارتباط مستقیم با مدیریت و مربیان</span>
                </li>
            </ul>

            <div style="background: rgba(255,255,255,0.12); padding: 14px; border-radius: 12px; font-size: 0.85rem; line-height: 1.6; margin-top: 16px;">
                <p style="margin:0; font-style: italic;">«گزارش‌های روزانه مربیان و اطلاع‌رسانی پیامکی، خیال ما رو کاملاً راحت می‌کنه.»</p>
                <span style="display:block; margin-top: 4px; opacity:0.8; font-weight:700;">— مادر آیدا، کلاس خردسال</span>
            </div>
        </div>
    </aside>

    <!-- Main Login Form -->
    <main class="auth-form-panel">
        <div class="auth-form-container">
            <div class="auth-mobile-logo auth-anim-1">
                <div class="auth-mobile-logo-icon">
                    <svg width="28" height="28" viewBox="0 0 40 40" fill="none">
                        <path d="M20 11c-3 0-5.5 1.5-5.5 4.2 0 1.8 1 3 2.5 3.9-.6.4-1.4 1.2-1.8 2.2-.4 1-.2 1.8.4 2.4.6.6 1.4.6 2.2.3.7-.2 1.2-1 1.5-1.5h.8c.3.5.8 1.3 1.5 1.5.8.3 1.6.3 2.2-.3.6-.6.8-1.4.4-2.4-.4-1-1.2-1.8-1.8-2.2 1.5-.9 2.5-2.1 2.5-3.9 0-2.7-2.5-4.2-5.5-4.2z" fill="white"/>
                    </svg>
                </div>
                <span class="auth-mobile-logo-text"><?= $siteNameValue ?></span>
            </div>

            <div class="auth-header auth-anim-2">
                <h1>ورود به پنل والدین</h1>
                <p>شماره موبایل و رمز عبور خود را وارد کنید:</p>
            </div>

            <?php if ($successMessage !== null): ?>
                <div class="auth-alert auth-alert--success auth-anim-2" role="status" style="background:rgba(46,133,64,0.12);color:#1e632b;border:1px solid rgba(46,133,64,0.3);padding:12px;border-radius:10px;margin-bottom:16px;">
                    <span><?= e($successMessage) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--danger auth-anim-2" role="alert">
                    <svg class="auth-alert-icon" width="20" height="20" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-alert-triangle')) ?>"/></svg>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('login.php')) ?>" novalidate class="auth-form" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="auth-field auth-anim-3">
                    <label for="phone" class="auth-field-label">شماره موبایل</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
                        <input type="tel" id="phone" name="phone" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>"
                            maxlength="15" value="<?= e($phone) ?>" placeholder="09123456789" required autofocus dir="ltr" inputmode="numeric">
                    </div>
                </div>

                <div class="auth-field auth-anim-4">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label for="password" class="auth-field-label">رمز عبور</label>
                        <a href="<?= e(url('forgot-password.php?type=parent')) ?>" style="font-size:0.8rem; color:var(--primary,#3D8B63); text-decoration:none;">فراموشی رمز عبور؟</a>
                    </div>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" id="password" name="password" class="auth-input<?= $error !== '' ? ' has-error' : '' ?>" placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password" required>
                        <button type="button" class="auth-toggle-pwd" aria-label="نمایش رمز عبور" data-toggle="password">
                            <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit auth-anim-5" id="submitBtn">
                    <span class="btn-text">ورود به پنل والدین</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                </button>
            </form>

            <div class="auth-divider auth-anim-6">حساب کاربری ندارید؟</div>
            <a href="<?= e(url('register.php')) ?>" class="auth-secondary-btn auth-anim-6" style="background:#fff;border:1.5px solid var(--primary,#3D8B63);color:var(--primary,#3D8B63);font-weight:700;">
                📝 ثبت‌نام جدید با شماره موبایل
            </a>

            <div class="auth-back auth-anim-6" style="margin-top:24px;">
                <a href="<?= e(url('index.php')) ?>">
                    بازگشت به صفحه اصلی مهدکودک
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
    var toggleBtns = document.querySelectorAll('.auth-toggle-pwd');
    var eyeOpen = '<svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var eyeClosed = '<svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    toggleBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.closest('.auth-field-wrap').querySelector('input');
            if (input.type === 'password') {
                input.type = 'text'; this.innerHTML = eyeClosed;
                this.setAttribute('aria-label', 'مخفی کردن رمز عبور');
            } else {
                input.type = 'password'; this.innerHTML = eyeOpen;
                this.setAttribute('aria-label', 'نمایش رمز عبور');
            }
        });
    });
});
</script>
</body>
</html>
