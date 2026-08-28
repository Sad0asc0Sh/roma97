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

function isStrongParentPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

$errors = [];
$step = (string) ($_GET['step'] ?? 'form');
$verifyPhone = normalizeIranianPhone((string) ($_GET['phone'] ?? ''));

$old = [
    'first_name' => '',
    'last_name'  => '',
    'phone'      => '',
];

try {
    initializeFinancialTables();
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $errors[] = 'ثبت‌نام موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
    $pdo = null;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST HANDLERS
// ─────────────────────────────────────────────────────────────────────────────
if (isPostRequest()) {
    $action = (string) ($_POST['action'] ?? 'submit_form');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    // ── 1. RESEND OTP CODE ───────────────────────────────────────────────────
    if ($action === 'resend_otp' && $errors === []) {
        $resendPhone = normalizeIranianPhone((string) ($_POST['phone'] ?? ''));
        if (!isValidIranianMobile($resendPhone)) {
            $errors[] = 'شماره موبایل نامعتبر است.';
        } else {
            $otpRes = generateAndSendSmsOtp($resendPhone, 'register');
            if ($otpRes['success']) {
                $msg = 'کد تایید جدید به شماره موبایل شما ارسال شد.';
                if (!empty($otpRes['code_debug'])) {
                    $msg .= " (کد تستی: {$otpRes['code_debug']})";
                }
                setFlash('success', $msg);
                redirect(url('register.php?step=verify&phone=' . urlencode($resendPhone)));
            } else {
                $errors[] = $otpRes['message'];
            }
        }
    }

    // ── 2. VERIFY OTP AND ACTUALLY CREATE ACCOUNT ─────────────────────────────
    if ($action === 'verify_otp' && $errors === []) {
        $verifyPhone = normalizeIranianPhone((string) ($_POST['phone'] ?? ''));
        $otpCode     = trim((string) ($_POST['otp_code'] ?? ''));

        if (!isValidIranianMobile($verifyPhone) || $otpCode === '') {
            $errors[] = 'لطفاً کد تایید ۵ رقمی ارسال شده را وارد کنید.';
        } else {
            $verifyResult = verifySmsOtp($verifyPhone, $otpCode, 'register');

            if (!$verifyResult['valid']) {
                $errors[] = $verifyResult['message'];
            } else {
                $payload = $verifyResult['payload'] ?? [];
                $firstName = trim((string) ($payload['first_name'] ?? ''));
                $lastName  = trim((string) ($payload['last_name'] ?? ''));
                $pwHash    = (string) ($payload['password_hash'] ?? '');

                if ($firstName === '' || $lastName === '' || $pwHash === '' || !$pdo instanceof PDO) {
                    $errors[] = 'اطلاعات ثبت‌نام منقضی شده است. لطفاً مجدداً ثبت‌نام کنید.';
                } else {
                    try {
                        $ins = $pdo->prepare('
                            INSERT INTO parents (first_name, last_name, email, phone, phone_verified, password, status, email_verified)
                            VALUES (:first_name, :last_name, NULL, :phone, 1, :password, :status, 0)
                        ');
                        $ins->execute([
                            ':first_name' => $firstName,
                            ':last_name'  => $lastName,
                            ':phone'      => $verifyPhone,
                            ':password'   => $pwHash,
                            ':status'     => 'pending',
                        ]);

                        $newParentId = (int) $pdo->lastInsertId();
                        recordAudit('auth.register', 'parent', $newParentId > 0 ? $newParentId : null, [
                            'phone' => $verifyPhone,
                        ]);

                        // Send welcome pattern SMS if configured
                        $smsSettings = getSmsSettings($pdo);
                        $welcomeBodyId = (int) ($smsSettings['sms_pattern_welcome'] ?? 0);
                        if ($welcomeBodyId > 0 && ($smsSettings['sms_enabled'] ?? '0') === '1') {
                            sendMeliPayamakPattern($verifyPhone, $welcomeBodyId, [$firstName . ' ' . $lastName], 'welcome', $newParentId);
                        }

                        setFlash('success', 'ثبت‌نام شما با موفقیت انجام شد و شماره موبایل شما تایید گردید. حساب کاربری شما پس از بررسی و تایید مدیریت مهد فعال خواهد شد.');
                        redirect(url('login.php'));
                    } catch (Throwable $e) {
                        error_log('Register insert parent error: ' . $e->getMessage());
                        $errors[] = 'ثبت‌نام با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
                    }
                }
            }
        }
    }

    // ── 3. SUBMIT INITIAL REGISTRATION FORM ──────────────────────────────────
    if ($action === 'submit_form' && $errors === []) {
        $old = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name'  => trim((string) ($_POST['last_name'] ?? '')),
            'phone'      => normalizeIranianPhone((string) ($_POST['phone'] ?? '')),
        ];
        $password        = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!checkBruteForce('parent_register', null)) {
            $errors[] = 'تعداد دفعات تلاش بیش از حد مجاز است. لطفاً چند دقیقه دیگر تلاش کنید.';
        }

        if (!isValidParentName($old['first_name'])) {
            $errors[] = 'لطفاً نام معتبر وارد کنید.';
        }

        if (!isValidParentName($old['last_name'])) {
            $errors[] = 'لطفاً نام خانوادگی معتبر وارد کنید.';
        }

        if (!isValidIranianMobile($old['phone'])) {
            $errors[] = 'لطفاً شماره موبایل معتبر ۱۱ رقمی وارد کنید (مثال: 09123456789).';
        }

        if (!isStrongParentPassword($password)) {
            $errors[] = 'رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.';
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'تکرار رمز عبور با رمز عبور وارد شده مطابقت ندارد.';
        }

        // Check for duplicate phone
        if ($errors === [] && $pdo instanceof PDO) {
            $checkPhone = $pdo->prepare('SELECT id FROM parents WHERE phone = :phone LIMIT 1');
            $checkPhone->execute([':phone' => $old['phone']]);
            if ($checkPhone->fetch()) {
                $errors[] = 'حساب کاربری با این شماره موبایل قبلاً ثبت شده است. لطفاً وارد شوید.';
            }
        }

        if ($errors !== [] && $pdo instanceof PDO) {
            recordFailedAttempt('parent_register');
        }

        // Send OTP verification code
        if ($errors === [] && $pdo instanceof PDO) {
            $payload = [
                'first_name'    => $old['first_name'],
                'last_name'     => $old['last_name'],
                'phone'         => $old['phone'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ];

            $otpRes = generateAndSendSmsOtp($old['phone'], 'register', $payload);

            if ($otpRes['success']) {
                $msg = 'کد تایید ۵ رقمی به شماره ' . persianNumber($old['phone']) . ' پیامک شد.';
                if (!empty($otpRes['code_debug'])) {
                    $msg .= " (کد آزمایشی: {$otpRes['code_debug']})";
                }
                setFlash('success', $msg);
                redirect(url('register.php?step=verify&phone=' . urlencode($old['phone'])));
            } else {
                $errors[] = $otpRes['message'];
            }
        }
    }
}

$successFlash = getFlash('success');
$siteNameValue = e(siteName());
$pageTitle = ($step === 'verify') ? 'تایید شماره موبایل' : 'ثبت‌نام والدین | ' . $siteNameValue;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ثبت‌نام والدین در مهد کودک <?= $siteNameValue ?>">
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
            <p class="auth-brand-subtitle">عضویت در سامانه هوشمند مهدکودک برای پیگیری لحظه‌ای دلبندان شما</p>
            <ul class="auth-brand-features">
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-check-circle')) ?>"/></svg></div>
                    <span>تایید سریع هویت با پیامک رمز یکبار مصرف</span>
                </li>
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-file-text')) ?>"/></svg></div>
                    <span>دریافت پیامکی گزارش‌های روزانه مربیان</span>
                </li>
                <li>
                    <div class="auth-feature-icon"><svg width="20" height="20" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-message-square')) ?>"/></svg></div>
                    <span>ورود مستقیم با رمز عبور پس از تایید مدیریت</span>
                </li>
            </ul>

            <div style="background: rgba(255,255,255,0.12); padding: 14px; border-radius: 12px; font-size: 0.85rem; line-height: 1.6; margin-top: 16px;">
                <p style="margin:0; font-style: italic;">«وقتی اولین بار اومدیم بازدید، پارسا نخواست بره. این از هر تبلیغی مهم‌تر بود.»</p>
                <span style="display:block; margin-top: 4px; opacity:0.8; font-weight:700;">— پدر پارسا، کلاس نوباوه</span>
            </div>
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

            <?php if ($successFlash !== null): ?>
                <div class="auth-alert auth-alert--success auth-anim-2" role="status" style="background:rgba(46,133,64,0.12);color:#1e632b;border:1px solid rgba(46,133,64,0.3);padding:12px;border-radius:10px;margin-bottom:16px;">
                    <span><?= e($successFlash) ?></span>
                </div>
            <?php endif; ?>

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

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- STEP 2: VERIFY OTP CODE                                        -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <?php if ($step === 'verify'): ?>
                <div class="auth-header auth-anim-2">
                    <h1>تایید شماره موبایل</h1>
                    <p style="font-size:0.9rem;">
                        کد ۵ رقمی پیامک شده به شماره <strong><?= e(persianNumber($verifyPhone)) ?></strong> را وارد کنید:
                        <a href="<?= e(url('register.php')) ?>" style="color:var(--primary,#3D8B63);font-size:0.8rem;margin-right:6px;text-decoration:underline;">(ویرایش اطلاعات)</a>
                    </p>
                </div>

                <form method="post" action="<?= e(url('register.php?step=verify&phone=' . urlencode($verifyPhone))) ?>" class="auth-form" id="verifyForm">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="verify_otp">
                    <input type="hidden" name="phone" value="<?= e($verifyPhone) ?>">

                    <div class="auth-field auth-anim-3">
                        <label for="otp_code" class="auth-field-label">کد تایید ۵ رقمی (OTP)</label>
                        <div class="auth-field-wrap">
                            <input type="text" id="otp_code" name="otp_code" class="auth-input" maxlength="6"
                                placeholder="— — — — —" pattern="[0-9]{5,6}" required autofocus
                                style="text-align:center;letter-spacing:10px;font-size:1.5rem;font-weight:700;direction:ltr;" inputmode="numeric">
                        </div>
                    </div>

                    <!-- Countdown Timer & Resend Button -->
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;font-size:0.85rem;" class="auth-anim-3">
                        <span id="timerBox" style="color:var(--auth-muted,#777);">
                            ⏱️ زمان باقی‌مانده: <strong id="countdown" style="color:var(--primary,#3D8B63);direction:ltr;display:inline-block;">02:00</strong>
                        </span>
                        <form method="post" action="<?= e(url('register.php?step=verify&phone=' . urlencode($verifyPhone))) ?>" id="resendForm" style="display:none;margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                            <input type="hidden" name="action" value="resend_otp">
                            <input type="hidden" name="phone" value="<?= e($verifyPhone) ?>">
                            <button type="submit" id="resendBtn" style="background:none;border:none;color:var(--primary,#3D8B63);cursor:pointer;font-weight:700;text-decoration:underline;padding:0;">
                                🔄 ارسال مجدد کد تایید
                            </button>
                        </form>
                    </div>

                    <button type="submit" class="auth-submit auth-anim-4" id="submitBtn">
                        <span class="btn-text">تایید و تکمیل ثبت‌نام</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </button>
                </form>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- STEP 1: INITIAL REGISTRATION FORM (Clean Mobile-Only)           -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <?php else: ?>
                <div class="auth-header auth-anim-2">
                    <h1>ثبت‌نام والدین در مهدکودک</h1>
                    <p>مشخصات خود را وارد کنید تا کد تایید پیامکی ارسال شود:</p>
                </div>

                <form method="post" action="<?= e(url('register.php')) ?>" novalidate class="auth-form" id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="submit_form">

                    <div class="auth-form-row auth-anim-3">
                        <div class="auth-field">
                            <label for="first_name" class="auth-field-label">نام <span class="req" style="color:red">*</span></label>
                            <div class="auth-field-wrap">
                                <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <input type="text" id="first_name" name="first_name" class="auth-input" maxlength="100"
                                    value="<?= e($old['first_name']) ?>" placeholder="نام" autocomplete="given-name" required autofocus>
                            </div>
                        </div>
                        <div class="auth-field">
                            <label for="last_name" class="auth-field-label">نام خانوادگی <span class="req" style="color:red">*</span></label>
                            <div class="auth-field-wrap">
                                <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <input type="text" id="last_name" name="last_name" class="auth-input" maxlength="100"
                                    value="<?= e($old['last_name']) ?>" placeholder="نام خانوادگی" autocomplete="family-name" required>
                            </div>
                        </div>
                    </div>

                    <div class="auth-field auth-anim-3">
                        <label for="phone" class="auth-field-label">شماره موبایل (جهت دریافت کد تایید و ورود) <span class="req" style="color:red">*</span></label>
                        <div class="auth-field-wrap">
                            <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
                            <input type="tel" id="phone" name="phone" class="auth-input" maxlength="15"
                                value="<?= e($old['phone']) ?>" placeholder="09123456789" autocomplete="tel" dir="ltr" required inputmode="numeric">
                        </div>
                        <small style="color:var(--auth-muted);font-size:0.8rem;">کد تایید ۵ رقمی به این شماره پیامک خواهد شد.</small>
                    </div>

                    <div class="auth-field auth-anim-4">
                        <label for="password" class="auth-field-label">رمز عبور ورود به پنل <span class="req" style="color:red">*</span></label>
                        <div class="auth-field-wrap">
                            <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            <input type="password" id="password" name="password" class="auth-input" minlength="8"
                                placeholder="حداقل ۸ کاراکتر شامل حرف و عدد" autocomplete="new-password" required>
                            <button type="button" class="auth-toggle-pwd" aria-label="نمایش رمز عبور" data-toggle="password">
                                <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="auth-field auth-anim-5">
                        <label for="confirm_password" class="auth-field-label">تکرار رمز عبور <span class="req" style="color:red">*</span></label>
                        <div class="auth-field-wrap">
                            <svg class="auth-field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            <input type="password" id="confirm_password" name="confirm_password" class="auth-input"
                                placeholder="تکرار رمز عبور" autocomplete="new-password" required>
                        </div>
                    </div>

                    <button type="submit" class="auth-submit auth-anim-6" id="submitBtn">
                        <span class="btn-text">ارسال کد تایید و ثبت‌نام</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-divider auth-anim-6">قبلاً ثبت‌نام کرده‌اید؟</div>
            <a href="<?= e(url('login.php')) ?>" class="auth-secondary-btn auth-anim-6" style="background:#fff;border:1.5px solid var(--primary,#3D8B63);color:var(--primary,#3D8B63);font-weight:700;">
                🔑 ورود به حساب با رمز عبور
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

    // Countdown Timer for OTP Step
    var countdownEl = document.getElementById('countdown');
    var timerBox = document.getElementById('timerBox');
    var resendForm = document.getElementById('resendForm');
    if (countdownEl && timerBox && resendForm) {
        var duration = 120;
        var interval = setInterval(function() {
            duration--;
            var minutes = Math.floor(duration / 60);
            var seconds = duration % 60;
            countdownEl.textContent = (minutes < 10 ? '0' : '') + minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

            if (duration <= 0) {
                clearInterval(interval);
                timerBox.style.display = 'none';
                resendForm.style.display = 'inline-block';
            }
        }, 1000);
    }
});
</script>
</body>
</html>
