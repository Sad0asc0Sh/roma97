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

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if (isLoggedIn()) { redirect(url('admin/index.php')); }
if (isParentLoggedIn()) { redirect(url('parent/index.php')); }
if (isTeacherLoggedIn()) { redirect(url('teacher/index.php')); }

function isStrongPassword(string $password): bool
{
    return authStrongPassword($password);
}

$rawToken = trim(isPostRequest()
    ? authInput($_POST, 'token', authInput($_GET, 'token'))
    : authInput($_GET, 'token'));
$error = '';
$fieldErrors = [];
$tokenValid = false;
$pdo = null;
$invalidTokenMessage = 'لینک بازیابی رمز عبور نامعتبر، منقضی یا قبلاً استفاده شده است.';

if (preg_match('/\A[a-f0-9]{64}\z/', $rawToken) !== 1) {
    $error = $invalidTokenMessage;
} else {
    try {
        // Run runtime schema initialization before any token/account transaction.
        initializeFinancialTables();
        $pdo = getDb();
        $checkStmt = $pdo->prepare('SELECT id, account_type, account_id
            FROM password_reset_tokens
            WHERE token = :token AND used = 0 AND expires_at > NOW() LIMIT 1');
        $checkStmt->execute([':token' => $rawToken]);
        $tokenRow = $checkStmt->fetch();
        $tokenValid = $tokenRow !== false
            && in_array($tokenRow['account_type'], ['parent', 'teacher'], true)
            && (int) $tokenRow['account_id'] > 0;

        if (!$tokenValid) {
            $error = $invalidTokenMessage;
        } elseif (isPostRequest()) {
            $password = authInput($_POST, 'password');
            $confirmPassword = authInput($_POST, 'confirm_password');
            if (!validateCsrfToken(authInput($_POST, 'csrf_token'))) {
                $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
            } elseif (!isStrongPassword($password)) {
                $error = $fieldErrors['password'] = 'رمز عبور باید ۸ تا ۷۲ بایت و شامل حرف انگلیسی و عدد باشد.';
            } elseif ($password !== $confirmPassword) {
                $error = $fieldErrors['confirm_password'] = 'تکرار رمز عبور جدید مطابقت ندارد.';
            } else {
                $hashedPw = password_hash($password, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                // Re-read under lock: the display-time read is not authority to consume a token.
                $lockedStmt = $pdo->prepare('SELECT id, account_type, account_id
                    FROM password_reset_tokens
                    WHERE token = :token AND used = 0 AND expires_at > NOW() LIMIT 1 FOR UPDATE');
                $lockedStmt->execute([':token' => $rawToken]);
                $lockedToken = $lockedStmt->fetch();
                if (!$lockedToken || !in_array($lockedToken['account_type'], ['parent', 'teacher'], true)
                    || (int) $lockedToken['account_id'] <= 0) {
                    $pdo->rollBack();
                    $tokenValid = false;
                    $error = $invalidTokenMessage;
                } else {
                    $accountType = $lockedToken['account_type'];
                    $accountId = (int) $lockedToken['account_id'];
                    // Only these two literal tables may be addressed by a recovery token.
                    $table = $accountType === 'parent' ? 'parents' : 'teachers';
                    $accountStmt = $pdo->prepare("SELECT id, phone FROM {$table} WHERE id = :id FOR UPDATE");
                    $accountStmt->execute([':id' => $accountId]);
                    $account = $accountStmt->fetch();
                    if (!$account) {
                        $pdo->rollBack();
                        $tokenValid = false;
                        $error = $invalidTokenMessage;
                    } else {
                        $claim = $pdo->prepare('UPDATE password_reset_tokens SET used = 1
                            WHERE id = :id AND used = 0 AND expires_at > NOW()');
                        $claim->execute([':id' => $lockedToken['id']]);
                        if ($claim->rowCount() !== 1) {
                            $pdo->rollBack();
                            $tokenValid = false;
                            $error = $invalidTokenMessage;
                        } else {
                            // Claim, password update, and sibling-token invalidation commit together.
                            // Approval/suspension state is intentionally unchanged.
                            $updateStmt = $pdo->prepare("UPDATE {$table} SET password = :password WHERE id = :id");
                            $updateStmt->execute([':password' => $hashedPw, ':id' => $accountId]);
                            $pdo->prepare('UPDATE password_reset_tokens SET used = 1
                                WHERE account_type = :type AND account_id = :id AND used = 0')
                                ->execute([':type' => $accountType, ':id' => $accountId]);
                            if ($accountType === 'parent') {
                                $phone = normalizeIranianPhone(authInput($account, 'phone'));
                                if (isValidIranianMobile($phone)) {
                                    $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1
                                        WHERE phone = :phone AND action_type = 'reset_password' AND is_used = 0")
                                        ->execute([':phone' => $phone]);
                                }
                            }
                            $pdo->commit();
                            if ($accountType === 'parent') { unset($_SESSION['password_reset_pending']); }
                            recordAudit('auth.password_reset', $accountType, $accountId);
                            setFlash('success', 'رمز عبور با موفقیت تغییر یافت. اکنون می‌توانید وارد شوید.');
                            redirect(url($accountType === 'parent' ? 'login.php' : 'teacher/login.php'));
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Password token reset failed: ' . $e->getMessage());
        $error = 'بازیابی رمز موقتاً در دسترس نیست. لطفاً دوباره تلاش کنید.';
    }
}

$pageTitle = 'تنظیم رمز عبور جدید | ' . siteName();
$authMode = 'reset';
require __DIR__ . '/templates/auth-page.php';
