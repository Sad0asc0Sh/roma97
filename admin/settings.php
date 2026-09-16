<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';

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
        'contact_email' => 'info@roma.ir',
        'site_address' => '',
        'map_link' => '',
        'working_hours' => '',
        'instagram' => '',
        'telegram' => '',
        'whatsapp' => '',
        'topbar_enabled' => '1',
        'topbar_text' => '',
        'default_tuition_amount' => '0',
        'sms_enabled' => '0',
        'sms_api_key' => '',
        'sms_detailed_report_enabled' => '0',
        'sms_pattern_otp' => '',
        'sms_pattern_welcome' => '',
        'sms_pattern_daily_report_brief' => '',
        'sms_pattern_daily_report' => '',
        'sms_pattern_attendance' => '',
        'sms_pattern_tuition_reminder' => '',
        'sms_pattern_announcement' => '',
        'sms_pattern_payment_receipt' => '',
        'sms_pattern_child_status' => '',
        'sms_inline_budget_seconds' => '8',
    ];

    $keys = array_keys($settings);
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $statement = $pdo->prepare(
        'SELECT meta_key, meta_value FROM settings WHERE meta_key IN (' . $placeholders . ')'
    );
    $statement->execute($keys);

    while ($row = $statement->fetch()) {
        if (array_key_exists($row['meta_key'], $settings)) {
            $settings[$row['meta_key']] = (string) $row['meta_value'];
        }
    }

    return $settings;
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

    $mimeType = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
    } else {
        $mimeType = $allowedTypes[$extension][0] ?? null;
    }

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

    if (!@move_uploaded_file($tmpName, $destination)) {
        if (!@copy($tmpName, $destination)) {
            throw new RuntimeException('بارگذاری لوگو در دسترس نیست.');
        }
        @unlink($tmpName);
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

    $recentSmsLogs = [];
    try {
        $smsLogsStmt = $pdo->query("
            SELECT sl.*, p.first_name AS p_first, p.last_name AS p_last
            FROM sms_logs sl
            LEFT JOIN parents p ON p.id = sl.parent_id
            ORDER BY sl.id DESC
            LIMIT 15
        ");
        $recentSmsLogs = $smsLogsStmt ? $smsLogsStmt->fetchAll() : [];
    } catch (Throwable $e) {
        $recentSmsLogs = [];
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'تنظیمات موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
    $settings = [
        'site_name' => 'مهد کودک روما',
        'site_description' => 'به مهد کودک روما خوش آمدید',
        'logo' => '',
        'contact_phone' => '+98 21 1234 5678',
        'contact_email' => 'info@roma.ir',
        'site_address' => '',
        'map_link' => '',
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

    if ($action === 'create_admin') {
        if (currentAdminRole() !== 'owner') {
            setFlash('error', 'تنها مدیر ارشد (Owner) می‌تواند کاربر جدید پنل تعریف کند.');
            redirect(url('admin/settings.php'));
        }

        $newUsername = trim((string) ($_POST['new_admin_username'] ?? ''));
        $newPassword = (string) ($_POST['new_admin_password'] ?? '');
        $newRole     = (string) ($_POST['new_admin_role'] ?? 'manager');

        $allowedRoles = ['owner', 'manager', 'accountant', 'receptionist'];

        if (!preg_match('/\A[A-Za-z0-9_]{3,50}\z/', $newUsername)) {
            setFlash('error', 'نام کاربری باید ۳ تا ۵۰ کاراکتر و شامل حروف، اعداد یا زیرخط باشد.');
            redirect(url('admin/settings.php'));
        }

        if (!isStrongAdminPassword($newPassword)) {
            setFlash('error', 'رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.');
            redirect(url('admin/settings.php'));
        }

        if (!in_array($newRole, $allowedRoles, true)) {
            setFlash('error', 'نقش انتخابی نامعتبر است.');
            redirect(url('admin/settings.php'));
        }

        $chkStmt = $pdo->prepare('SELECT id FROM admins WHERE username = :u LIMIT 1');
        $chkStmt->execute([':u' => $newUsername]);

        if ($chkStmt->fetchColumn() !== false) {
            setFlash('error', 'این نام کاربری قبلاً استفاده شده است.');
            redirect(url('admin/settings.php'));
        }

        $insStmt = $pdo->prepare(
            'INSERT INTO admins (username, password, role) VALUES (:u, :p, :r)'
        );
        $insStmt->execute([
            ':u' => $newUsername,
            ':p' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':r' => $newRole,
        ]);

        recordAudit('admin.create', 'admin', (int) $pdo->lastInsertId(), ['username' => $newUsername, 'role' => $newRole]);
        setFlash('success', 'حساب مدیر جدید با موفقیت ایجاد شد.');
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

    if ($action === 'update_sms_settings') {
        $smsEnabled = isset($_POST['sms_enabled']) ? '1' : '0';
        $smsApiKey  = trim((string) ($_POST['sms_api_key'] ?? ''));
        $smsDetailed = isset($_POST['sms_detailed_report_enabled']) ? '1' : '0';
        $bodyOtp    = trim((string) ($_POST['sms_pattern_otp'] ?? ''));
        $bodyWel    = trim((string) ($_POST['sms_pattern_welcome'] ?? ''));
        $bodyBrief  = trim((string) ($_POST['sms_pattern_daily_report_brief'] ?? ''));
        $bodyRep    = trim((string) ($_POST['sms_pattern_daily_report'] ?? ''));
        $bodyAtt    = trim((string) ($_POST['sms_pattern_attendance'] ?? ''));
        $bodyTui    = trim((string) ($_POST['sms_pattern_tuition_reminder'] ?? ''));
        $bodyAnn    = trim((string) ($_POST['sms_pattern_announcement'] ?? ''));
        $bodyPay    = trim((string) ($_POST['sms_pattern_payment_receipt'] ?? ''));
        $bodyStat   = trim((string) ($_POST['sms_pattern_child_status'] ?? ''));

        // Inline send budget: how long a web request may spend flushing the SMS
        // queue before handing the rest over to the cron worker. Clamped so a
        // typo can never hang the panel for minutes.
        $inlineBudget = (int) ($_POST['sms_inline_budget_seconds'] ?? 8);
        if ($inlineBudget < 0) {
            $inlineBudget = 0;
        }
        if ($inlineBudget > 25) {
            $inlineBudget = 25;
        }

        saveSetting($pdo, 'sms_enabled', $smsEnabled);
        saveSetting($pdo, 'sms_api_key', $smsApiKey);
        saveSetting($pdo, 'sms_detailed_report_enabled', $smsDetailed);
        saveSetting($pdo, 'sms_pattern_otp', $bodyOtp);
        saveSetting($pdo, 'sms_pattern_welcome', $bodyWel);
        saveSetting($pdo, 'sms_pattern_daily_report_brief', $bodyBrief);
        saveSetting($pdo, 'sms_pattern_daily_report', $bodyRep);
        saveSetting($pdo, 'sms_pattern_attendance', $bodyAtt);
        saveSetting($pdo, 'sms_pattern_tuition_reminder', $bodyTui);
        saveSetting($pdo, 'sms_pattern_announcement', $bodyAnn);
        saveSetting($pdo, 'sms_pattern_payment_receipt', $bodyPay);
        saveSetting($pdo, 'sms_pattern_child_status', $bodyStat);
        saveSetting($pdo, 'sms_inline_budget_seconds', (string) $inlineBudget);

        recordAudit('sms_settings.update', 'settings', null);
        setFlash('success', 'تنظیمات پنل پیامک با موفقیت ذخیره شد.');
        redirect(url('admin/settings.php#sms-section'));
    }

    if ($action === 'test_sms') {
        $testPhone = trim((string) ($_POST['test_phone'] ?? ''));
        $testBodyId = (int) ($_POST['test_body_id'] ?? 0);
        $testArg1 = trim((string) ($_POST['test_arg1'] ?? 'تست'));
        $testArg2 = trim((string) ($_POST['test_arg2'] ?? 'مهد کودک روما'));

        if (!isValidIranianMobile($testPhone)) {
            setFlash('error', 'شماره موبایل گیرنده تست نامعتبر است (مثال: 09123456789).');
            redirect(url('admin/settings.php#sms-section'));
        }

        if ($testBodyId <= 0) {
            setFlash('error', 'لطفاً شناسه الگوی معتبر (bodyId) برای تست وارد کنید.');
            redirect(url('admin/settings.php#sms-section'));
        }

        $args = array_filter([$testArg1, $testArg2], fn($a) => $a !== '');
        $res = sendMeliPayamakPattern($testPhone, $testBodyId, array_values($args), 'test');

        if ($res['success']) {
            setFlash('success', "پیامک تست با موفقیت ارسال شد. کد پیگیری: " . ($res['recId'] ?? '-'));
        } else {
            setFlash('error', "خطا در ارسال پیامک تست: " . $res['status']);
        }
        redirect(url('admin/settings.php#sms-section'));
    }

    $siteName = trim((string) ($_POST['site_name'] ?? ''));
    $siteDescription = trim((string) ($_POST['site_description'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $siteAddressVal = trim((string) ($_POST['site_address'] ?? ''));
    $mapLink = trim((string) ($_POST['map_link'] ?? ''));
    $workingHours = trim((string) ($_POST['working_hours'] ?? ''));
    $instagram = trim((string) ($_POST['instagram'] ?? ''));
    $telegram = trim((string) ($_POST['telegram'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $defaultTuitionAmountRaw = trim((string) ($_POST['default_tuition_amount'] ?? '0'));
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

    // Map share link: optional, but must point at a known map provider.
    $mapLinkValid = true;
    if ($mapLink !== '') {
        if (settingsStringLength($mapLink) > 500 || !filter_var($mapLink, FILTER_VALIDATE_URL)) {
            $mapLinkValid = false;
        } else {
            $host = strtolower((string) parse_url($mapLink, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            $allowedHosts = [
                'neshan.org', 'map.neshan.org', 'nshn.ir',
                'maps.google.com', 'google.com', 'maps.app.goo.gl', 'goo.gl',
                'balad.ir', 'map.ir',
            ];
            $hostAllowed = false;
            foreach ($allowedHosts as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    $hostAllowed = true;
                    break;
                }
            }
            $mapLinkValid = $hostAllowed;
        }
    }

    if (!$mapLinkValid) {
        setFlash('error', 'لینک نقشه نامعتبر است. فقط لینک اشتراک از نشان (Neshan)، گوگل مپ یا بلد پذیرفته می‌شود.');
        redirect(url('admin/settings.php'));
    }

    if ($workingHours !== '' && settingsStringLength($workingHours) > 100) {
        setFlash('error', 'ساعت کاری بسیار طولانی است.');
        redirect(url('admin/settings.php'));
    }

    $defaultTuitionVal = filter_var($defaultTuitionAmountRaw, FILTER_VALIDATE_FLOAT);
    if ($defaultTuitionVal === false || $defaultTuitionVal < 0 || $defaultTuitionVal >= 1000000000) {
        setFlash('error', 'لطفاً مبلغ شهریه ثابت پیش‌فرض معتبر (عدد مثبت تا ۱,۰۰۰,۰۰۰,۰۰۰ تومان) وارد کنید.');
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
        saveSetting($pdo, 'map_link', $mapLink);
        saveSetting($pdo, 'working_hours', $workingHours);
        saveSetting($pdo, 'instagram', $instagram);
        saveSetting($pdo, 'telegram', $telegram);
        saveSetting($pdo, 'whatsapp', $whatsapp);
        saveSetting($pdo, 'default_tuition_amount', (string) $defaultTuitionVal);

        $topbarEnabled = isset($_POST['topbar_enabled']) ? '1' : '0';
        $topbarText = trim((string) ($_POST['topbar_text'] ?? ''));
        if (settingsStringLength($topbarText) > 255) {
            $topbarText = mb_substr($topbarText, 0, 255, 'UTF-8');
        }
        saveSetting($pdo, 'topbar_enabled', $topbarEnabled);
        saveSetting($pdo, 'topbar_text', $topbarText);

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
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-settings-grid">
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">تنظیمات عمومی سایت</h2>
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
                        maxlength="100" placeholder="info@roma.ir"
                        value="<?= e($settings['contact_email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="site_address" class="form-label">آدرس مهد کودک (نشانی اصلی)</label>
                    <input type="text" id="site_address" name="site_address" class="form-control"
                        maxlength="255" placeholder="مثال: تهران، خیابان ...، پلاک ..."
                        value="<?= e($settings['site_address'] ?? '') ?>">
                    <small style="color:var(--muted);font-size:0.85rem;">این نشانی در فوتر سایت، صفحه تماس و اطلاعات ساختارمند (SEO) نمایش داده می‌شود.</small>
                </div>

                <div class="form-group">
                    <label for="map_link" class="form-label">لینک اشتراک موقعیت روی نقشه 🗺️</label>
                    <input type="url" id="map_link" name="map_link" class="form-control"
                        dir="ltr" maxlength="500"
                        placeholder="https://maps.app.goo.gl/... یا https://neshan.org/maps/..."
                        value="<?= e($settings['map_link'] ?? '') ?>">
                    <small style="color:var(--muted);font-size:0.85rem;display:block;line-height:1.8;margin-top:4px;">
                        نحوه دریافت لینک: در اپلیکیشن <strong>نشان</strong> یا <strong>گوگل مپ</strong>، محل دقیق مهد را پیدا کنید ←
                        روی نام/نشانگر بزنید ← گزینه «اشتراک‌گذاری» ← کپی لینک ← همین‌جا جای‌گذاری کنید.
                        نقشه صفحه تماس به‌صورت خودکار از این لینک ساخته می‌شود.
                    </small>

                    <?php $previewEmbed = function_exists('siteMapEmbedUrl') && ($settings['map_link'] ?? '') !== '' ? siteMapEmbedUrl() : null; ?>
                    <?php if (($settings['map_link'] ?? '') !== ''): ?>
                        <div style="margin-top:var(--space-sm);">
                            <?php if ($previewEmbed !== null): ?>
                                <div style="width:100%;height:180px;border-radius:var(--radius-md);overflow:hidden;border:1px solid var(--border);">
                                    <iframe title="پیش‌نمایش نقشه" src="<?= e($previewEmbed) ?>"
                                        width="100%" height="100%" style="border:0;"
                                        loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                                </div>
                            <?php else: ?>
                                <div style="padding:10px 14px;background:var(--adm-warning-50, rgba(232,168,56,0.12));border:1px solid rgba(232,168,56,0.35);border-radius:var(--radius-sm);font-size:0.8125rem;color:var(--adm-warning, #B8791A);">
                                    لینک ثبت شده است، اما پیش‌نمایش داخلی برای این سرویس ممکن نیست (مثلاً بلد). لینک در صفحه تماس به‌صورت دکمه نمایش داده می‌شود.
                                </div>
                            <?php endif; ?>
                            <a href="<?= e($settings['map_link']) ?>" target="_blank" rel="noopener noreferrer"
                                style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;margin-top:6px;">
                                بازکردن لینک فعلی ↗
                            </a>
                        </div>
                    <?php endif; ?>
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

                <div class="form-group">
                    <label for="default_tuition_amount" class="form-label">شهریه ثابت پیش‌فرض ماهانه (تومان)</label>
                    <input type="number" step="0.01" id="default_tuition_amount" name="default_tuition_amount" class="form-control"
                        placeholder="۰"
                        value="<?= e($settings['default_tuition_amount'] ?? '0') ?>" required min="0">
                    <small style="color:var(--muted);font-size:0.85rem;">مبلغ پیش‌فرض شهریه برای محاسبات مانده بدهی و یادآوری خودکار</small>
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

                <div class="form-group">
                    <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="topbar_enabled" value="1" style="width:18px;height:18px;cursor:pointer;"
                            <?= ($settings['topbar_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                        فعالسازی نوار بالای سایت (Top Bar)
                    </label>
                    <small style="color:var(--muted);font-size:0.85rem;">نمایش نوار تماس و شبکههای اجتماعی در بالای هدر سایت</small>
                </div>

                <div class="form-group">
                    <label for="topbar_text" class="form-label">متن نوار بالای سایت</label>
                    <input type="text" id="topbar_text" name="topbar_text" class="form-control"
                        maxlength="255" placeholder="متن اختیاری برای نمایش در نوار بالا..."
                        value="<?= e($settings['topbar_text'] ?? '') ?>">
                    <small style="color:var(--muted);font-size:0.85rem;">در صورت خالی بودن، اطلاعات تماس و شبکههای اجتماعی نمایش داده میشود.</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">تغییر رمز عبور</h2>
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
                    <button type="submit" class="btn btn-primary">تغییر رمز عبور</button>
                </div>
            </form>
        </div>

        <?php if (currentAdminRole() === 'owner'): ?>
            <div class="admin-section">
                <div class="admin-section-header">
                    <h2 class="admin-section-title">افزودن کاربر جدید پنل مدیریت</h2>
                </div>
                <p style="color:var(--muted);margin-bottom:var(--space-md);font-size:0.9rem;">تعریف حساب کاربری جدید برای کارمندان با نقش‌های دسترسی مشخص.</p>
                <form method="post" action="<?= e(url('admin/settings.php')) ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="create_admin">

                    <div class="form-group">
                        <label for="new_admin_username" class="form-label">نام کاربری <span class="req">*</span></label>
                        <input type="text" id="new_admin_username" name="new_admin_username" class="form-control"
                            maxlength="50" placeholder="مثال: accountant1" required pattern="[A-Za-z0-9_]{3,50}">
                    </div>

                    <div class="form-group">
                        <label for="new_admin_password" class="form-label">رمز عبور <span class="req">*</span></label>
                        <input type="password" id="new_admin_password" name="new_admin_password" class="form-control"
                            autocomplete="new-password" minlength="8" required>
                        <small style="color:var(--muted);font-size:0.85rem;">حداقل ۸ کاراکتر، شامل حداقل یک حرف و یک عدد.</small>
                    </div>

                    <div class="form-group">
                        <label for="new_admin_role" class="form-label">نقش دسترسی <span class="req">*</span></label>
                        <select id="new_admin_role" name="new_admin_role" class="form-control" required>
                            <option value="manager">مدیر داخلی (Manager) - همه صفحات به‌جز تنظیمات کل</option>
                            <option value="accountant">حسابدار (Accountant) - فقط صفحات مالی و گزارشات</option>
                            <option value="receptionist">مسئول پذیرش (Receptionist) - مدیریت کودکان، حضور و غیاب و پیام‌ها</option>
                            <option value="owner">مدیر ارشد (Owner) - دسترسی کامل به تمامی بخش‌ها</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">ایجاد حساب کاربر</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- SMS Gateway Section -->
        <div class="admin-section" id="sms-section" style="grid-column: 1 / -1;">
            <div class="admin-section-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <h2 class="admin-section-title">📱 تنظیمات پنل پیامک ملی‌پیامک (خط خدماتی اشتراکی / الگو)</h2>
                <span class="badge <?= ($settings['sms_enabled'] ?? '0') === '1' ? 'badge-success' : 'badge-secondary' ?>" style="font-size:0.85rem;padding:4px 10px;border-radius:20px;">
                    <?= ($settings['sms_enabled'] ?? '0') === '1' ? '✅ پیامک فعال است' : '⚪ پیامک غیرفعال است' ?>
                </span>
            </div>
            <p style="color:var(--muted);margin-bottom:var(--space-md);font-size:0.9rem;line-height:1.8;">
                با اتصال به وب‌سرویس ملی‌پیامک، کدهای ورود/ثبت‌نام (OTP) و اعلانات مهدکودک (گزارش روزانه کودک، حضور و غیاب، یادآوری بدهی شهریه و اطلاعیه‌ها) از طریق خط خدماتی اشتراکی حتی برای شماره‌های بلک‌لیست (تبلیغات مسدود) به صورت آنی ارسال می‌شوند.
            </p>

            <form method="post" action="<?= e(url('admin/settings.php#sms-section')) ?>" novalidate style="margin-bottom:var(--space-xl);">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_sms_settings">

                <div style="background:var(--surface-hover, rgba(0,0,0,0.02));padding:16px;border-radius:var(--radius-md);border:1px solid var(--border);margin-bottom:var(--space-lg);">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:bold;">
                            <input type="checkbox" name="sms_enabled" value="1" style="width:18px;height:18px;cursor:pointer;"
                                <?= ($settings['sms_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            فعال‌سازی ارسال پیامک‌های سیستمی
                        </label>
                        <small style="color:var(--muted);font-size:0.85rem;">در صورت غیرفعال بودن، پیام‌ها فقط به صورت نوتیفیکیشن درون پورتال ذخیره می‌شوند.</small>
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label for="sms_api_key" class="form-label">کلید وب‌سرویس ملی‌پیامک (SMS API Key) <span class="req">*</span></label>
                        <input type="text" id="sms_api_key" name="sms_api_key" class="form-control" dir="ltr"
                            placeholder="مثال: f95527ef2fa7498dbad9a0d09f2bbad2"
                            value="<?= e($settings['sms_api_key'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.85rem;">کلید وب‌سرویس اختصاصی کنسول ملی‌پیامک شما (از منوی وب‌سرویس در پنل ملی‌پیامک دریافت کنید).</small>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:bold;">
                            <input type="checkbox" name="sms_detailed_report_enabled" value="1" style="width:18px;height:18px;cursor:pointer;"
                                <?= ($settings['sms_detailed_report_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            گزارش روزانه تفصیلی برای همه (حالت کلی)
                        </label>
                        <small style="color:var(--muted);font-size:0.85rem;">خاموش = همه والدین پیامک خلاصه ارجاع به پنل می‌گیرند. روشن = همه والدین پیامک تفصیلی می‌گیرند. هزینه پیامک از جیب مهد است و به شهریه اضافه نمی‌شود؛ در گزارش مالی بخش خدمات جانبی ثبت می‌شود.</small>
                    </div>
                </div>

                <h3 style="font-size:1.05rem;margin-bottom:var(--space-md);color:var(--adm-text);border-bottom:1px dashed var(--adm-border);padding-bottom:8px;">
                    📋 شناسه‌های الگوهای متنی تایید شده در ملی‌پیامک (با لحن گرم و کودک‌محور)
                </h3>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:var(--space-md);margin-bottom:var(--space-lg);">
                    <div class="form-group">
                        <label for="sms_pattern_otp" class="form-label">
                            ۱. کد تایید ورود و ثبت‌نام (OTP)
                        </label>
                        <input type="number" id="sms_pattern_otp" name="sms_pattern_otp" class="form-control" dir="ltr"
                            placeholder="مثال: 524" value="<?= e($settings['sms_pattern_otp'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیر: <code>{0}=کد تایید ۵ رقمی</code><br>نمونه: کد ورود شما به خانه پرمهر مهدکودک روما: {0}</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_welcome" class="form-label">
                            ۲. خوش‌آمدگویی و تایید حساب کاربری والد
                        </label>
                        <input type="number" id="sms_pattern_welcome" name="sms_pattern_welcome" class="form-control" dir="ltr"
                            placeholder="مثال: 525" value="<?= e($settings['sms_pattern_welcome'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیر: <code>{0}=نام والد</code><br>نمونه: {0} عزیز، با عشق به جمع پرمهر مهدکودک روما خوش آمدید.</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_daily_report_brief" class="form-label">
                            ۳. گزارش روزانه خلاصه (ارجاع به پنل) 💡
                        </label>
                        <input type="number" id="sms_pattern_daily_report_brief" name="sms_pattern_daily_report_brief" class="form-control" dir="ltr"
                            placeholder="مثال: 530" value="<?= e($settings['sms_pattern_daily_report_brief'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام کودک</code>، <code>{1}=نام مهد</code><br>نمونه: مامان و بابای مهربون {0}، گزارش روز قشنگ دلبندتون در پنل {1} ثبت شد.</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_daily_report" class="form-label">
                            ۴. گزارش روزانه تفصیلی ⭐
                        </label>
                        <input type="number" id="sms_pattern_daily_report" name="sms_pattern_daily_report" class="form-control" dir="ltr"
                            placeholder="مثال: 526" value="<?= e($settings['sms_pattern_daily_report'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام کودک</code>، <code>{1}=تاریخ</code>، <code>{2}=احوال/روحی</code><br>نمونه: گزارش روز {1} فرشته کوچولومون {0}: {2}. مهد روما</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_attendance" class="form-label">
                            ۵. اعلام حضور / غیبت کودک در مهد
                        </label>
                        <input type="number" id="sms_pattern_attendance" name="sms_pattern_attendance" class="form-control" dir="ltr"
                            placeholder="مثال: 527" value="<?= e($settings['sms_pattern_attendance'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام کودک</code>، <code>{1}=تاریخ</code>، <code>{2}=وضعیت</code><br>نمونه: دلبند قشنگمون {0} در تاریخ {1} {2}. مهد روما</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_tuition_reminder" class="form-label">
                            ۶. یادآوری دوستانه شهریه
                        </label>
                        <input type="number" id="sms_pattern_tuition_reminder" name="sms_pattern_tuition_reminder" class="form-control" dir="ltr"
                            placeholder="مثال: 528" value="<?= e($settings['sms_pattern_tuition_reminder'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام والد</code>، <code>{1}=نام کودک</code>، <code>{2}=ماه</code>، <code>{3}=مبلغ</code><br>نمونه: {0} مهربان، یادآوری دوستانه شهریه ماه {2} فرشته قشنگمون {1}: {3} تومان. مهد روما</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_announcement" class="form-label">
                            ۷. اطلاعیه‌ها و رویدادهای مهدکودک
                        </label>
                        <input type="number" id="sms_pattern_announcement" name="sms_pattern_announcement" class="form-control" dir="ltr"
                            placeholder="مثال: 529" value="<?= e($settings['sms_pattern_announcement'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام والد</code>، <code>{1}=عنوان پیام</code><br>نمونه: {0} مهربان، اطلاعیه جدید مهد با عنوان «{1}» در پنل شما قرار گرفت.</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_payment_receipt" class="form-label">
                            ۸. رسید پرداخت شهریه (اعتمادساز مالی) 🧾
                        </label>
                        <input type="number" id="sms_pattern_payment_receipt" name="sms_pattern_payment_receipt" class="form-control" dir="ltr"
                            placeholder="مثال: 531" value="<?= e($settings['sms_pattern_payment_receipt'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام والد</code>، <code>{1}=نام کودک</code>، <code>{2}=مبلغ</code>، <code>{3}=ماه</code><br>نمونه: {0} مهربان، پرداخت {2} تومان شهریه ماه {3} برای {1} با سپاس ثبت شد. مهد روما</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_child_status" class="form-label">
                            ۹. تغییر وضعیت ثبت‌نام کودک 🔄
                        </label>
                        <input type="number" id="sms_pattern_child_status" name="sms_pattern_child_status" class="form-control" dir="ltr"
                            placeholder="مثال: 532" value="<?= e($settings['sms_pattern_child_status'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام والد</code>، <code>{1}=نام کودک</code>، <code>{2}=وضعیت جدید</code><br>نمونه: {0} مهربان، وضعیت ثبت‌نام {1} به «{2}» تغییر یافت. مهد روما</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_inline_budget_seconds" class="form-label">
                            ⏱️ حداکثر زمان ارسال درون‌خطی (ثانیه)
                        </label>
                        <input type="number" id="sms_inline_budget_seconds" name="sms_inline_budget_seconds" class="form-control" dir="ltr"
                            min="0" max="25" placeholder="8" value="<?= e($settings['sms_inline_budget_seconds'] ?? '8') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">
                            هنگام ثبت گزارش یا حضور و غیاب، سیستم حداکثر این مدت را صرف ارسال آنی پیامک‌ها می‌کند و باقی صف را به کرون می‌سپارد تا صفحه هرگز کند نشود. مقدار پیشنهادی: ۸ ثانیه.
                        </small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات پیامک</button>
                </div>
            </form>

            <!-- Test SMS Form -->
            <div style="background:var(--adm-primary-50, rgba(46,133,64,0.06));border:1px solid var(--adm-border);padding:18px;border-radius:var(--radius-md);margin-bottom:var(--space-xl);">
                <h3 style="font-size:1rem;color:var(--adm-primary,#3D8B63);margin-bottom:8px;">🧪 تست زنده ارسال پیامک خدماتی با الگو</h3>
                <p style="font-size:0.85rem;color:var(--adm-text-muted);margin-bottom:12px;">برای اطمینان از صحت کلید API و شناسه الگو، یک پیامک تستی به شماره دلخواه ارسال کنید.</p>
                <form method="post" action="<?= e(url('admin/settings.php#sms-section')) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="test_sms">

                    <div style="flex:1;min-width:180px;">
                        <label class="form-label" style="font-size:0.82rem;">شماره گیرنده تست</label>
                        <input type="text" name="test_phone" class="form-control" dir="ltr" placeholder="09123456789" required>
                    </div>

                    <div style="width:120px;">
                        <label class="form-label" style="font-size:0.82rem;">شناسه الگو (bodyId)</label>
                        <input type="number" name="test_body_id" class="form-control" dir="ltr" placeholder="524" required>
                    </div>

                    <div style="flex:1;min-width:140px;">
                        <label class="form-label" style="font-size:0.82rem;">متغیر اول ({0})</label>
                        <input type="text" name="test_arg1" class="form-control" placeholder="12345">
                    </div>

                    <div style="flex:1;min-width:140px;">
                        <label class="form-label" style="font-size:0.82rem;">متغیر دوم ({1})</label>
                        <input type="text" name="test_arg2" class="form-control" placeholder="روما">
                    </div>

                    <button type="submit" class="btn btn-primary" style="padding:10px 18px;font-size:0.88rem;cursor:pointer;border-radius:var(--radius-sm);">
                        🚀 ارسال پیامک تست
                    </button>
                </form>
            </div>

            <!-- Recent SMS Logs Table -->
            <h3 style="font-size:1.05rem;margin-bottom:var(--space-md);color:var(--adm-text);">
                📜 آخرین پیامک‌های ارسالی سیستم (لاگ وضعیت)
            </h3>
            <?php if (!empty($recentSmsLogs)): ?>
                <div class="table-responsive" style="overflow-x:auto;">
                    <table class="table" style="width:100%;font-size:0.85rem;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>گیرنده</th>
                                <th>والد</th>
                                <th>نوع رویداد</th>
                                <th>شناسه الگو</th>
                                <th>شناسه پیگیری (recId)</th>
                                <th>وضعیت ارسال</th>
                                <th>زمان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentSmsLogs as $log): ?>
                                <tr>
                                    <td><?= e($log['id']) ?></td>
                                    <td dir="ltr" style="text-align:right;font-family:monospace;"><?= e($log['recipient_phone']) ?></td>
                                    <td><?= !empty($log['p_first']) ? e($log['p_first'] . ' ' . $log['p_last']) : '<span style="color:var(--adm-text-muted);">-</span>' ?></td>
                                    <td>
                                        <span class="badge badge-secondary" style="font-size:0.75rem;">
                                            <?= e($log['message_type'] ?? 'pattern') ?>
                                        </span>
                                    </td>
                                    <td dir="ltr" style="text-align:right;"><?= e($log['pattern_body_id'] ?? '-') ?></td>
                                    <td dir="ltr" style="text-align:right;font-family:monospace;"><?= e($log['rec_id'] ?? '-') ?></td>
                                    <td>
                                        <?php if (($log['status'] ?? '') === 'sent'): ?>
                                            <span style="color:var(--adm-success);font-weight:bold;">✅ موفق</span>
                                        <?php else: ?>
                                            <span style="color:var(--adm-danger);font-weight:bold;" title="<?= e($log['error_message'] ?? '') ?>">
                                                ❌ <?= e($log['error_message'] ?: ($log['status'] ?? 'ناموفق')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.78rem;color:var(--adm-text-muted);"><?= e(shamsiDate((string)$log['created_at'], 'with_time')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color:var(--muted);font-size:0.88rem;background:var(--surface-hover);padding:14px;border-radius:var(--radius-sm);text-align:center;">
                    هنوز هیچ پیامکی از طریق سیستم ارسال نشده است.
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
