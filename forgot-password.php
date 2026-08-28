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
if (isTeacherLoggedIn()) {
    redirect(url('teacher/index.php'));
}

$step = (string) ($_GET['step'] ?? 'request');
$phone = normalizeIranianPhone((string) ($_GET['phone'] ?? ''));

$error = '';
$successMessage = getFlash('success');

try {
    initializeFinancialTables();
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $pdo = null;
}

if (isPostRequest()) {
    $action = (string) ($_POST['action'] ?? 'request_otp');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    // ── 1. REQUEST RESET OTP ─────────────────────────────────────────────────
    if ($action === 'request_otp' && $error === '') {
        $rawPhone = trim((string) ($_POST['phone'] ?? ''));
        $normPhone = normalizeIranianPhone($rawPhone);

        if (!isValidIranianMobile($normPhone)) {
            $error = 'لطفاً شماره موبایل معتبر ۱۱ رقمی وارد کنید (مثال: 09123456789).';
        } elseif (!checkBruteForce('parent_pwd_reset', $normPhone)) {
            $error = 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
        } elseif ($pdo instanceof PDO) {
            $stmt = $pdo->prepare('SELECT id, first_name, last_name, status FROM parents WHERE phone = :phone LIMIT 1');
            $stmt->execute([':phone' => $normPhone]);
            $parent = $stmt->fetch();

            if (!$parent) {
                $error = 'حساب کاربری با این شماره موبایل یافت نشد.';
                recordFailedAttempt('parent_pwd_reset', $normPhone);
            } elseif ($parent['status'] === 'suspended') {
                $error = 'حساب کاربری شما مسدود شده است. با مدیریت مهد تماس بگیرید.';
            } else {
                $otpRes = generateAndSendSmsOtp($normPhone, 'reset_password', ['parent_id' => (int) $parent['id']]);

                if ($otpRes['success']) {
                    $msg = 'کد تایید ۵ رقمی تغییر رمز به شماره ' . persianNumber($normPhone) . ' پیامک شد.';
                    if (!empty($otpRes['code_debug'])) {
                        $msg .= " (کد تستی: {$otpRes['code_debug']})";
                    }
                    setFlash('success', $msg);
                    redirect(url('forgot-password.php?step=verify&phone=' . urlencode($normPhone)));
                } else {
                    $error = $otpRes['message'];
                }
            }
        }
    }

    // ── 2. VERIFY OTP AND SET NEW PASSWORD ───────────────────────────────────
    if ($action === 'set_new_password' && $error === '') {
        $verifyPhone     = normalizeIranianPhone((string) ($_POST['phone'] ?? ''));
        $otpCode         = trim((string) ($_POST['otp_code'] ?? ''));
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!isValidIranianMobile($verifyPhone) || $otpCode === '') {
            $error = 'لطفاً کد تایید ۵ رقمی را وارد کنید.';
        } elseif (strlen($newPassword) < 8 || preg_match('/[0-9]/', $newPassword) !== 1) {
            $error = 'رمز عبور جدید باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'تکرار رمز عبور جدید با رمز عبور مطابقت ندارد.';
        } elseif (!checkBruteForce('parent_pwd_reset_verify', $verifyPhone)) {
            $error = 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
        } else {
            $vRes = verifySmsOtp($verifyPhone, $otpCode, 'reset_password');

            if (!$vRes['valid']) {
                $error = $vRes['message'];
                recordFailedAttempt('parent_pwd_reset_verify', $verifyPhone);
            } elseif ($pdo instanceof PDO) {
                resetLoginAttempts('parent_pwd_reset_verify', $verifyPhone);

                $upStmt = $pdo->prepare('UPDATE parents SET password = :p WHERE phone = :phone');
                $upStmt->execute([
                    ':p'     => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':phone' => $verifyPhone,
                ]);

                recordAudit('auth.password_reset_success', 'parent', null, ['phone' => $verifyPhone]);
                setFlash('success', 'رمز عبور حساب شما با موفقیت تغییر یافت. اکنون می‌توانید وارد شوید.');
                redirect(url('login.php'));
            }
        }
    }
}

