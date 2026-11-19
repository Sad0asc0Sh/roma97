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
        'sms_api_key_shared' => '',
        'sms_api_key_otp' => '',
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
        'sms_otp_type' => 'pattern',
        'sms_provider' => 'melipayamak_main',
        'sms_username' => '',
        'sms_password' => '',
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
        $smsProvider = in_array($_POST['sms_provider'] ?? '', ['melipayamak_main', 'melipayamak_console'], true) ? (string) $_POST['sms_provider'] : 'melipayamak_main';
        $smsUsername = trim((string) ($_POST['sms_username'] ?? ''));
        $smsPassword = (string) ($_POST['sms_password'] ?? '');
        if ($smsPassword === '' && $smsProvider === 'melipayamak_main') {
            $existingPass = getSetting('sms_password', '');
            if ($existingPass !== '') {
                $smsPassword = $existingPass;
            }
        }
        $smsApiKey  = melipayamakCleanKey(trim((string) ($_POST['sms_api_key'] ?? '')));
        $smsKeyShared = melipayamakCleanKey(trim((string) ($_POST['sms_api_key_shared'] ?? '')));
        // If master API key is provided, sync to shared key for full backward compatibility
        if ($smsApiKey !== '' && $smsKeyShared === '') {
            $smsKeyShared = $smsApiKey;
        } elseif ($smsKeyShared !== '' && $smsApiKey === '') {
            $smsApiKey = $smsKeyShared;
        }
        $smsKeyOtp    = melipayamakCleanKey(trim((string) ($_POST['sms_api_key_otp'] ?? '')));
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

        $smsOtpType = in_array($_POST['sms_otp_type'] ?? '', ['pattern', 'fast'], true) ? (string) $_POST['sms_otp_type'] : 'pattern';

        saveSetting($pdo, 'sms_enabled', $smsEnabled);
        saveSetting($pdo, 'sms_provider', $smsProvider);
        saveSetting($pdo, 'sms_username', $smsUsername);
        saveSetting($pdo, 'sms_password', $smsPassword);
        saveSetting($pdo, 'sms_api_key', $smsApiKey);
        saveSetting($pdo, 'sms_api_key_shared', $smsKeyShared);
        saveSetting($pdo, 'sms_api_key_otp', $smsKeyOtp);
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
        saveSetting($pdo, 'sms_otp_type', $smsOtpType);

        recordAudit('sms_settings.update', 'settings', null);
        setFlash('success', 'تنظیمات پنل پیامک با موفقیت ذخیره شد.');
        redirect(url('admin/settings.php#sms-section'));
    }

    if ($action === 'check_sms_connection') {
        $providerToCheck = trim((string) ($_POST['check_provider'] ?? ''));
        $usernameToCheck = trim((string) ($_POST['check_username'] ?? ''));
        $passwordToCheck = (string) ($_POST['check_password'] ?? '');
        $keyToCheck      = melipayamakCleanKey(trim((string) ($_POST['check_api_key'] ?? '')));

        $currentSettings = getSmsSettings($pdo);
        if ($providerToCheck === '') {
            $providerToCheck = (string) ($currentSettings['sms_provider'] ?? 'melipayamak_main');
        }
        if ($usernameToCheck === '') {
            $usernameToCheck = (string) ($currentSettings['sms_username'] ?? '');
        }
        if ($passwordToCheck === '') {
            $passwordToCheck = (string) ($currentSettings['sms_password'] ?? '');
        }
        if ($keyToCheck === '') {
            $keyToCheck = melipayamakConsoleKey($currentSettings, 'shared');
        }

        if ($providerToCheck === 'melipayamak_main') {
            $connRes = checkPayamakPanelCredit($usernameToCheck, $passwordToCheck);
        } else {
            $connRes = checkMelipayamakConnection($keyToCheck);
        }

        if ($connRes['success']) {
            setFlash('success', '✅ ' . $connRes['message']);
        } else {
            setFlash('error', '❌ خطا در اتصال به ملی‌پیامک: ' . $connRes['message']);
        }
        redirect(url('admin/settings.php#sms-section'));
    }

    if ($action === 'test_sms') {
        $testPhone = trim((string) ($_POST['test_phone'] ?? ''));
        $testBodyId = (int) ($_POST['test_body_id'] ?? 0);
        $testArg1 = trim((string) ($_POST['test_arg1'] ?? ''));
        $testArg2 = trim((string) ($_POST['test_arg2'] ?? ''));
        $testArg3 = trim((string) ($_POST['test_arg3'] ?? ''));
        $testArg4 = trim((string) ($_POST['test_arg4'] ?? ''));

        if (!isValidIranianMobile($testPhone)) {
            setFlash('error', 'شماره موبایل گیرنده تست نامعتبر است (مثال: 09123456789).');
            redirect(url('admin/settings.php#sms-section'));
        }

        if ($testBodyId <= 0) {
            setFlash('error', 'لطفاً شناسه الگوی معتبر (bodyId) برای تست وارد کنید.');
            redirect(url('admin/settings.php#sms-section'));
        }

        $rawArgs = [$testArg1, $testArg2, $testArg3, $testArg4];
        while (count($rawArgs) > 0 && end($rawArgs) === '') {
            array_pop($rawArgs);
        }
        if ($rawArgs === []) {
            $rawArgs = ['تست'];
        }

        $res = sendMeliPayamakPattern($testPhone, $testBodyId, $rawArgs, 'test');

        if ($res['success']) {
            $msg = "پیامک تست با موفقیت ارسال شد. کد پیگیری: " . ($res['recId'] ?? '-');
            $currentSettings = getSmsSettings($pdo);
            if (($currentSettings['sms_enabled'] ?? '0') !== '1') {
                $msg .= " (توجه: ارسال پیامک سیستمی هنوز خاموش است؛ برای فعال‌سازی کامل، تیک «فعال‌سازی ارسال پیامک‌های سیستمی» را بزنید و ذخیره کنید).";
            }
            setFlash('success', $msg);
        } else {
            setFlash('error', "خطا در ارسال پیامک تست: " . $res['status']);
        }
        redirect(url('admin/settings.php#sms-section'));
    }

    if ($action === 'test_otp_fast') {
        $testPhone = trim((string) ($_POST['test_phone'] ?? ''));
        if (!isValidIranianMobile($testPhone)) {
            setFlash('error', 'شماره موبایل گیرنده تست نامعتبر است (مثال: 09123456789).');
            redirect(url('admin/settings.php#sms-section'));
        }

        $res = sendMeliPayamakOtp($testPhone, 'test_otp');
        if ($res['success']) {
            $codeStr = $res['code'] ?? '';
            $msg = "⚡ پیامک کد تایید سریع (Flash OTP) با موفقیت ارسال شد! کد ارسالی: {$codeStr}. این پیامک از خط مستقیم پرسرعت ارسال شده و معمولاً ظرف چند ثانیه دریافت می‌شود.";
            $currentSettings = getSmsSettings($pdo);
            if (($currentSettings['sms_enabled'] ?? '0') !== '1') {
                $msg .= " (توجه: ارسال پیامک سیستمی هنوز خاموش است؛ برای فعال‌سازی کامل، تیک «فعال‌سازی ارسال پیامک‌های سیستمی» را بزنید و ذخیره کنید).";
            }
            setFlash('success', $msg);
        } else {
            setFlash('error', "خطا در ارسال سریع OTP: " . $res['status']);
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
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <div class="adm-page-title-row">
                <h1>تنظیمات سیستم</h1>
            </div>
            <p class="adm-page-subtitle">پیکربندی عمومی وب‌سایت، امنیت حساب مدیران و اتصال وب‌سرویس پیامک</p>
        </div>
    </div>

    <nav class="adm-section-nav" aria-label="بخش‌های تنظیمات">
        <a href="#settings-general" class="adm-section-nav-link is-active">تنظیمات عمومی</a>
        <a href="#settings-security" class="adm-section-nav-link">امنیت و رمز عبور</a>
        <a href="#sms-section" class="adm-section-nav-link">پنل پیامک و الگوها</a>
        <a href="#sms-test" class="adm-section-nav-link">تست زنده ارسال</a>
        <a href="#sms-logs" class="adm-section-nav-link">لاگ پیامک‌ها</a>
    </nav>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-settings-grid">
        <div class="admin-section" id="settings-general">
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
                                <div class="settings-map-preview">
                                    <iframe title="پیش‌نمایش نقشه" src="<?= e($previewEmbed) ?>"
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

        <div class="admin-section" id="settings-security">
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
        <div class="admin-section admin-section--full" id="sms-section">
            <div class="admin-section-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <h2 class="admin-section-title">تنظیمات پنل پیامک ملی‌پیامک (خط خدماتی اشتراکی / الگو)</h2>
                <span class="badge <?= ($settings['sms_enabled'] ?? '0') === '1' ? 'badge-success' : 'badge-secondary' ?>">
                    <?= ($settings['sms_enabled'] ?? '0') === '1' ? 'پیامک فعال است' : 'پیامک غیرفعال است' ?>
                </span>
            </div>
            <p class="adm-page-subtitle" style="margin-bottom:18px;">
                با اتصال به وب‌سرویس ملی‌پیامک، کدهای ورود/ثبت‌نام (OTP) و اعلانات مهدکودک (گزارش روزانه کودک، حضور و غیاب، یادآوری بدهی شهریه و اطلاعیه‌ها) از طریق خط خدماتی اشتراکی حتی برای شماره‌های بلک‌لیست به صورت آنی ارسال می‌شوند.
            </p>

            <form method="post" action="<?= e(url('admin/settings.php#sms-section')) ?>" novalidate style="margin-bottom:28px;">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_sms_settings">

                <div class="adm-panel-muted">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700;">
                            <input type="checkbox" name="sms_enabled" value="1" style="width:18px;height:18px;cursor:pointer;"
                                <?= ($settings['sms_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            فعال‌سازی ارسال پیامک‌های سیستمی
                        </label>
                        <small style="color:var(--muted);font-size:0.84rem;">در صورت غیرفعال بودن، پیام‌ها فقط به صورت نوتیفیکیشن درون پورتال ذخیره می‌شوند.</small>
                    </div>

                    <!-- Provider Choice -->
                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label" style="font-weight:700;font-size:0.94rem;">نوع سامانه و پنل ملی‌پیامک:</label>
                        <div class="adm-provider-grid">
                            <label class="adm-provider-option">
                                <input type="radio" name="sms_provider" value="melipayamak_main" onchange="toggleProviderFields(this.value);"
                                    <?= ($settings['sms_provider'] ?? 'melipayamak_main') === 'melipayamak_main' ? 'checked' : '' ?>>
                                <span><b>پنل اصلی ملی‌پیامک (<span dir="ltr">login.melipayamak.com</span>)</b> — ورود با نام کاربری و رمز</span>
                            </label>
                            <label class="adm-provider-option">
                                <input type="radio" name="sms_provider" value="melipayamak_console" onchange="toggleProviderFields(this.value);"
                                    <?= ($settings['sms_provider'] ?? 'melipayamak_main') === 'melipayamak_console' ? 'checked' : '' ?>>
                                <span><b>کنسول ملی‌پیامک (<span dir="ltr">console.melipayamak.com</span>)</b> — ورود با کلید API</span>
                            </label>
                        </div>
                    </div>

                    <!-- FIELDS FOR MAIN PANEL (login.melipayamak.com) -->
                    <div id="provider_main_fields" class="adm-provider-box" style="display:<?= ($settings['sms_provider'] ?? 'melipayamak_main') === 'melipayamak_main' ? 'block' : 'none' ?>;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
                            <div class="form-group" style="margin:0;">
                                <label for="sms_username" class="form-label">
                                    نام کاربری پنل اصلی (login.melipayamak.com) <span class="req">*</span>
                                </label>
                                <input type="text" id="sms_username" name="sms_username" class="form-control" dir="ltr"
                                    placeholder="شماره موبایل یا یوزرنیم پنل"
                                    value="<?= e($settings['sms_username'] ?? '') ?>">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label for="sms_password" class="form-label">
                                    کلمه عبور پنل اصلی <span class="req">*</span>
                                </label>
                                <input type="password" id="sms_password" name="sms_password" class="form-control" dir="ltr"
                                    placeholder="<?= !empty($settings['sms_password']) ? '•••••••• (رمز قبلاً ذخیره شده)' : 'رمز عبور ورود به پنل' ?>"
                                    autocomplete="current-password">
                            </div>
                        </div>
                        <small style="color:var(--muted);font-size:0.82rem;margin-top:8px;display:block;line-height:1.7;">
                            همان نام کاربری و رمزی که با آن وارد سایت <b>login.melipayamak.com</b> می‌شوید. تمام الگوهای تاییدشده شما در وب‌سرویس خدماتی پنل اصلی بدون درج هیچ نام سایت متفرقه‌ای ارسال خواهند شد.
                        </small>
                    </div>

                    <!-- FIELDS FOR CONSOLE (console.melipayamak.com) -->
                    <div id="provider_console_fields" class="adm-provider-box" style="display:<?= ($settings['sms_provider'] ?? 'melipayamak_main') === 'melipayamak_console' ? 'block' : 'none' ?>;">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label for="sms_api_key" class="form-label" style="font-weight:700;">
                                کلید وب‌سرویس کنسول (Console API Key) <span class="req">*</span>
                            </label>
                            <input type="text" id="sms_api_key" name="sms_api_key" class="form-control" dir="ltr" style="font-family:monospace;"
                                placeholder="کپی از کنسول ملی‌پیامک (مثال: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)"
                                value="<?= e($settings['sms_api_key'] ?? ($settings['sms_api_key_shared'] ?? '')) ?>">
                            <small style="color:var(--muted);font-size:0.82rem;margin-top:5px;display:block;">
                                کلید وب‌سرویس دریافتی از <b>console.melipayamak.com</b> (بخش کلیدهای وب‌سرویس).
                            </small>
                        </div>

                        <details style="margin-bottom:4px;background:var(--adm-surface-subtle);border:1px dashed var(--adm-border);border-radius:var(--adm-radius-xs);padding:8px 12px;">
                            <summary style="cursor:pointer;font-size:0.82rem;color:var(--muted);font-weight:600;">تنظیمات پیشرفته کنسول (کلید اختصاصی یکبارمصرف)</summary>
                            <div style="margin-top:8px;">
                                <label for="sms_api_key_otp" class="form-label" style="font-size:0.8rem;">کلید اختصاصی یکبارمصرف کنسول</label>
                                <input type="text" id="sms_api_key_otp" name="sms_api_key_otp" class="form-control" dir="ltr"
                                    placeholder="اختیاری"
                                    value="<?= e($settings['sms_api_key_otp'] ?? '') ?>">
                            </div>
                        </details>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700;">
                            <input type="checkbox" name="sms_detailed_report_enabled" value="1" style="width:18px;height:18px;cursor:pointer;"
                                <?= ($settings['sms_detailed_report_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            گزارش روزانه تفصیلی برای همه (حالت کلی)
                        </label>
                        <small style="color:var(--muted);font-size:0.84rem;">خاموش = همه والدین پیامک خلاصه ارجاع به پنل می‌گیرند. روشن = همه والدین پیامک تفصیلی می‌گیرند. هزینه پیامک از جیب مهد است و به شهریه اضافه نمی‌شود؛ در گزارش مالی بخش خدمات جانبی ثبت می‌شود.</small>
                    </div>
                </div>

                <h3 style="font-size:1.05rem;margin-bottom:16px;color:var(--adm-text);border-bottom:1px dashed var(--adm-border);padding-bottom:8px;">
                    شناسه‌های الگوهای متنی تایید شده در ملی‌پیامک (با لحن گرم و کودک‌محور)
                </h3>

                <div class="sms-pattern-grid">
                    <div class="form-group adm-pattern-highlight">
                        <label for="sms_pattern_otp" class="form-label" style="font-weight:700;font-size:0.95rem;">
                            ۱. کد تایید ورود و ثبت‌نام (OTP)
                        </label>
                        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                            <input type="number" id="sms_pattern_otp" name="sms_pattern_otp" class="form-control" dir="ltr" style="max-width:200px;"
                                placeholder="کد متن تاییدشده" value="<?= e($settings['sms_pattern_otp'] ?? '') ?>">
                            <small style="color:var(--muted);font-size:0.8rem;line-height:1.9;">کد متن تاییدشده از «ابزار ویژه ← وبسرویس خدماتی»<br>متغیر: <code dir="ltr">{0}</code>=کد تایید ۵ رقمی (فقط همین یک متغیر)<br>متن دقیقی که باید در پنل ملی‌پیامک ثبت کنید:<br><code>کد ورود شما به خانه پرمهر مهدکودک روما: {0} با آرزوی روزی شاد برای دلبندتان</code><br>توجه: متن خوش‌آمدگویی را در این الگو ثبت نکنید؛ در غیر این صورت کد تایید با متن اشتباه ارسال می‌شود.</small>
                        </div>
                        <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--adm-border);">
                            <label class="form-label" style="font-weight:600;font-size:0.82rem;margin-bottom:8px;">روش ارسال پیامک کد تایید به والدین:</label>
                            <div class="adm-provider-grid">
                                <label class="adm-provider-option">
                                    <input type="radio" name="sms_otp_type" value="pattern" <?= ($settings['sms_otp_type'] ?? 'pattern') === 'pattern' ? 'checked' : '' ?>>
                                    <span><b>الگوی اختصاصی مهدکودک روما</b> (با نام و لحن دلخواه مهدکودک و بدون درج نام یا لینک سایت‌های دیگر — با کد متن بالا)</span>
                                </label>
                                <label class="adm-provider-option">
                                    <input type="radio" name="sms_otp_type" value="fast" <?= ($settings['sms_otp_type'] ?? 'pattern') === 'fast' ? 'checked' : '' ?>>
                                    <span><b>سرویس فوق‌سریع ملی‌پیامک (Flash OTP)</b> (ارسال در کمتر از ۵ ثانیه — با متن پیش‌فرض پنل ملی‌پیامک شما)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_welcome" class="form-label">
                            ۲. خوش‌آمدگویی و تایید حساب کاربری والد
                        </label>
                        <input type="number" id="sms_pattern_welcome" name="sms_pattern_welcome" class="form-control" dir="ltr"
                            placeholder="مثال: 525" value="<?= e($settings['sms_pattern_welcome'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;line-height:1.9;">متغیر: <code dir="ltr">{0}</code>=نام والد (نام و نام خانوادگی؛ بعد از ثبت‌نام به او نمایش داده می‌شود)<br>متن دقیقی که باید در پنل ملی‌پیامک ثبت کنید:<br><code>{0} عزیز، با عشق و افتخار به جمع پرمهر مهدکودک روما خوش آمدید.</code><br>توجه: کد تایید در این الگو قرار نمی‌گیرد؛ این الگو فقط برای خوش‌آمدگویی است.</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_daily_report_brief" class="form-label">
                            ۳. گزارش روزانه خلاصه (ارجاع به پنل)
                        </label>
                        <input type="number" id="sms_pattern_daily_report_brief" name="sms_pattern_daily_report_brief" class="form-control" dir="ltr"
                            placeholder="مثال: 530" value="<?= e($settings['sms_pattern_daily_report_brief'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام کودک</code>، <code>{1}=نام مهد</code><br>نمونه: مامان و بابای مهربون {0}، گزارش روز قشنگ دلبندتون در پنل {1} ثبت شد.</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_daily_report" class="form-label">
                            ۴. گزارش روزانه تفصیلی
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
                            ۸. رسید پرداخت شهریه (اعتمادساز مالی)
                        </label>
                        <input type="number" id="sms_pattern_payment_receipt" name="sms_pattern_payment_receipt" class="form-control" dir="ltr"
                            placeholder="مثال: 531" value="<?= e($settings['sms_pattern_payment_receipt'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام والد</code>، <code>{1}=نام کودک</code>، <code>{2}=مبلغ</code>، <code>{3}=ماه</code><br>نمونه: {0} مهربان، پرداخت {2} تومان شهریه ماه {3} برای {1} با سپاس ثبت شد. مهد روما</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_pattern_child_status" class="form-label">
                            ۹. تغییر وضعیت ثبت‌نام کودک
                        </label>
                        <input type="number" id="sms_pattern_child_status" name="sms_pattern_child_status" class="form-control" dir="ltr"
                            placeholder="مثال: 532" value="<?= e($settings['sms_pattern_child_status'] ?? '') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">متغیرها: <code>{0}=نام والد</code>، <code>{1}=نام کودک</code>، <code>{2}=وضعیت جدید</code><br>نمونه: {0} مهربان، وضعیت ثبت‌نام {1} به «{2}» تغییر یافت. مهد روما</small>
                    </div>

                    <div class="form-group">
                        <label for="sms_inline_budget_seconds" class="form-label">
                            حداکثر زمان ارسال درون‌خطی (ثانیه)
                        </label>
                        <input type="number" id="sms_inline_budget_seconds" name="sms_inline_budget_seconds" class="form-control" dir="ltr"
                            min="0" max="25" placeholder="8" value="<?= e($settings['sms_inline_budget_seconds'] ?? '8') ?>">
                        <small style="color:var(--muted);font-size:0.8rem;">
                            هنگام ثبت گزارش یا حضور و غیاب، سیستم حداکثر این مدت را صرف ارسال آنی پیامک‌ها می‌کند و باقی صف را به کرون می‌سپارد تا صفحه هرگز کند نشود. مقدار پیشنهادی: ۸ ثانیه.
                        </small>
                    </div>
                </div>

                <div class="form-actions" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">ذخیره تنظیمات پیامک</button>
                    <button type="button" class="btn btn-secondary" onclick="checkMelipayamakLive();">
                        بررسی اتصال و استعلام اعتبار پنل
                    </button>
                </div>
            </form>

            <form method="post" action="<?= e(url('admin/settings.php#sms-section')) ?>" id="sms_check_conn_form" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="check_sms_connection">
                <input type="hidden" name="check_provider" id="sms_check_provider_hidden" value="">
                <input type="hidden" name="check_username" id="sms_check_username_hidden" value="">
                <input type="hidden" name="check_password" id="sms_check_password_hidden" value="">
                <input type="hidden" name="check_api_key" id="sms_check_api_key_hidden" value="">
            </form>

            <!-- Test SMS Form -->
            <div class="adm-test-box" id="sms-test">
                <h3 style="font-size:1rem;color:var(--adm-primary);margin-bottom:8px;">تست زنده ارسال پیامک خدماتی با الگو</h3>
                <p style="font-size:0.85rem;color:var(--adm-text-muted);margin-bottom:14px;">برای اطمینان از صحت وب‌سرویس و شناسه الگو، یک پیامک تستی به شماره دلخواه ارسال کنید.</p>
                <?php
                $isMainMode = ($settings['sms_provider'] ?? 'melipayamak_main') === 'melipayamak_main';
                $hasMainCreds = !empty(trim((string)($settings['sms_username'] ?? '')));
                $hasConsoleCreds = !empty(trim((string)($settings['sms_api_key'] ?? ($settings['sms_api_key_shared'] ?? ''))));
                $hasAnyCreds = $isMainMode ? $hasMainCreds : $hasConsoleCreds;
                ?>
                <?php if (!$hasAnyCreds): ?>
                    <div class="adm-callout adm-callout--danger">
                        <div class="adm-callout-body">
                            <strong>اطلاعات ورود به سامانه ثبت نشده است:</strong> لطفاً ابتدا در کادر بالا <?= $isMainMode ? '«نام کاربری و کلمه عبور پنل اصلی (login.melipayamak.com)»' : '«کلید وب‌سرویس»' ?> را وارد کرده و دکمه <strong>«ذخیره تنظیمات پیامک»</strong> را بزنید، سپس پیامک تست بفرستید.
                        </div>
                    </div>
                <?php elseif (($settings['sms_enabled'] ?? '0') !== '1'): ?>
                    <div class="adm-callout adm-callout--warning">
                        <div class="adm-callout-body">
                            <strong>ارسال پیامک‌های سیستمی غیرفعال است:</strong> شما می‌توانید تست ارسال را انجام دهید تا از صحت الگو و وب‌سرویس مطمئن شوید؛ اما برای فعال شدن ارسال خودکار پیامک‌ها به والدین و پرسنل، تیک <strong>«فعال‌سازی ارسال پیامک‌های سیستمی»</strong> را در فرم بالا بزنید و ذخیره کنید.
                        </div>
                    </div>
                <?php endif; ?>

                <div style="margin-bottom:12px;">
                    <label for="test_pattern_selector" class="form-label" style="font-size:0.82rem;font-weight:600;">انتخاب سریع از الگوهای مهد (برای پر شدن خودکار شناسه و نمونه متن):</label>
                    <select id="test_pattern_selector" class="form-control" style="font-size:0.85rem;" onchange="applyTestPatternPreset(this);">
                        <option value="">-- انتخاب رویداد جهت تست آسان --</option>
                        <option value="otp" data-id="<?= e($settings['sms_pattern_otp'] ?? '') ?>" data-v1="12345" data-v2="" data-v3="" data-v4="" data-l1="کد تایید" data-l2="" data-l3="" data-l4="">۱. کد تایید ورود و ثبت‌نام (۱ متغیر: کد تایید)</option>
                        <option value="welcome" data-id="<?= e($settings['sms_pattern_welcome'] ?? '') ?>" data-v1="سارا احمدی" data-v2="" data-v3="" data-v4="" data-l1="نام والد" data-l2="" data-l3="" data-l4="">۲. خوش‌آمدگویی والد (۱ متغیر: نام والد)</option>
                        <option value="daily_brief" data-id="<?= e($settings['sms_pattern_daily_report_brief'] ?? '') ?>" data-v1="کیان" data-v2="مهد روما" data-v3="" data-v4="" data-l1="نام کودک" data-l2="نام مهد" data-l3="" data-l4="">۳. گزارش روزانه خلاصه (۲ متغیر: نام کودک، نام مهد)</option>
                        <option value="daily_vip" data-id="<?= e($settings['sms_pattern_daily_report'] ?? '') ?>" data-v1="کیان" data-v2="۱۴۰۳/۰۸/۰۱" data-v3="بسیار شاد و با انرژی" data-v4="" data-l1="نام کودک" data-l2="تاریخ" data-l3="حال‌وهوا" data-l4="">۴. گزارش روزانه تفصیلی (۳ متغیر: نام کودک، تاریخ، احوال)</option>
                        <option value="attendance" data-id="<?= e($settings['sms_pattern_attendance'] ?? '') ?>" data-v1="کیان" data-v2="۱۴۰۳/۰۸/۰۱" data-v3="حاضر" data-v4="" data-l1="نام کودک" data-l2="تاریخ" data-l3="وضعیت حضور" data-l4="">۵. اعلام حضور و غیاب (۳ متغیر: نام کودک، تاریخ، وضعیت)</option>
                        <option value="tuition" data-id="<?= e($settings['sms_pattern_tuition_reminder'] ?? '') ?>" data-v1="خانم احمدی" data-v2="کیان" data-v3="آبان" data-v4="۲,۵۰۰,۰۰۰" data-l1="نام والد" data-l2="نام کودک" data-l3="ماه" data-l4="مبلغ">۶. یادآوری شهریه (۴ متغیر: والد، کودک، ماه، مبلغ)</option>
                        <option value="announcement" data-id="<?= e($settings['sms_pattern_announcement'] ?? '') ?>" data-v1="خانم احمدی" data-v2="جشن روز جهانی کودک" data-v3="" data-v4="" data-l1="نام والد" data-l2="عنوان پیام" data-l3="" data-l4="">۷. اطلاعیه‌ها و پیام‌های مهد (۲ متغیر: نام والد، عنوان)</option>
                        <option value="payment" data-id="<?= e($settings['sms_pattern_payment_receipt'] ?? '') ?>" data-v1="خانم احمدی" data-v2="کیان" data-v3="۲,۵۰۰,۰۰۰" data-v4="آبان" data-l1="نام والد" data-l2="نام کودک" data-l3="مبلغ" data-l4="ماه">۸. رسید پرداخت شهریه (۴ متغیر: والد، کودک، مبلغ، ماه)</option>
                        <option value="status" data-id="<?= e($settings['sms_pattern_child_status'] ?? '') ?>" data-v1="خانم احمدی" data-v2="کیان" data-v3="تأیید ثبت‌نام" data-v4="" data-l1="نام والد" data-l2="نام کودک" data-l3="وضعیت جدید" data-l4="">۹. تغییر وضعیت پرونده کودک (۳ متغیر: والد، کودک، وضعیت)</option>
                    </select>
                </div>

                <form method="post" action="<?= e(url('admin/settings.php#sms-section')) ?>" class="sms-test-form">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="test_sms">

                    <div class="sms-test-field sms-test-field--phone">
                        <label class="form-label" style="font-size:0.82rem;">شماره گیرنده تست</label>
                        <input type="text" name="test_phone" class="form-control" dir="ltr" placeholder="09123456789" required>
                    </div>

                    <div class="sms-test-field sms-test-field--id">
                        <label class="form-label" style="font-size:0.82rem;">شناسه الگو (bodyId)</label>
                        <input type="number" id="test_body_id_input" name="test_body_id" class="form-control" dir="ltr" placeholder="کد متن واقعی" value="<?= e($settings['sms_pattern_otp'] ?: $settings['sms_pattern_welcome'] ?: '') ?>" required>
                    </div>

                    <div class="sms-test-field">
                        <label class="form-label" id="lbl_test_arg1" style="font-size:0.82rem;">متغیر اول ({0})</label>
                        <input type="text" id="test_arg1_input" name="test_arg1" class="form-control" placeholder="12345">
                    </div>

                    <div class="sms-test-field">
                        <label class="form-label" id="lbl_test_arg2" style="font-size:0.82rem;">متغیر دوم ({1})</label>
                        <input type="text" id="test_arg2_input" name="test_arg2" class="form-control" placeholder="اختیاری">
                    </div>

                    <div class="sms-test-field">
                        <label class="form-label" id="lbl_test_arg3" style="font-size:0.82rem;">متغیر سوم ({2})</label>
                        <input type="text" id="test_arg3_input" name="test_arg3" class="form-control" placeholder="اختیاری">
                    </div>

                    <div class="sms-test-field">
                        <label class="form-label" id="lbl_test_arg4" style="font-size:0.82rem;">متغیر چهارم ({3})</label>
                        <input type="text" id="test_arg4_input" name="test_arg4" class="form-control" placeholder="اختیاری">
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">
                            ارسال تست با الگو
                        </button>
                        <button type="submit" name="action" value="test_otp_fast" class="btn adm-btn-fast" title="ارسال کد از طریق خط مستقیم اختصاصی در ۲ تا ۵ ثانیه">
                            تست ارسال فوق‌سریع کد تایید (Flash OTP)
                        </button>
                    </div>
                </form>
            </div>

            <script>
            function toggleProviderFields(provider) {
                var mainBox = document.getElementById('provider_main_fields');
                var consoleBox = document.getElementById('provider_console_fields');
                if (provider === 'melipayamak_main') {
                    if (mainBox) mainBox.style.display = 'block';
                    if (consoleBox) consoleBox.style.display = 'none';
                } else {
                    if (mainBox) mainBox.style.display = 'none';
                    if (consoleBox) consoleBox.style.display = 'block';
                }
            }

            var smsApiKeyInput = document.getElementById('sms_api_key');
            if (smsApiKeyInput) {
                function sanitizeApiKeyField() {
                    var v = smsApiKeyInput.value.trim();
                    if (v.indexOf('/') !== -1) {
                        var parts = v.replace(/\/+$/, '').split('/');
                        smsApiKeyInput.value = parts[parts.length - 1].trim();
                    }
                }
                smsApiKeyInput.addEventListener('blur', sanitizeApiKeyField);
                smsApiKeyInput.addEventListener('change', sanitizeApiKeyField);
            }

            function checkMelipayamakLive() {
                var checkedRadio = document.querySelector('input[name="sms_provider"]:checked');
                var provider = checkedRadio ? checkedRadio.value : 'melipayamak_main';
                document.getElementById('sms_check_provider_hidden').value = provider;

                if (provider === 'melipayamak_main') {
                    var u = document.getElementById('sms_username');
                    var p = document.getElementById('sms_password');
                    document.getElementById('sms_check_username_hidden').value = u ? u.value.trim() : '';
                    document.getElementById('sms_check_password_hidden').value = p ? p.value : '';
                } else {
                    var keyInput = document.getElementById('sms_api_key');
                    var keyVal = keyInput ? keyInput.value.trim() : '';
                    if (keyVal.indexOf('/') !== -1) {
                        var parts = keyVal.replace(/\/+$/, '').split('/');
                        keyVal = parts[parts.length - 1].trim();
                        if (keyInput) keyInput.value = keyVal;
                    }
                    document.getElementById('sms_check_api_key_hidden').value = keyVal;
                }
                document.getElementById('sms_check_conn_form').submit();
            }

            function applyTestPatternPreset(sel) {
                var opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.value) return;

                var pId = opt.getAttribute('data-id');
                if (pId) {
                    document.getElementById('test_body_id_input').value = pId;
                }

                var v1 = opt.getAttribute('data-v1') || '';
                var v2 = opt.getAttribute('data-v2') || '';
                var v3 = opt.getAttribute('data-v3') || '';
                var v4 = opt.getAttribute('data-v4') || '';

                var l1 = opt.getAttribute('data-l1');
                var l2 = opt.getAttribute('data-l2');
                var l3 = opt.getAttribute('data-l3');
                var l4 = opt.getAttribute('data-l4');

                document.getElementById('test_arg1_input').value = v1;
                document.getElementById('test_arg2_input').value = v2;
                document.getElementById('test_arg3_input').value = v3;
                document.getElementById('test_arg4_input').value = v4;

                document.getElementById('lbl_test_arg1').innerText = l1 ? l1 + ' ({0})' : 'متغیر اول ({0})';
                document.getElementById('lbl_test_arg2').innerText = l2 ? l2 + ' ({1})' : 'متغیر دوم ({1})';
                document.getElementById('lbl_test_arg3').innerText = l3 ? l3 + ' ({2})' : 'متغیر سوم ({2})';
                document.getElementById('lbl_test_arg4').innerText = l4 ? l4 + ' ({3})' : 'متغیر چهارم ({3})';
            }
            </script>

            <!-- Recent SMS Logs Table -->
            <h3 id="sms-logs" style="font-size:1.05rem;margin-bottom:16px;color:var(--adm-text);">
                آخرین پیامک‌های ارسالی سیستم (لاگ وضعیت)
            </h3>
            <?php if (!empty($recentSmsLogs)): ?>
                <div class="sms-logs-wrap">
                    <table class="table admin-table">
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
                                        <span class="badge badge-secondary">
                                            <?= e($log['message_type'] ?? 'pattern') ?>
                                        </span>
                                    </td>
                                    <td dir="ltr" style="text-align:right;"><?= e($log['pattern_body_id'] ?? '-') ?></td>
                                    <td dir="ltr" style="text-align:right;font-family:monospace;"><?= e($log['rec_id'] ?? '-') ?></td>
                                    <td>
                                        <?php if (($log['status'] ?? '') === 'sent'): ?>
                                            <span class="badge badge-success">موفق</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" title="<?= e($log['error_message'] ?? '') ?>">
                                                <?= e($log['error_message'] ?: ($log['status'] ?? 'ناموفق')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.78rem;color:var(--adm-text-muted);"><?= e(shamsiDbTimestamp((string)$log['created_at'], 'with_time')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="adm-panel-muted" style="text-align:center;color:var(--muted);font-size:0.88rem;margin-bottom:0;">
                    هنوز هیچ پیامکی از طریق سیستم ارسال نشده است.
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
