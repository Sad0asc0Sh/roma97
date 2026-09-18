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

$recoveryType = authInput($_GET, 'type', authInput($_POST, 'type', 'parent'));
$teacherRecovery = $recoveryType === 'teacher';
$step = authInput($_GET, 'step') === 'verify' ? 'verify' : 'request';
$phone = normalizeIranianPhone(authInput($_GET, 'phone'));
$resendAvailableAt = 0;
$error = '';
$fieldErrors = [];
$action = authInput($_POST, 'action', 'request_otp');
// Preserve the submitted phone and intended step even when CSRF/storage validation fails.
if (isPostRequest()) {
    $phone = normalizeIranianPhone(authInput($_POST, 'phone'));
    $step = in_array($action, ['resend_otp', 'set_new_password'], true) ? 'verify' : 'request';
}
$successMessage = getFlash('success');
$pdo = null;
if ($recoveryType !== 'parent') {
    $step = 'request';
    $error = $teacherRecovery
        ? 'برای بازیابی رمز مربی از مدیریت مهد درخواست لینک بازیابی کنید.'
        : 'نوع حساب نامعتبر است.';
} elseif (isPostRequest() || isValidIranianMobile($phone)) {
    // اتصال تنبل: نمایش خالی فرم بازیابی (GET بدون شماره) نیازی به DB ندارد.
    try {
        initializeFinancialTables();
        $pdo = getDb();
    } catch (Throwable $e) {
        error_log('Recovery storage unavailable: ' . $e->getMessage());
        $error = 'بازیابی رمز موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.';
    }
}

