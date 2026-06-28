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
        'contact_email' => 'info@rooma.ir',
        'site_address' => '',
        'working_hours' => '',
        'instagram' => '',
        'telegram' => '',
        'whatsapp' => '',
    ];

    $statement = $pdo->prepare(
        'SELECT meta_key, meta_value FROM settings WHERE meta_key IN (:site_name, :site_description, :logo, :contact_phone, :contact_email, :site_address, :working_hours, :instagram, :telegram, :whatsapp)'
    );
    $statement->execute([
        ':site_name' => 'site_name',
        ':site_description' => 'site_description',
        ':logo' => 'logo',
        ':contact_phone' => 'contact_phone',
        ':contact_email' => 'contact_email',
        ':site_address' => 'site_address',
        ':working_hours' => 'working_hours',
        ':instagram' => 'instagram',
        ':telegram' => 'telegram',
        ':whatsapp' => 'whatsapp',
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
        'contact_email' => 'info@rooma.ir',
        'site_address' => '',
        'working_hours' => '',
        'instagram' => '',
        'telegram' => '',
        'whatsapp' => '',
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
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $siteAddressVal = trim((string) ($_POST['site_address'] ?? ''));
    $workingHours = trim((string) ($_POST['working_hours'] ?? ''));
    $instagram = trim((string) ($_POST['instagram'] ?? ''));
    $telegram = trim((string) ($_POST['telegram'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
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

    if ($contactEmail !== '' && settingsStringLength($contactEmail) > 100) {
        setFlash('error', 'ایمیل معتبر وارد کنید.');
        redirect(url('admin/settings.php'));
    }

    if (settingsStringLength($siteAddressVal) > 255) {
        setFlash('error', 'آدرس بسیار طولانی است.');
        redirect(url('admin/settings.php'));
    }

    if (settingsStringLength($workingHours) > 100) {
        setFlash('error', 'ساعت کاری بسیار طولانی است.');
        redirect(url('admin/settings.php'));
    }

    try {
        $newLogo = handleLogoUpload($_FILES['logo'] ?? []);
        $logoPath = $newLogo ?? $settings['logo'];

        saveSetting($pdo, 'site_name', $siteName);
        saveSetting($pdo, 'site_description', $siteDescription);
        saveSetting($pdo, 'logo', $logoPath);
        saveSetting($pdo, 'contact_phone', $contactPhone);
        saveSetting($pdo, 'contact_email', $contactEmail);
        saveSetting($pdo, 'site_address', $siteAddressVal);
        saveSetting($pdo, 'working_hours', $workingHours);
        saveSetting($pdo, 'instagram', $instagram);
        saveSetting($pdo, 'telegram', $telegram);
        saveSetting($pdo, 'whatsapp', $whatsapp);

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
    <h1>&#9881; تنظیمات</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status">&#9989; <?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert">&#10060; <?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-settings-grid">
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">&#128196; تنظیمات عمومی سایت</h2>
            </div>
            <form method="post" action="<?= e(url('admin/settings.php')) ?>" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_settings">

                <div class="form-group">
                    <label for="site_name" class="form-label">نام سایت</label>
                    <input type="text" id="site_name" name="site_name" class="form-control"
                        maxlength="100" placeholder="نام مهد کودک..."
                        value="<?= e($settings['site_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="site_description" class="form-label">توضیحات متا</label>
                    <textarea id="site_description" name="site_description" class="form-control"
                        maxlength="255" rows="3"
                        placeholder="توضیحات کوتاه درباره سایت..."><?= e($settings['site_description']) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="contact_phone" class="form-label">شماره تماس</label>
                    <input type="text" id="contact_phone" name="contact_phone" class="form-control"
                        maxlength="30" placeholder="+98 21 1234 5678"
                        value="<?= e($settings['contact_phone'] ?? '') ?>">
                    <small style="color:var(--muted);font-size:0.85rem;">نمایش در صفحه اصلی سایت</small>
                </div>

                <div class="form-group">
                    <label for="contact_email" class="form-label">ایمیل</label>
                    <input type="email" id="contact_email" name="contact_email" class="form-control"
                        maxlength="100" placeholder="info@rooma.ir"
                        value="<?= e($settings['contact_email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="site_address" class="form-label">آدرس</label>
                    <input type="text" id="site_address" name="site_address" class="form-control"
                        maxlength="255" placeholder="تهران، خیابان ..."
                        value="<?= e($settings['site_address'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="working_hours" class="form-label">ساعت کاری</label>
                    <input type="text" id="working_hours" name="working_hours" class="form-control"
                        maxlength="100" placeholder="شنبه تا پنجشنبه ..."
                        value="<?= e($settings['working_hours'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="instagram" class="form-label">اینستاگرام (لینک)</label>
                    <input type="url" id="instagram" name="instagram" class="form-control"
                        maxlength="255" placeholder="https://instagram.com/..."
                        value="<?= e($settings['instagram'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="telegram" class="form-label">تلگرام (لینک)</label>
                    <input type="url" id="telegram" name="telegram" class="form-control"
                        maxlength="255" placeholder="https://t.me/..."
                        value="<?= e($settings['telegram'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="whatsapp" class="form-label">واتس‌اپ (شماره)</label>
                    <input type="text" id="whatsapp" name="whatsapp" class="form-control"
                        maxlength="30" placeholder="+98 912 ..."
                        value="<?= e($settings['whatsapp'] ?? '') ?>">
                </div>

                <?php if ($settings['logo'] !== ''): ?>
                    <div class="form-group">
                        <label class="form-label">لوگوی فعلی</label>
                        <img src="<?= e(url($settings['logo'])) ?>" alt="لوگوی <?= e($settings['site_name']) ?>" class="admin-logo-preview">
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="logo" class="form-label">لوگو (برای تغییر)</label>
                    <input type="file" id="logo" name="logo" class="form-control"
                        accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif">
                    <small style="color:var(--muted);font-size:0.85rem;">فرمتهای مجاز: JPG، PNG، GIF. حداکثر حجم: ۵۰۰ کیلوبایت.</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">&#128190; ذخیره تنظیمات</button>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">&#128274; تغییر رمز عبور</h2>
            </div>
            <p style="color:var(--muted);margin-bottom:var(--space-md);font-size:0.9rem;">برای امنیت حساب، رمز عبور خود را بهصورت دورهای تغییر دهید.</p>
            <form method="post" action="<?= e(url('admin/settings.php')) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label for="current_password" class="form-label">رمز عبور فعلی</label>
                    <input type="password" id="current_password" name="current_password" class="form-control"
                        autocomplete="current-password" required>
                </div>

                <div class="form-group">
                    <label for="new_password" class="form-label">رمز عبور جدید</label>
                    <input type="password" id="new_password" name="new_password" class="form-control"
                        autocomplete="new-password" minlength="8" required>
                    <small style="color:var(--muted);font-size:0.85rem;">حداقل ۸ کاراکتر، شامل حداقل یک حرف و یک عدد.</small>
                </div>

                <div class="form-group">
                    <label for="confirm_new_password" class="form-label">تکرار رمز عبور جدید</label>
                    <input type="password" id="confirm_new_password" name="confirm_new_password" class="form-control"
                        autocomplete="new-password" minlength="8" required>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">&#128274; تغییر رمز عبور</button>
                </div>
            </form>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
