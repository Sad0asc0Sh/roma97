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

if (isLoggedIn()) { redirect(url('admin/index.php')); }
if (isParentLoggedIn()) { redirect(url('parent/index.php')); }
if (isTeacherLoggedIn()) { redirect(url('teacher/index.php')); }
header('Cache-Control: no-store');

function registerStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
function isValidParentName(string $name): bool
{
    return $name !== '' && registerStringLength($name) <= 100
        && preg_match("/^[\p{L}\p{Z}\s\x{200C}'\-]+$/u", $name) === 1;
}
function isStrongParentPassword(string $password): bool
{
    return authStrongPassword($password);
}

$errors = [];
$fieldErrors = [];
$step = authInput($_GET, 'step') === 'verify' ? 'verify' : 'form';
$verifyPhone = normalizeIranianPhone(authInput($_GET, 'phone'));
$resendAvailableAt = 0;
$old = ['first_name' => '', 'last_name' => '', 'phone' => ''];
// Restore only nonsecret fields when returning from verification to edit the form.
$savedPhone = is_array($_SESSION['registration_pending'] ?? null)
    ? authInput($_SESSION['registration_pending'], 'phone') : '';
$savedPending = authPending('registration_pending', $savedPhone);
if ($savedPending !== null) {
    foreach (array_keys($old) as $key) { $old[$key] = authInput($savedPending, $key); }
    if ($step === 'verify' && $verifyPhone === '') { $verifyPhone = $savedPhone; }
}
$action = authInput($_POST, 'action', 'submit_form');
// Set view state before CSRF/storage checks so errors never erase submitted names or phone.
if (isPostRequest()) {
    if ($action === 'submit_form') {
        $step = 'form';
        $old = [
            'first_name' => trim(authInput($_POST, 'first_name')),
            'last_name' => trim(authInput($_POST, 'last_name')),
            'phone' => normalizeIranianPhone(authInput($_POST, 'phone')),
        ];
    } elseif (in_array($action, ['resend_otp', 'verify_otp'], true)) {
        $step = 'verify';
        $verifyPhone = normalizeIranianPhone(authInput($_POST, 'phone'));
    }
}
$pdo = null;
// اتصال تنبل به ذخیره‌سازی: فرم خالی ثبت‌نام (GET بدون شماره معتبر) نیازی به DB ندارد.
// فقط برای POST یا صفحه تأیید (که استعلام cooldown لازم دارد) وصل شو.
$needsRegistrationStorage = isPostRequest()
    || ($step === 'verify' && isValidIranianMobile($verifyPhone));
if ($needsRegistrationStorage) {
    try {
        // All runtime DDL must run BEFORE starting the OTP/account transaction.
        initializeFinancialTables();
        $pdo = getDb();
    } catch (Throwable $exception) {
        error_log('Registration storage unavailable: ' . $exception->getMessage());
        $errors[] = 'ثبت‌نام موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
    }
}

