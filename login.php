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

$error = '';
$successMessage = getFlash('success');
$email = '';

// Check brute-force lockout before any DB work
if (!checkBruteForce('parent_login')) {
    $error = 'تعداد تلاش‌های ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
}

try {
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $pdo = null;
}

if (isPostRequest() && $error === '') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (
        $pdo === null
        || $email === ''
        || strlen($email) > 150
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $password === ''
    ) {
        $error = 'ایمیل یا رمز عبور نادرست است.';
    } elseif (!checkBruteForce('parent_login', $email)) {
        // Per-account lockout, independent of the IP-based pre-check above.
        $error = 'تعداد تلاش‌های ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT id, first_name, last_name, email, password, status FROM parents WHERE email = :email LIMIT 1'
            );
            $statement->execute([':email' => $email]);
            $parent = $statement->fetch();

            if (!$parent || !password_verify($password, $parent['password'])) {
                $error = 'ایمیل یا رمز عبور نادرست است.';
                recordFailedAttempt('parent_login', $email);
            } elseif ($parent['status'] === 'pending') {
                $error = 'حساب شما در انتظار تأیید است. لطفاً منتظر تأیید مدیر بمانید.';
            } elseif ($parent['status'] === 'suspended') {
                $error = 'حساب شما مسدود شده است. با ما تماس بگیرید.';
            } elseif ($parent['status'] !== 'active') {
                $error = 'ایمیل یا رمز عبور نادرست است.';
                recordFailedAttempt('parent_login', $email);
            } else {
                // Successful login — reset brute-force counter
                resetLoginAttempts('parent_login', $email);

                session_regenerate_id(true);
                $_SESSION['parent_id'] = (int) $parent['id'];
                recordAudit('auth.login', 'parent', (int) $parent['id']);
                $_SESSION['parent_name'] = trim($parent['first_name'] . ' ' . $parent['last_name']);
                $_SESSION['parent_first_name'] = (string) $parent['first_name'];

                redirect(url('parent/index.php'));
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'ورود موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.';
        }
    }
}

$pageTitle = 'ورود والدین | ' . siteName();
$pageDescription = 'ورود به پنل والدین مهد کودک ' . siteName();
require_once __DIR__ . '/templates/header.php';
?>

<section class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-card-header">
                <div class="auth-logo">
                    <span class="auth-logo-icon">🌸</span>
                    <span class="auth-logo-text"><?= e(siteName()) ?></span>
                </div>
                <h1>ورود به پنل والدین</h1>
                <p class="auth-subtitle">لطفاً برای دسترسی به حساب خود وارد شوید</p>
            </div>

            <?php if ($successMessage !== null): ?>
                <div class="alert alert-success" role="status">
                    <span class="alert-icon">✅</span>
                    <span><?= e($successMessage) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <span class="alert-icon">⚠️</span>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('login.php')) ?>" novalidate class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="form-group">
                    <label for="email" class="form-label">آدرس ایمیل</label>
                    <div class="input-wrapper">
                        <span class="input-icon">📧</span>
                        <input type="email" id="email" name="email" maxlength="150" value="<?= e($email) ?>" placeholder="example@email.com" autocomplete="email" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">رمز عبور</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="password" name="password" placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password" required>
                        <button type="button" class="toggle-password" aria-label="نمایش رمز عبور" data-toggle="password">👁️</button>
                    </div>
                </div>

                <button class="btn btn-primary btn-block btn-lg" type="submit">ورود به حساب</button>
            </form>

            <div class="auth-divider">
                <span>یا</span>
            </div>

            <div class="auth-links">
                <a href="<?= e(url('register.php')) ?>" class="btn btn-outline btn-block">ساخت حساب جدید</a>
            </div>

            <div class="auth-back">
                <a href="<?= e(url('index.php')) ?>">← بازگشت به صفحه اصلی</a>
            </div>
        </div>

        <div class="auth-info">
            <div class="auth-info-content">
                <h2>به خانواده ما خوش آمدید</h2>
                <p>از طریق پنل والدین می‌توانید:</p>
                <ul class="auth-features">
                    <li>وضعیت حضور فرزندتان را پیگیری کنید</li>
                    <li>گزارش‌های روزانه را مشاهده کنید</li>
                    <li>از اخبار و رویدادها مطلع شوید</li>
                    <li>با مربیان ارتباط برقرار کنید</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