$siteNameValue = e(siteName());
$pageTitle = 'بازیابی رمز عبور | ' . $siteNameValue;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="بازیابی رمز عبور والدین مهد کودک <?= $siteNameValue ?>">
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
            <p class="auth-brand-subtitle">بازیابی سریع و امن رمز عبور با پیامک رمز یکبار مصرف</p>
        </div>
    </aside>

    <!-- Main Form Panel -->
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

            <!-- ── STEP 2: VERIFY OTP & SET NEW PASSWORD ────────────────────── -->
            <?php if ($step === 'verify'): ?>
                <div class="auth-header auth-anim-2">
                    <h1>تغییر رمز عبور</h1>
                    <p style="font-size:0.9rem;">
                        کد ۵ رقمی پیامک شده به شماره <strong><?= e(persianNumber($phone)) ?></strong> و رمز جدید را وارد کنید:
                    </p>
                </div>

                <form method="post" action="<?= e(url('forgot-password.php?step=verify&phone=' . urlencode($phone))) ?>" class="auth-form" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="set_new_password">
                    <input type="hidden" name="phone" value="<?= e($phone) ?>">

                    <div class="auth-field auth-anim-3">
                        <label for="otp_code" class="auth-field-label">کد تایید ۵ رقمی پیامک شده</label>
                        <div class="auth-field-wrap">
                            <input type="text" id="otp_code" name="otp_code" class="auth-input" maxlength="6"
                                placeholder="— — — — —" pattern="[0-9]{5,6}" required autofocus
                                style="text-align:center;letter-spacing:10px;font-size:1.5rem;font-weight:700;direction:ltr;" inputmode="numeric">
                        </div>
                    </div>

                    <div class="auth-field auth-anim-4">
                        <label for="new_password" class="auth-field-label">رمز عبور جدید</label>
                        <div class="auth-field-wrap">
                            <input type="password" id="new_password" name="new_password" class="auth-input" minlength="8"
                                placeholder="حداقل ۸ کاراکتر با حرف و عدد" autocomplete="new-password" required>
                        </div>
                    </div>

                    <div class="auth-field auth-anim-4">
                        <label for="confirm_password" class="auth-field-label">تکرار رمز عبور جدید</label>
                        <div class="auth-field-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" class="auth-input"
                                placeholder="تکرار رمز جدید" autocomplete="new-password" required>
                        </div>
                    </div>

                    <button type="submit" class="auth-submit auth-anim-5" id="submitBtn">
                        <span class="btn-text">ثبت رمز جدید و ورود</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                    </button>
                </form>

            <!-- ── STEP 1: REQUEST OTP WITH PHONE ──────────────────────────── -->
            <?php else: ?>
                <div class="auth-header auth-anim-2">
                    <h1>بازیابی رمز عبور</h1>
                    <p>شماره موبایل حساب خود را وارد کنید تا کد تایید پیامکی ارسال شود:</p>
                </div>

                <form method="post" action="<?= e(url('forgot-password.php?step=request')) ?>" novalidate class="auth-form" id="reqForm">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="request_otp">

                    <div class="auth-field auth-anim-3">
                        <label for="phone" class="auth-field-label">شماره موبایل حساب</label>
                        <div class="auth-field-wrap">
                            <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
                            <input type="tel" id="phone" name="phone" class="auth-input" maxlength="15"
                                value="<?= e($phone) ?>" placeholder="09123456789" required autofocus dir="ltr" inputmode="numeric">
                        </div>
                    </div>

                    <button type="submit" class="auth-submit auth-anim-4" id="submitBtn">
                        <span class="btn-text">ارسال کد پیامکی بازیابی</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-divider auth-anim-6">یا</div>
            <a href="<?= e(url('login.php')) ?>" class="auth-secondary-btn auth-anim-6" style="background:#fff;border:1.5px solid var(--primary,#3D8B63);color:var(--primary,#3D8B63);font-weight:700;">
                بازگشت به صفحه ورود
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
</body>
</html>