if (isPostRequest() && $errors === [] && $pdo instanceof PDO) {
    if (!validateCsrfToken(authInput($_POST, 'csrf_token'))) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } else {
        try {
            if ($action === 'submit_form') {
                $password = authInput($_POST, 'password');
                if (!checkBruteForce('parent_register', $old['phone'])) {
                    $errors[] = 'تعداد دفعات تلاش بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
                }
                if (!isValidParentName($old['first_name'])) {
                    $errors[] = $fieldErrors['first_name'] = 'لطفاً نام معتبر وارد کنید.';
                }
                if (!isValidParentName($old['last_name'])) {
                    $errors[] = $fieldErrors['last_name'] = 'لطفاً نام خانوادگی معتبر وارد کنید.';
                }
                if (!isValidIranianMobile($old['phone'])) {
                    $errors[] = $fieldErrors['phone'] = 'لطفاً شماره موبایل معتبر وارد کنید.';
                }
                if (!isStrongParentPassword($password)) {
                    $errors[] = $fieldErrors['password'] = 'رمز عبور باید ۸ تا ۷۲ بایت و شامل حرف انگلیسی و عدد باشد.';
                }
                if ($password !== authInput($_POST, 'confirm_password')) {
                    $errors[] = $fieldErrors['confirm_password'] = 'تکرار رمز عبور مطابقت ندارد.';
                }
                if ($errors === []) {
                    $stmt = $pdo->prepare('SELECT id FROM parents WHERE phone = :phone LIMIT 1');
                    $stmt->execute([':phone' => $old['phone']]);
                    if ($stmt->fetch()) { $errors[] = 'حسابی با این شماره ثبت شده است. لطفاً وارد شوید.'; }
                }
                if ($errors === []) {
                    $verifyPhone = $old['phone'];
                    // Check cooldown before replacing the existing session-owned payload.
                    $resendAvailableAt = authResendAvailableAt($pdo, $verifyPhone, 'register');
                    if ($resendAvailableAt > time()) {
                        $errors[] = 'کد به‌تازگی ارسال شده است. لطفاً پس از پایان زمان انتظار تلاش کنید.';
                    } else {
                        $pending = $old + ['password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'challenge' => bin2hex(random_bytes(32)), 'expires_at' => time() + 1800];
                        $_SESSION['registration_pending'] = $pending;
                        $step = 'verify'; // Keep a failed delivery retryable without losing the pending payload.
                        recordFailedAttempt('parent_register', $verifyPhone); // Count sends, not only invalid forms.
                        $otpRes = generateAndSendSmsOtp($verifyPhone, 'register', $pending);
                        if ($otpRes['success']) {
                            $msg = 'کد تایید ۵ رقمی به شماره موبایل شما ارسال شد.';
                            if (!empty($otpRes['code_debug'])) { $msg .= " (کد آزمایشی: {$otpRes['code_debug']})"; }
                            setFlash('success', $msg);
                            redirect(url('register.php?step=verify&phone=' . urlencode($verifyPhone)));
                        }
                        $errors[] = $otpRes['message'];
                    }
                }
            } elseif (in_array($action, ['resend_otp', 'verify_otp'], true)) {
                $step = 'verify';
                $verifyPhone = normalizeIranianPhone(authInput($_POST, 'phone'));
                $pending = authPending('registration_pending', $verifyPhone);
                if (!isValidIranianMobile($verifyPhone) || $pending === null
                    || !isValidParentName(authInput($pending, 'first_name'))
                    || !isValidParentName(authInput($pending, 'last_name'))
                    || empty(password_get_info(authInput($pending, 'password_hash'))['algo'])) {
                    $step = 'form';
                    $errors[] = 'اطلاعات ثبت‌نام منقضی شده است. لطفاً فرم ثبت‌نام را دوباره تکمیل کنید.';
                } elseif ($action === 'resend_otp') {
                    if (!checkBruteForce('parent_register', $verifyPhone)) {
                        $errors[] = 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
                    } else {
                        $resendAvailableAt = authResendAvailableAt($pdo, $verifyPhone, 'register');
                        if ($resendAvailableAt > time()) {
                            $errors[] = 'لطفاً پس از پایان زمان انتظار دوباره درخواست دهید.';
                        } else {
                            recordFailedAttempt('parent_register', $verifyPhone);
                            // Preserve names, password hash and session challenge on every resend.
                            $otpRes = generateAndSendSmsOtp($verifyPhone, 'register', $pending);
                            if ($otpRes['success']) {
                                $msg = 'کد تایید جدید به شماره موبایل شما ارسال شد.';
                                if (!empty($otpRes['code_debug'])) { $msg .= " (کد آزمایشی: {$otpRes['code_debug']})"; }
                                setFlash('success', $msg);
                                redirect(url('register.php?step=verify&phone=' . urlencode($verifyPhone)));
                            }
                            $errors[] = $otpRes['message'];
                        }
                    }
                } else {
                    $otpCode = authNormalizeOtp(authInput($_POST, 'otp_code'));
                    if (preg_match('/\A[0-9]{5}\z/', $otpCode) !== 1) {
                        $errors[] = $fieldErrors['otp_code'] = 'لطفاً کد تایید ۵ رقمی را وارد کنید.';
                    } elseif (!checkBruteForce('parent_register_verify', $verifyPhone)) {
                        $errors[] = 'تعداد تلاش‌ها بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
                    } else {
                        $pdo->beginTransaction();
                        $verifyResult = authVerifyPendingOtp($pdo, $verifyPhone, $otpCode, 'register', $pending);
                        if (!$verifyResult['valid']) {
                            $pdo->commit(); // Persist OTP failure counters.
                            recordFailedAttempt('parent_register_verify', $verifyPhone);
                            $errors[] = $verifyResult['message'];
                        } else {
                            // Repeat duplicate check at creation time, not just initial submission.
                            $stmt = $pdo->prepare('SELECT id FROM parents WHERE phone = :phone LIMIT 1 FOR UPDATE');
                            $stmt->execute([':phone' => $verifyPhone]);
                            if ($stmt->fetch()) {
                                $pdo->rollBack();
                                $errors[] = 'حسابی با این شماره ثبت شده است. لطفاً وارد شوید.';
                            } else {
                                $ins = $pdo->prepare("INSERT INTO parents (first_name, last_name, email, phone, phone_verified, password, status, email_verified)
                                    VALUES (:first, :last, NULL, :phone, 1, :password, 'pending', 0)");
                                $ins->execute([':first' => $pending['first_name'], ':last' => $pending['last_name'],
                                    ':phone' => $verifyPhone, ':password' => $pending['password_hash']]);
                                $newParentId = (int) $pdo->lastInsertId();
                                $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1 WHERE phone = :phone AND action_type = 'register'")
                                    ->execute([':phone' => $verifyPhone]);
                                $pdo->commit();
                                unset($_SESSION['registration_pending']);
                                resetLoginAttempts('parent_register_verify', $verifyPhone);
                                recordAudit('auth.register', 'parent', $newParentId);
                                // Optional welcome failure must not make a committed signup look failed.
                                try {
                                    $settings = getSmsSettings($pdo);
                                    $bodyId = (int) ($settings['sms_pattern_welcome'] ?? 0);
                                    if ($bodyId > 0 && ($settings['sms_enabled'] ?? '0') === '1') {
                                        sendMeliPayamakPattern($verifyPhone, $bodyId,
                                            [$pending['first_name'] . ' ' . $pending['last_name']], 'welcome', $newParentId);
                                    }
                                } catch (Throwable $e) { error_log('Welcome SMS failed: ' . $e->getMessage()); }
                                setFlash('success', 'ثبت‌نام موفق بود. حساب شما پس از تایید مدیریت فعال خواهد شد.');
                                redirect(url('login.php'));
                            }
                        }
                    }
                }
            } else { $errors[] = 'درخواست نامعتبر است.'; }
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Registration failed: ' . $e->getMessage());
            $errors[] = 'ثبت‌نام موقتاً در دسترس نیست. لطفاً دوباره تلاش کنید.';
        }
    }
}
if ($step === 'verify' && authPending('registration_pending', $verifyPhone) === null) {
    $step = 'form';
    $errors[] = 'اطلاعات ثبت‌نام منقضی شده است. لطفاً دوباره ثبت‌نام کنید.';
}
if ($pdo instanceof PDO && isValidIranianMobile($verifyPhone)) {
    try { $resendAvailableAt = authResendAvailableAt($pdo, $verifyPhone, 'register'); }
    catch (Throwable $e) { error_log('Registration cooldown unavailable: ' . $e->getMessage()); }
}
$successFlash = getFlash('success');
$pageTitle = $step === 'verify' ? 'تایید شماره موبایل' : 'ثبت‌نام والدین | ' . siteName();
$authMode = 'register';
require __DIR__ . '/templates/unified-auth-page.php';
