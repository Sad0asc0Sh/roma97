<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/db.php';

if (isParentLoggedIn()) {
    redirect(url('parent/index.php'));
}

function registerStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function isValidParentName(string $name): bool
{
    if ($name === '' || registerStringLength($name) > 100) {
        return false;
    }

    return preg_match("/^[\p{L}\p{Z}\s'\-]+$/u", $name) === 1;
}

function isValidParentPhone(string $phone): bool
{
    return $phone === ''
        || (registerStringLength($phone) <= 15 && preg_match('/\A[0-9+\-\s]{7,15}\z/', $phone) === 1);
}

function isStrongParentPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

$errors = [];
$old = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
];

try {
    initializeParentTables();
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $errors[] = 'ثبتنام موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.';
    $pdo = null;
}

if (isPostRequest()) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $old = [
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
    ];
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    if (!isValidParentName($old['first_name'])) {
        $errors[] = 'لطفاً نام معتبر وارد کنید.';
    }

    if (!isValidParentName($old['last_name'])) {
        $errors[] = 'لطفاً نام خانوادگی معتبر وارد کنید.';
    }

    if (
        $old['email'] === ''
        || registerStringLength($old['email']) > 150
        || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'لطفاً یک آدرس ایمیل معتبر وارد کنید.';
    }

    if (!isValidParentPhone($old['phone'])) {
        $errors[] = 'لطفاً شماره تلفن معتبر وارد کنید.';
    }

    if (!isStrongParentPassword($password)) {
        $errors[] = 'رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'تکرار رمز عبور مطابقت ندارد.';
    }

    if ($errors === [] && $pdo instanceof PDO) {
        $checkEmail = $pdo->prepare('SELECT id FROM parents WHERE email = :email LIMIT 1');
        $checkEmail->execute([':email' => $old['email']]);

        if ($checkEmail->fetch()) {
            $errors[] = 'حسابی با این ایمیل قبلاً ثبت شده است.';
        }
    }

    if ($errors === [] && $pdo instanceof PDO) {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO parents (first_name, last_name, email, phone, password, status, email_verified)
                 VALUES (:first_name, :last_name, :email, :phone, :password, :status, :email_verified)'
            );
            $statement->execute([
                ':first_name' => $old['first_name'],
                ':last_name' => $old['last_name'],
                ':email' => $old['email'],
                ':phone' => $old['phone'] === '' ? null : $old['phone'],
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':status' => 'pending',
                ':email_verified' => 0,
            ]);

            setFlash('success', 'ثبتنام شما با موفقیت انجام شد. منتظر تأیید مدیر بمانید.');
            redirect(url('login.php'));
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $errors[] = 'ثبتنام با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
        }
    }
}

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ثبت‌نام والدین در مهد کودک <?= e(siteName()) ?>">
    <meta name="theme-color" content="#3D8B63">
    <title>ثبت‌نام | <?= e(siteName()) ?></title>
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
            <h2 class="auth-brand-title">به <span><?= e(siteName()) ?></span> خوش آمدید</h2>
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
                <span class="auth-mobile-logo-text"><?= e(siteName()) ?></span>
            </div>

            <div class="auth-header auth-anim-2">
                <h1>ساخت حساب جدید</h1>
                <p>برای ثبت‌نام اطلاعات خود را وارد کنید</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="auth-alert auth-alert--danger auth-anim-2" role="alert">
                    <svg class="auth-alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <ul class="auth-alert-list">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('register.php')) ?>" novalidate class="auth-form" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="auth-form-row auth-anim-3">
                    <div class="auth-field">
                        <label for="first_name" class="auth-field-label">نام</label>
                        <div class="auth-field-wrap">
                            <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="first_name" name="first_name" class="auth-input" maxlength="100" value="<?= e($old['first_name']) ?>" placeholder="نام" autocomplete="given-name" required>
                        </div>
                    </div>
                    <div class="auth-field">
                        <label for="last_name" class="auth-field-label">نام خانوادگی</label>
                        <div class="auth-field-wrap">
                            <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="last_name" name="last_name" class="auth-input" maxlength="100" value="<?= e($old['last_name']) ?>" placeholder="نام خانوادگی" autocomplete="family-name" required>
                        </div>
                    </div>
                </div>
                <div class="auth-field auth-anim-3">
                    <label for="email" class="auth-field-label">ایمیل</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" id="email" name="email" class="auth-input" maxlength="150" value="<?= e($old['email']) ?>" placeholder="example@email.com" autocomplete="email" required>
                    </div>
                </div>

                <div class="auth-field auth-anim-4">
                    <label for="phone" class="auth-field-label">شماره تلفن <small style="color:var(--auth-muted);font-weight:400">(اختیاری)</small></label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
                        <input type="tel" id="phone" name="phone" class="auth-input" maxlength="15" value="<?= e($old['phone']) ?>" placeholder="۰۹۱۲۳۴۵۶۷۸۹" autocomplete="tel">
                    </div>
                </div>

                <div class="auth-field auth-anim-5">
                    <label for="password" class="auth-field-label">رمز عبور</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" id="password" name="password" class="auth-input" minlength="8" placeholder="حداقل ۸ کاراکتر با حرف و عدد" autocomplete="new-password" required>
                        <button type="button" class="auth-toggle-pwd" aria-label="نمایش رمز عبور" data-toggle="password">
                            <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="auth-field auth-anim-5">
                    <label for="confirm_password" class="auth-field-label">تکرار رمز عبور</label>
                    <div class="auth-field-wrap">
                        <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" id="confirm_password" name="confirm_password" class="auth-input" placeholder="تکرار رمز عبور" autocomplete="new-password" required>
                    </div>
                </div>

                <button type="submit" class="auth-submit auth-anim-6" id="submitBtn">
                    <span class="btn-text">ثبت‌نام</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    <span class="btn-spinner"></span>
                </button>
            </form>
            <div class="auth-divider auth-anim-6">یا</div>
            <a href="<?= e(url('login.php')) ?>" class="auth-secondary-btn auth-anim-6">قبلاً ثبت‌نام کرده‌اید؟ وارد شوید</a>

            <div class="auth-back auth-anim-6">
                <a href="<?= e(url('index.php')) ?>">
                    بازگشت به صفحه اصلی
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>

            <div class="auth-footer auth-anim-6">
                <p>&copy; <?= e(date('Y')) ?> <?= e(siteName()) ?>. تمامی حقوق محفوظ است</p>
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
    var form = document.getElementById('registerForm');
    var submitBtn = document.getElementById('submitBtn');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.classList.add('is-loading');
            submitBtn.querySelector('.btn-text').textContent = 'در حال ثبت‌نام...';
            submitBtn.querySelector('svg:not(.btn-spinner)').style.display = 'none';
        });
    }
});
</script>
</body>
</html>