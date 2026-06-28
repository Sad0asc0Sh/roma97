<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

function settingsStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function getSettings(PDO $pdo): array
{
    $settings = [
        'site_name' => 'مهد کودک روما',
        'site_description' => 'به مهد کودک روما خوش آمدید',
        'logo' => '',
        'contact_phone' => '+98 21 1234 5678',
    ];

    $statement = $pdo->prepare(
        'SELECT meta_key, meta_value FROM settings WHERE meta_key IN (:site_name, :site_description, :logo, :contact_phone)'
    );
    $statement->execute([
        ':site_name' => 'site_name',
        ':site_description' => 'site_description',
        ':logo' => 'logo',
        ':contact_phone' => 'contact_phone',
    ]);

    while ($row = $statement->fetch()) {
        if (array_key_exists($row['meta_key'], $settings)) {
            $settings[$row['meta_key']] = (string) $row['meta_value'];
        }
    }

    return $settings;
}

function saveSetting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        "INSERT INTO settings (meta_key, meta_value) VALUES (:meta_key, :meta_value)
         ON DUPLICATE KEY UPDATE meta_value = :meta_value"
    );

    $statement->execute([
        ':meta_key' => $key,
        ':meta_value' => $value,
    ]);
}

function deleteLogoFile(string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $uploadRoot = realpath(__DIR__ . '/../assets/uploads');

    if ($projectRoot === false || $uploadRoot === false) {
        return;
    }

    $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));

    if (
        $candidate !== false
        && str_starts_with($candidate, $uploadRoot . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        @unlink($candidate);
    }
}

function handleLogoUpload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('بارگذاری لوگو نامعتبر است.');
    }

    if (($file['size'] ?? 0) > 512000) {
        throw new RuntimeException('بارگذاری لوگو نامعتبر است.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('بارگذاری لوگو نامعتبر است.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
    ];

    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('بارگذاری لوگو نامعتبر است.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);

    if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
        throw new RuntimeException('بارگذاری لوگو نامعتبر است.');
    }

    if (getimagesize($tmpName) === false) {
        throw new RuntimeException('بارگذاری لوگو نامعتبر است.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('بارگذاری لوگو در دسترس نیست.');
    }

    $fileName = 'logo-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('بارگذاری لوگو در دسترس نیست.');
    }

    return 'assets/uploads/' . $fileName;
}

$pdo = null;
$settingsAvailable = false;
$admin = null;

try {
    initializeCmsTables();
    $pdo = getDb();
    $settings = getSettings($pdo);
    $settingsAvailable = true;

    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId > 0) {
        $adminStmt = $pdo->prepare('SELECT id, password FROM admins WHERE id = :id LIMIT 1');
        $adminStmt->execute([':id' => $adminId]);
        $admin = $adminStmt->fetch() ?: null;
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'تنظیمات موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
    $settings = [
        'site_name' => 'مهد کودک روما',
        'site_description' => 'به مهد کودک روما خوش آمدید',
        'logo' => '',
        'contact_phone' => '+98 21 1234 5678',
    ];
}

function isStrongAdminPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

