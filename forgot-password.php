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

$type = strtolower(trim((string) ($_GET['type'] ?? $_POST['type'] ?? 'parent')));
if (!in_array($type, ['parent', 'teacher'], true)) {
    $type = 'parent';
}

$error = '';
$infoMessage = '';

if (!checkBruteForce('password_reset_request')) {
    $error = 'تعداد درخواست‌های ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
}

if (isPostRequest() && $error === '') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $email     = trim((string) ($_POST['email'] ?? ''));

    if (!validateCsrfToken($csrfToken)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif ($email === '' || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'لطفاً یک آدرس ایمیل معتبر وارد کنید.';
    } elseif (!checkBruteForce('password_reset_request', $email)) {
        $error = 'تعداد درخواست‌های ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
    } else {
        try {
            initializeFinancialTables(); // Ensures password_reset_tokens table exists
            $pdo = getDb();

            $account = null;

            if ($type === 'parent') {
                $stmt = $pdo->prepare('SELECT id, first_name, last_name, status FROM parents WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $account = $stmt->fetch();
            } else {
                initializeTeachersTables();
                $stmt = $pdo->prepare('SELECT id, first_name, last_name, status FROM teachers WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $account = $stmt->fetch();
            }

            if ($account && (string) $account['status'] === 'active') {
                $accountId = (int) $account['id'];
                $token = bin2hex(random_bytes(32)); // 64 chars
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $insStmt = $pdo->prepare(
                    'INSERT INTO password_reset_tokens (token, account_type, account_id, expires_at)
                     VALUES (:token, :atype, :aid, :exp)'
                );
                $insStmt->execute([
                    ':token' => $token,
                    ':atype' => $type,
                    ':aid'   => $accountId,
                    ':exp'   => $expiresAt,
                ]);

                recordAudit('auth.password_reset_request', $type, $accountId, ['email' => $email]);

                // Layer 1 (Best effort mail sending)
                if (function_exists('mail')) {
                    $resetUrl = url('reset-password.php?token=' . urlencode($token));
                    $subject = 'بازیابی رمز عبور مهدکودک ' . siteName();
                    $message = "سلام " . trim($account['first_name'] . ' ' . $account['last_name']) . " عزیز،\n\n"
                        . "درخواستی برای بازیابی رمز عبور حساب شما ثبت شده است.\n"
                        . "برای تنظیم رمز عبور جدید روی لینک زیر کلیک کنید (اعتبار: ۱ ساعت):\n"
                        . $resetUrl . "\n\n"
                        . "اگر شما این درخواست را نداده‌اید، نیاز به هیچ اقدامی نیست.\n\n"
                        . siteName();

                    $headers = "From: " . siteContactEmail() . "\r\n"
                        . "Content-Type: text/plain; charset=UTF-8\r\n";

                    @mail($email, $subject, $message, $headers);
                }
            } else {
                // Record failed attempt for brute-force tracking if account not found/inactive
                recordFailedAttempt('password_reset_request', $email);
            }

            // UNIFORM MESSAGE for privacy (User Enumeration prevention)
            $infoMessage = 'اگر این ایمیل در سیستم ثبت باشد، لینک بازیابی رمز عبور برای آن ارسال یا آماده گردید.';

        } catch (Throwable $e) {
            error_log($e->getMessage());
            $error = 'بررسی درخواست با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
        }
    }
}

$pageTitle = 'فراموشی رمز عبور | ' . siteName();
require_once __DIR__ . '/templates/header.php';
?>

<div class="auth-container" style="max-width: 480px; margin: 40px auto; padding: 0 16px;">
    <div class="card" style="padding: 28px;">
        <h1 style="font-size: 1.4rem; font-weight: 700; text-align: center; margin-bottom: 8px;">بازیابی رمز عبور 🔑</h1>
        <p class="muted" style="text-align: center; font-size: 0.88rem; margin-bottom: 24px;">آدرس ایمیل ثبت‌شده در حساب کاربری خود را وارد کنید</p>

        <!-- Account type tabs -->
        <div style="display: flex; border-bottom: 2px solid var(--adm-border-light, #e2e8f0); margin-bottom: 20px;">
            <a href="<?= e(url('forgot-password.php?type=parent')) ?>"
               style="flex: 1; text-align: center; padding: 10px; text-decoration: none; font-weight: 600; color: <?= $type === 'parent' ? 'var(--adm-primary, #3182ce)' : 'var(--adm-text-muted)' ?>; border-bottom: 2px solid <?= $type === 'parent' ? 'var(--adm-primary, #3182ce)' : 'transparent' ?>; margin-bottom: -2px;">
               والدین
            </a>
            <a href="<?= e(url('forgot-password.php?type=teacher')) ?>"
               style="flex: 1; text-align: center; padding: 10px; text-decoration: none; font-weight: 600; color: <?= $type === 'teacher' ? 'var(--adm-primary, #3182ce)' : 'var(--adm-text-muted)' ?>; border-bottom: 2px solid <?= $type === 'teacher' ? 'var(--adm-primary, #3182ce)' : 'transparent' ?>; margin-bottom: -2px;">
               معلمان
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert" style="margin-bottom: 20px;"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($infoMessage !== ''): ?>
            <div class="notice" role="status" style="margin-bottom: 20px; text-align: center; line-height: 1.6;"><?= e($infoMessage) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('forgot-password.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">

            <div class="form-group" style="margin-bottom: 20px;">
                <label for="email" class="form-label" style="display: block; margin-bottom: 6px; font-weight: 600;">ایمیل حساب <?= $type === 'parent' ? 'والد' : 'معلم' ?></label>
                <input type="email" id="email" name="email" class="form-control" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--adm-border, #cbd5e0);" placeholder="name@example.com" required maxlength="150">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem;">
                ارسال لینک بازیابی رمز
            </button>
        </form>

        <div style="margin-top: 20px; text-align: center; font-size: 0.88rem; border-top: 1px solid var(--adm-border-light, #e2e8f0); padding-top: 16px;">
            <a href="<?= e($type === 'teacher' ? url('teacher/login.php') : url('login.php')) ?>" style="color: var(--adm-primary, #3182ce); text-decoration: none;">
                ← بازگشت به صفحه ورود
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
