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
    $errors[] = 'ثبت‌نام موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.';
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

    if ($pdo === null) {
        $errors[] = 'ثبت‌نام موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.';
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

            setFlash('success', 'ثبت‌نام شما با موفقیت انجام شد. منتظر تأیید مدیر بمانید.');
            redirect(url('login.php'));
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $errors[] = 'ثبت‌نام با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
        }
    }
}

$pageTitle = 'ثبت‌نام والدین | ' . siteName();
$pageDescription = 'ثبت‌نام در پنل والدین مهد کودک ' . siteName();
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
                <h1>ثبت‌نام والدین</h1>
                <p class="auth-subtitle">برای پیگیری وضعیت فرزندتان حساب بسازید</p>
            </div>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <span class="alert-icon">⚠️</span>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <p><?= e($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('register.php')) ?>" novalidate class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="first_name" class="form-label">نام</label>
                        <div class="input-wrapper">
                            <span class="input-icon">👤</span>
                            <input type="text" id="first_name" name="first_name" maxlength="100" value="<?= e($old['first_name']) ?>" placeholder="نام شما" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="last_name" class="form-label">نام خانوادگی</label>
                        <div class="input-wrapper">
                            <span class="input-icon">👤</span>
                            <input type="text" id="last_name" name="last_name" maxlength="100" value="<?= e($old['last_name']) ?>" placeholder="نام خانوادگی" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">آدرس ایمیل</label>
                    <div class="input-wrapper">
                        <span class="input-icon">📧</span>
                        <input type="email" id="email" name="email" maxlength="150" value="<?= e($old['email']) ?>" placeholder="example@email.com" autocomplete="email" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">شماره تماس <span class="label-optional">(اختیاری)</span></label>
                    <div class="input-wrapper">
                        <span class="input-icon">📱</span>
                        <input type="tel" id="phone" name="phone" maxlength="15" value="<?= e($old['phone']) ?>" placeholder="۰۹۱۲۳۴۵۶۷۸۹" autocomplete="tel">
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="password" class="form-label">رمز عبور</label>
                        <div class="input-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" id="password" name="password" minlength="8" placeholder="حداقل ۸ کاراکتر" autocomplete="new-password" required>
                            <button type="button" class="toggle-password" aria-label="نمایش رمز عبور" data-toggle="password">👁️</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">تکرار رمز عبور</label>
                        <div class="input-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="تکرار رمز عبور" autocomplete="new-password" required>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary btn-block btn-lg" type="submit">ثبت‌نام</button>
            </form>

            <div class="auth-divider">
                <span>یا</span>
            </div>

            <div class="auth-links">
                <a href="<?= e(url('login.php')) ?>" class="btn btn-outline btn-block">قبلاً ثبت‌نام کرده‌اید؟ وارد شوید</a>
            </div>

            <div class="auth-back">
                <a href="<?= e(url('index.php')) ?>">← بازگشت به صفحه اصلی</a>
            </div>
        </div>

        <div class="auth-info">
            <div class="auth-info-content">
                <h2>چرا به ما بپیوندید؟</h2>
                <ul class="auth-features">
                    <li>پیگیری لحظه‌ای وضعیت حضور فرزندتان</li>
                    <li>مشاهده گزارش‌های روزانه مربیان</li>
                    <li>اطلاع از آخرین اخبار و رویدادها</li>
                    <li>ارتباط مستقیم با مربیان</li>
                    <li>مشاهده وضعیت پرداخت شهریه</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