if (isPostRequest() && $error === '' && $pdo instanceof PDO) {
    if (!validateCsrfToken(authInput($_POST, 'csrf_token'))) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } else {
        try {
            $phone = normalizeIranianPhone(authInput($_POST, 'phone'));
            if (!isValidIranianMobile($phone)) {
                $error = $fieldErrors['phone'] = 'لطفاً شماره موبایل معتبر وارد کنید.';
            } elseif (in_array($action, ['request_otp', 'resend_otp'], true)) {
                $pending = authPending('password_reset_pending', $phone);
                $isResend = $action === 'resend_otp';
                if ($isResend && $pending === null) {
                    $step = 'request';
                    $error = 'درخواست بازیابی منقضی شده است. لطفاً دوباره شروع کنید.';
                } elseif (!checkBruteForce('parent_pwd_reset', $phone)) {
                    $error = 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
                } else {
                    $resendAvailableAt = authResendAvailableAt($pdo, $phone, 'reset_password');
                    if ($resendAvailableAt > time()) {
                        $error = 'لطفاً پس از پایان زمان انتظار دوباره درخواست دهید.';
                    } else {
                        recordFailedAttempt('parent_pwd_reset', $phone); // Include successful sends in the quota.
                        // Ambiguous legacy phone numbers must never reset multiple accounts.
                        $stmt = $pdo->prepare('SELECT id, status FROM parents WHERE phone = :phone LIMIT 2');
                        $stmt->execute([':phone' => $phone]);
                        $parents = $stmt->fetchAll();
                        if (count($parents) !== 1 || !in_array($parents[0]['status'], ['active', 'pending'], true)) {
                            $error = 'امکان بازیابی با این مشخصات وجود ندارد. با مدیریت مهد تماس بگیرید.';
                        } elseif ($isResend && (int) $pending['parent_id'] !== (int) $parents[0]['id']) {
                            unset($_SESSION['password_reset_pending']);
                            $step = 'request';
                            $error = 'اطلاعات حساب تغییر کرده است. لطفاً بازیابی را دوباره شروع کنید.';
                        } else {
                            if (!$isResend) {
                                $pending = ['phone' => $phone, 'parent_id' => (int) $parents[0]['id'],
                                    'challenge' => bin2hex(random_bytes(32)), 'expires_at' => time() + 1800];
                            }
                            $_SESSION['password_reset_pending'] = $pending;
                            $step = 'verify'; // A failed gateway response must not discard the retry state.
                            $otpRes = generateAndSendSmsOtp($phone, 'reset_password', $pending);
                            if ($otpRes['success']) {
                                $msg = 'کد تایید ۵ رقمی بازیابی به شماره موبایل شما ارسال شد.';
                                if (!empty($otpRes['code_debug'])) { $msg .= " (کد آزمایشی: {$otpRes['code_debug']})"; }
                                setFlash('success', $msg);
                                redirect(url('forgot-password.php?step=verify&phone=' . urlencode($phone)));
                            }
                            $error = $otpRes['message'];
                        }
                    }
                }
            } elseif ($action === 'set_new_password') {
                $step = 'verify';
                $pending = authPending('password_reset_pending', $phone);
                $otpCode = authNormalizeOtp(authInput($_POST, 'otp_code'));
                $newPassword = authInput($_POST, 'new_password');
                if ($pending === null) {
                    $step = 'request';
                    $error = 'درخواست بازیابی منقضی شده است. لطفاً دوباره شروع کنید.';
                } elseif (preg_match('/\A[0-9]{5}\z/', $otpCode) !== 1) {
                    $error = $fieldErrors['otp_code'] = 'لطفاً کد تایید ۵ رقمی را وارد کنید.';
                } elseif (!authStrongPassword($newPassword)) {
                    $error = $fieldErrors['new_password'] = 'رمز عبور باید ۸ تا ۷۲ بایت و شامل حرف انگلیسی و عدد باشد.';
                } elseif ($newPassword !== authInput($_POST, 'confirm_password')) {
                    $error = $fieldErrors['confirm_password'] = 'تکرار رمز عبور مطابقت ندارد.';
                } elseif (!checkBruteForce('parent_pwd_reset_verify', $phone)) {
                    $error = 'تعداد تلاش‌ها بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->beginTransaction();
                    $vRes = authVerifyPendingOtp($pdo, $phone, $otpCode, 'reset_password', $pending);
                    if (!$vRes['valid']) {
                        $pdo->commit(); // Keep failed OTP attempt counters.
                        recordFailedAttempt('parent_pwd_reset_verify', $phone);
                        $error = $vRes['message'];
                    } else {
                        $stmt = $pdo->prepare('SELECT id, status, phone FROM parents WHERE id = :id FOR UPDATE');
                        $stmt->execute([':id' => $pending['parent_id']]);
                        $parent = $stmt->fetch();
                        if (!$parent || $parent['phone'] !== $phone || !in_array($parent['status'], ['active', 'pending'], true)) {
                            $pdo->rollBack();
                            $error = 'اطلاعات حساب تغییر کرده است. با مدیریت مهد تماس بگیرید.';
                        } else {
                            $pdo->prepare('UPDATE parents SET password = :password WHERE id = :id')
                                ->execute([':password' => $hash, ':id' => $pending['parent_id']]);
                            $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1 WHERE phone = :phone AND action_type = 'reset_password'")
                                ->execute([':phone' => $phone]);
                            $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE account_type = 'parent' AND account_id = :id")
                                ->execute([':id' => $pending['parent_id']]);
                            $pdo->commit();
                            unset($_SESSION['password_reset_pending']);
                            resetLoginAttempts('parent_pwd_reset_verify', $phone);
                            recordAudit('auth.password_reset_success', 'parent', (int) $pending['parent_id']);
                            setFlash('success', 'رمز عبور با موفقیت تغییر یافت. اکنون می‌توانید وارد شوید.');
                            redirect(url('login.php'));
                        }
                    }
                }
            } else { $error = 'درخواست نامعتبر است.'; }
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Password recovery failed: ' . $e->getMessage());
            $error = 'بازیابی رمز موقتاً در دسترس نیست. لطفاً دوباره تلاش کنید.';
        }
    }
}
if ($step === 'verify' && authPending('password_reset_pending', $phone) === null) {
    $step = 'request';
    $error = 'درخواست بازیابی منقضی شده است. لطفاً دوباره شروع کنید.';
}
if ($pdo instanceof PDO && isValidIranianMobile($phone)) {
    try { $resendAvailableAt = authResendAvailableAt($pdo, $phone, 'reset_password'); }
    catch (Throwable $e) { error_log('Recovery cooldown unavailable: ' . $e->getMessage()); }
}
$pageTitle = 'بازیابی رمز عبور | ' . siteName();
$authMode = 'forgot';
require __DIR__ . '/templates/auth-page.php';