if (isPostRequest()) {
    $action = (string) ($_POST['action'] ?? 'update_settings');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/settings.php'));
    }

    if (!$settingsAvailable || !$pdo instanceof PDO) {
        setFlash('error', 'تنظیمات موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'change_password') {
        if ($admin === null) {
            setFlash('error', 'حساب کاربری شما پیدا نشد. لطفاً دوباره وارد شوید.');
            redirect(url('admin/settings.php'));
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

        if (!password_verify($currentPassword, (string) $admin['password'])) {
            setFlash('error', 'رمز عبور فعلی نادرست است.');
            redirect(url('admin/settings.php'));
        }

        if (!isStrongAdminPassword($newPassword)) {
            setFlash('error', 'رمز عبور جدید باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.');
            redirect(url('admin/settings.php'));
        }

        if ($newPassword !== $confirmNewPassword) {
            setFlash('error', 'تکرار رمز عبور جدید مطابقت ندارد.');
            redirect(url('admin/settings.php'));
        }

        $updatePwd = $pdo->prepare('UPDATE admins SET password = :password WHERE id = :id');
        $updatePwd->execute([
            ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => (int) $admin['id'],
        ]);

        recordAudit('auth.password_change', 'admin', (int) $admin['id']);
        setFlash('success', 'رمز عبور با موفقیت تغییر کرد.');
        redirect(url('admin/settings.php'));
    }

    $siteName = trim((string) ($_POST['site_name'] ?? ''));
    $siteDescription = trim((string) ($_POST['site_description'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $newLogo = null;

    if ($siteName === '' || settingsStringLength($siteName) > 100) {
        setFlash('error', 'لطفاً نام سایت معتبر وارد کنید.');
        redirect(url('admin/settings.php'));
    }

    if (settingsStringLength($siteDescription) > 255) {
        setFlash('error', 'لطفاً توضیحات متای معتبر وارد کنید.');
        redirect(url('admin/settings.php'));
    }

    if (settingsStringLength($contactPhone) > 30 || !preg_match('/\A[0-9+()\-\s]*\z/', $contactPhone)) {
        setFlash('error', 'لطفاً شماره تماس معتبر وارد کنید.');
        redirect(url('admin/settings.php'));
    }

    try {
        $newLogo = handleLogoUpload($_FILES['logo'] ?? []);
        $logoPath = $newLogo ?? $settings['logo'];

        saveSetting($pdo, 'site_name', $siteName);
        saveSetting($pdo, 'site_description', $siteDescription);
        saveSetting($pdo, 'logo', $logoPath);
        saveSetting($pdo, 'contact_phone', $contactPhone);

        if ($newLogo !== null) {
            deleteLogoFile($settings['logo']);
        }

        recordAudit('settings.update', 'settings', null);
        setFlash('success', 'تنظیمات با موفقیت به‌روزرسانی شد.');
        redirect(url('admin/settings.php'));
    } catch (Throwable $exception) {
        error_log($exception->getMessage());

        if ($newLogo !== null) {
            deleteLogoFile($newLogo);
        }

        setFlash('error', 'تنظیمات ذخیره نشد. لطفاً فرم را بررسی کرده و دوباره تلاش کنید.');
        redirect(url('admin/settings.php'));
    }
}

$successMessage = getFlash('success');
$errorMessage = getFlash('error');
$pageTitle = 'تنظیمات | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>تنظیمات</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <form class="form-card" method="post" action="<?= e(url('admin/settings.php')) ?>" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="update_settings">

        <label for="site_name">نام سایت</label>
        <input
            type="text"
            id="site_name"
            name="site_name"
            maxlength="100"
            value="<?= e($settings['site_name']) ?>"
            required
        >

        <label for="site_description">توضیحات متا</label>
        <textarea
            id="site_description"
            name="site_description"
            maxlength="255"
            rows="4"
        ><?= e($settings['site_description']) ?></textarea>

        <label for="contact_phone">شماره تماس (نمایش در صفحه اصلی)</label>
        <input
            type="text"
            id="contact_phone"
            name="contact_phone"
            maxlength="30"
            placeholder="+98 21 1234 5678"
            value="<?= e($settings['contact_phone'] ?? '') ?>"
        >

        <?php if ($settings['logo'] !== ''): ?>
            <div>
                <p>لوگوی فعلی</p>
                <img src="<?= e(url($settings['logo'])) ?>" alt="لوگوی <?= e($settings['site_name']) ?>" class="admin-logo-preview">
            </div>
        <?php endif; ?>

        <label for="logo">لوگو</label>
        <input
            type="file"
            id="logo"
            name="logo"
            accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif"
        >
        <p>فرمت‌های مجاز: JPG، PNG، GIF. حداکثر حجم: ۵۰۰ کیلوبایت.</p>

        <button type="submit">ذخیره تنظیمات</button>
    </form>

    <form class="form-card margin-top-lg" method="post" action="<?= e(url('admin/settings.php')) ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="change_password">

        <h2>تغییر رمز عبور</h2>
        <p class="muted">برای امنیت حساب، رمز عبور خود را به‌صورت دوره‌ای تغییر دهید — به‌خصوص اگر هنوز از رمز پیش‌فرض استفاده می‌کنید.</p>

        <label for="current_password">رمز عبور فعلی</label>
        <input
            type="password"
            id="current_password"
            name="current_password"
            autocomplete="current-password"
            required
        >

        <label for="new_password">رمز عبور جدید</label>
        <input
            type="password"
            id="new_password"
            name="new_password"
            autocomplete="new-password"
            minlength="8"
            required
        >
        <p class="muted">حداقل ۸ کاراکتر، شامل حداقل یک حرف و یک عدد.</p>

        <label for="confirm_new_password">تکرار رمز عبور جدید</label>
        <input
            type="password"
            id="confirm_new_password"
            name="confirm_new_password"
            autocomplete="new-password"
            minlength="8"
            required
        >

        <button type="submit">تغییر رمز عبور</button>
    </form>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
