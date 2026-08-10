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
if (isTeacherLoggedIn()) {
    redirect(url('teacher/index.php'));
}

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

$rawToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

if ($rawToken === '' || strlen($rawToken) > 64) {
    setFlash('error', 'لینک بازیابی رمز عبور نامعتبر یا منقضی شده است.');
    redirect(url('login.php'));
}

$error = '';
$tokenValid = false;
$tokenRow = null;

try {
    initializeFinancialTables();
    $pdo = getDb();

    // Check token existence and validity first
    $checkStmt = $pdo->prepare(
        'SELECT id, account_type, account_id, expires_at, used
         FROM password_reset_tokens
         WHERE token = :token AND used = 0 AND expires_at > NOW()
         LIMIT 1'
    );
    $checkStmt->execute([':token' => $rawToken]);
    $tokenRow = $checkStmt->fetch();

    if ($tokenRow) {
        $tokenValid = true;
    } else {
        $error = 'لینک بازیابی رمز عبور نامعتبر است یا منقضی (۱ ساعت) و استفاده شده است.';
    }

    if (isPostRequest() && $tokenValid) {
        $csrfToken       = (string) ($_POST['csrf_token'] ?? '');
        $password        = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!validateCsrfToken($csrfToken)) {
            $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
        } elseif (!isStrongPassword($password)) {
            $error = 'رمز عبور جدید باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.';
        } elseif ($password !== $confirmPassword) {
            $error = 'تکرار رمز عبور جدید مطابقت ندارد.';
        } else {
            // ATOMIC CLAIM: UPDATE used = 1 WHERE token = ? AND used = 0 AND expires_at > NOW()
            $claim = $pdo->prepare(
                'UPDATE password_reset_tokens
                 SET used = 1
                 WHERE token = :token AND used = 0 AND expires_at > NOW()'
            );
            $claim->execute([':token' => $rawToken]);

            if ($claim->rowCount() === 0) {
                $error = 'این لینک قبلاً استفاده شده یا منقضی شده است.';
                $tokenValid = false;
            } else {
                $accountType = (string) $tokenRow['account_type'];
                $accountId   = (int) $tokenRow['account_id'];
                $hashedPw    = password_hash($password, PASSWORD_DEFAULT);

                if ($accountType === 'parent') {
                    $updateStmt = $pdo->prepare('UPDATE parents SET password = :pw WHERE id = :id');
                    $updateStmt->execute([':pw' => $hashedPw, ':id' => $accountId]);
                    recordAudit('auth.password_reset', 'parent', $accountId);
                    setFlash('success', 'رمز عبور با موفقیت تغییر یافت. اکنون می‌توانید وارد شوید.');
                    redirect(url('login.php'));
                } else {
                    $updateStmt = $pdo->prepare('UPDATE teachers SET password = :pw WHERE id = :id');
                    $updateStmt->execute([':pw' => $hashedPw, ':id' => $accountId]);
                    recordAudit('auth.password_reset', 'teacher', $accountId);
                    setFlash('success', 'رمز عبور با موفقیت تغییر یافت. اکنون می‌توانید وارد شوید.');
                    redirect(url('teacher/login.php'));
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    $error = 'خطایی در پردازش بازیابی رمز عبور رخ داد.';
}

$pageTitle = 'تنظیم رمز عبور جدید | ' . siteName();
require_once __DIR__ . '/templates/header.php';
?>

<div class="auth-container" style="max-width: 480px; margin: 40px auto; padding: 0 16px;">
    <div class="card" style="padding: 28px;">
        <h1 style="font-size: 1.4rem; font-weight: 700; text-align: center; margin-bottom: 8px;">تنظیم رمز عبور جدید 🔑</h1>
        <p class="muted" style="text-align: center; font-size: 0.88rem; margin-bottom: 24px;">لطفاً رمز عبور جدید خود را وارد کنید</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert" style="margin-bottom: 20px;"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($tokenValid): ?>
            <form method="post" action="<?= e(url('reset-password.php?token=' . urlencode($rawToken))) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="token" value="<?= e($rawToken) ?>">

                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="password" class="form-label" style="display: block; margin-bottom: 6px; font-weight: 600;">رمز عبور جدید</label>
                    <input type="password" id="password" name="password" class="form-control" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--adm-border, #cbd5e0);" required minlength="8" autocomplete="new-password">
                    <small class="muted">حداقل ۸ کاراکتر، شامل حرف و عدد</small>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="confirm_password" class="form-label" style="display: block; margin-bottom: 6px; font-weight: 600;">تکرار رمز عبور جدید</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--adm-border, #cbd5e0);" required minlength="8">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem;">
                    تغییر و ذخیره رمز عبور
                </button>
            </form>
        <?php else: ?>
            <div style="text-align: center; margin-top: 20px;">
                <a href="<?= e(url('forgot-password.php')) ?>" class="btn btn-secondary">ارسال مجدد لینک بازیابی</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
