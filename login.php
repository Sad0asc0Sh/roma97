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

$error = getFlash('error') ?? '';
$successMessage = getFlash('success');
$phone = '';
$fieldErrors = [];
$pdo = null;
// اتصال تنبل به ذخیره‌سازی: نمایش فرم (GET) نیازی به DB ندارد؛ فقط هنگام POST
// وصل شو تا ناوبری بین تب‌های ورود/ثبت‌نام سبک بماند. پیام‌ها و منطق ورود بدون تغییر است.
if (isPostRequest()) {
    try {
        initializeFinancialTables();
        $pdo = getDb();
    } catch (Throwable $exception) {
        error_log('Login storage unavailable: ' . $exception->getMessage());
        $error = 'سامانه ورود موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
    }
}

if (isPostRequest()) {
    $error = '';
    $phone = normalizeIranianPhone(trim(authInput($_POST, 'phone')));
    $password = authInput($_POST, 'password');
    if (!validateCsrfToken(authInput($_POST, 'csrf_token'))) {
        $error = 'زمان اعتبار فرم تمام شده است. لطفاً دوباره تلاش کنید.';
    } elseif (!isValidIranianMobile($phone)) {
        $fieldErrors['phone'] = 'شماره موبایل معتبر وارد کنید؛ مانند 09123456789.';
    } elseif ($password === '' || strlen($password) > 4096 || str_contains($password, "\0")) {
        $fieldErrors['password'] = 'رمز عبور خود را وارد کنید.';
    } elseif ($pdo === null) {
        $error = 'سامانه ورود موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
    } else {
        try {
            if (!checkBruteForce('parent_login', $phone)) {
                $error = 'تعداد تلاش‌ها بیش از حد مجاز است. ۱۵ دقیقه دیگر تلاش کنید.';
            } else {
                $statement = $pdo->prepare('SELECT id, first_name, last_name, phone, password, status FROM parents WHERE phone = :phone LIMIT 1');
                $statement->execute([':phone' => $phone]);
                $parent = $statement->fetch();
                if (!$parent || !password_verify($password, (string) $parent['password'])) {
                    recordFailedAttempt('parent_login', $phone);
                    $error = 'شماره موبایل یا رمز عبور نادرست است. اطلاعات را بررسی کنید یا رمز را بازیابی کنید.';
                } elseif ($parent['status'] === 'pending') {
                    $error = 'ثبت‌نام شما دریافت شده و منتظر تأیید مدیریت مهدکودک است. پس از فعال‌سازی می‌توانید وارد شوید.';
                } elseif ($parent['status'] !== 'active') {
                    $error = 'حساب شما فعال نیست. برای پیگیری با مدیریت مهد تماس بگیرید.';
                } else {
                    resetLoginAttempts('parent_login', $phone);
                    session_regenerate_id(true);
                    $_SESSION['last_activity'] = time();
                    $_SESSION['parent_id'] = (int) $parent['id'];
                    $_SESSION['parent_name'] = trim($parent['first_name'] . ' ' . $parent['last_name']);
                    $_SESSION['parent_first_name'] = (string) $parent['first_name'];
                    recordAudit('auth.login', 'parent', (int) $parent['id']);
                    rotateCsrfToken();
                    redirect(url('parent/index.php'));
                }
            }
        } catch (Throwable $exception) {
            error_log('Login failed: ' . $exception->getMessage());
            $error = 'ورود موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
        }
    }
}

$authMode = 'login';
$pageTitle = 'ورود به پنل والدین | ' . siteName();
require __DIR__ . '/templates/auth-page.php';
