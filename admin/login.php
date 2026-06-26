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

// Silently ensure the admins table exists on every login page load
try {
    initializeDatabase();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $setupUnavailable = true;
}

// Check for brute-force lockout
$lockedOut = !checkBruteForce('admin_login');

if ($lockedOut) {
    $error = 'تعداد تلاش‌های ناموفق برای ورود زیاد است. لطفاً ۱۵ دقیقه صبر کنید و دوباره تلاش کنید.';
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
        // Per-account lockout, independent of the IP-based pre-check above —
        // protects a single account from being brute-forced across many IPs.
        $error = 'تعداد تلاش‌های ناموفق برای ورود زیاد است. لطفاً ۱۵ دقیقه صبر کنید و دوباره تلاش کنید.';
    } else {
        try {
            $pdo = getDb();
            $statement = $pdo->prepare(
                'SELECT id, username, password FROM admins WHERE username = :username LIMIT 1'
            );
            $statement->execute([':username' => $username]);
            $admin = $statement->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                // Reset login attempts on successful login
                resetLoginAttempts('admin_login', $username);

                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                recordAudit('auth.login', 'admin', (int) $admin['id']);

                redirect(url('admin/index.php'));
            }

            $error = 'نام کاربری یا رمز عبور نامعتبر است.';

            // Record failed attempt
            recordFailedAttempt('admin_login', $username);
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'ورود موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
        }
    }
}

$pageTitle = 'ورود مدیر | ' . e(siteName());
require_once __DIR__ . '/../templates/header.php';
?>

<section class="auth-panel">
    <h1>ورود مدیر</h1>

    <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="form-card" method="post" action="<?= e(url('admin/login.php')) ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

        <label for="username">نام کاربری</label>
        <input
            type="text"
            id="username"
            name="username"
            maxlength="50"
            autocomplete="username"
            required
        >

        <label for="password">رمز عبور</label>
        <input
            type="password"
            id="password"
            name="password"
            maxlength="255"
            autocomplete="current-password"
            required
        >

        <button type="submit">ورود</button>
    </form>
</section>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
