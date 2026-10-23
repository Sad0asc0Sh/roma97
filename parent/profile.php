<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/auth_flow.php';

requireParentLogin();

function profileStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function isValidProfileName(string $name): bool
{
    return $name !== ''
        && profileStringLength($name) <= 100
        && preg_match("/^[\p{L}\p{Z}\s'\-]+$/u", $name) === 1;
}

function isStrongProfilePassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

function getParentProfile(PDO $pdo, int $parentId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, first_name, last_name, email, phone, phone_verified, password, status, avatar,
                sms_pref_daily_report, sms_pref_attendance, sms_pref_tuition, sms_pref_announcements
         FROM parents WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $parentId]);
    $parent = $statement->fetch();

    return $parent ?: null;
}

function profileEmailExists(PDO $pdo, string $email, int $parentId): bool
{
    $statement = $pdo->prepare(
        'SELECT id FROM parents WHERE email = :email AND id <> :id LIMIT 1'
    );
    $statement->execute([
        ':email' => $email,
        ':id' => $parentId,
    ]);

    return (bool) $statement->fetch();
}

function profilePhoneExists(PDO $pdo, string $phone, int $parentId): bool
{
    $statement = $pdo->prepare(
        'SELECT id FROM parents WHERE phone = :phone AND id <> :id LIMIT 1'
    );
    $statement->execute([
        ':phone' => $phone,
        ':id' => $parentId,
    ]);

    return (bool) $statement->fetch();
}

/**
 * Return the staged phone-change request for this parent, or null when it is
 * missing, expired, or belongs to someone else.
 */
function getPendingPhoneChange(int $parentId): ?array
{
    $pending = $_SESSION['phone_change_pending'] ?? null;

    if (
        !is_array($pending)
        || (int) ($pending['parent_id'] ?? 0) !== $parentId
        || !is_string($pending['new_phone'] ?? null)
        || $pending['new_phone'] === ''
        || !is_string($pending['challenge'] ?? null)
        || (int) ($pending['expires_at'] ?? 0) <= time()
    ) {
        unset($_SESSION['phone_change_pending']);

        return null;
    }

    return $pending;
}

/**
 * Apply the staged profile update. A non-null $phone also marks it verified
 * (it was proven via SMS OTP) and records the phone-change audit event.
 */
function applyParentProfileUpdate(
    PDO $pdo,
    int $parentId,
    string $firstName,
    string $lastName,
    string $email,
    ?string $phone
): void {
    if ($phone === null) {
        $statement = $pdo->prepare(
            'UPDATE parents SET first_name = :first_name, last_name = :last_name, email = :email WHERE id = :id'
        );
        $statement->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email === '' ? null : $email,
            ':id'         => $parentId,
        ]);
        recordAudit('parent.update_profile', 'parent', $parentId);
    } else {
        $statement = $pdo->prepare(
            'UPDATE parents SET first_name = :first_name, last_name = :last_name, email = :email,
                phone = :phone, phone_verified = 1 WHERE id = :id'
        );
        $statement->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email === '' ? null : $email,
            ':phone'      => $phone,
            ':id'         => $parentId,
        ]);
        recordAudit('parent.update_profile', 'parent', $parentId);
        recordAudit('parent.phone_change', 'parent', $parentId);
    }

    $_SESSION['parent_name'] = trim($firstName . ' ' . $lastName);
    $_SESSION['parent_first_name'] = $firstName;
    setFlash('success', 'پروفایل به‌روزرسانی شد.');
    redirect(url('parent/profile.php'));
}

function deleteAvatarFile(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $avatarRoot = realpath(__DIR__ . '/../assets/uploads/avatars');

    if ($projectRoot === false || $avatarRoot === false) {
        return;
    }

    $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));

    if (
        $candidate !== false
        && str_starts_with($candidate, $avatarRoot . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        @unlink($candidate);
    }
}

function uploadAvatar(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('لطفاً یک تصویر پروفایل انتخاب کنید.');
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('بارگذاری تصویر پروفایل نامعتبر است.');
    }

    if (($file['size'] ?? 0) > 512000) {
        throw new RuntimeException('حجم تصویر پروفایل باید ۵۰۰ کیلوبایت یا کمتر باشد.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('بارگذاری تصویر پروفایل نامعتبر است.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
    ];

    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('تصویر پروفایل باید با فرمت JPG، PNG یا GIF باشد.');
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
            throw new RuntimeException('نوع تصویر پروفایل نامعتبر است.');
        }
    }

    if (getimagesize($tmpName) === false) {
        throw new RuntimeException('تصویر پروفایل نامعتبر است.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/avatars';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('بارگذاری تصویر پروفایل در دسترس نیست.');
    }

    $indexFile = $uploadDir . DIRECTORY_SEPARATOR . 'index.html';

    if (!is_file($indexFile)) {
        @touch($indexFile);
    }

    $htaccess = dirname($uploadDir) . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n");
    }

    $fileName = 'avatar-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('بارگذاری تصویر پروفایل در دسترس نیست.');
    }

    return 'assets/uploads/avatars/' . $fileName;
}

$parentId = (int) $_SESSION['parent_id'];
$errors = [];
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

try {
    initializeParentTables();
    $pdo = getDb();
    $parent = getParentProfile($pdo, $parentId);

    if ($parent === null) {
        unset($_SESSION['parent_id'], $_SESSION['parent_name']);
        redirect(url('login.php'));
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'پروفایل موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
    redirect(url('parent/index.php'));
}

$infoOld = [
    'first_name' => (string) $parent['first_name'],
    'last_name' => (string) $parent['last_name'],
    'email' => (string) $parent['email'],
    'phone' => (string) ($parent['phone'] ?? ''),
];

if (isPostRequest()) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif ($action === 'update_info') {
        $infoOld = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
        ];

        if (!isValidProfileName($infoOld['first_name'])) {
            $errors[] = 'لطفاً نام معتبر وارد کنید.';
        }

        if (!isValidProfileName($infoOld['last_name'])) {
            $errors[] = 'لطفاً نام خانوادگی معتبر وارد کنید.';
        }

        if ($infoOld['email'] !== '') {
            if (profileStringLength($infoOld['email']) > 150 || !filter_var($infoOld['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'لطفاً یک آدرس ایمیل معتبر وارد کنید یا فیلد ایمیل را خالی بگذارید.';
            } elseif (profileEmailExists($pdo, $infoOld['email'], $parentId)) {
                $errors[] = 'این آدرس ایمیل قبلاً توسط حساب دیگری استفاده شده است.';
            }
        }

        // The phone number is the login identifier: it must stay unique, and a
        // change only takes effect after an SMS OTP to the NEW number is verified.
        $phoneRaw = $infoOld['phone'];
        $newPhone = normalizeIranianPhone($phoneRaw);
        $currentPhone = normalizeIranianPhone((string) ($parent['phone'] ?? ''));
        $phoneChanged = $phoneRaw !== '' && $newPhone !== $currentPhone;

        if ($phoneRaw === '') {
            $errors[] = 'لطفاً شماره تلفن معتبر وارد کنید.';
        } elseif ($phoneChanged) {
            if (!isValidIranianMobile($newPhone)) {
                $errors[] = 'لطفاً شماره موبایل معتبر وارد کنید (مثال: 09123456789).';
            } elseif (profilePhoneExists($pdo, $newPhone, $parentId)) {
                $errors[] = 'این شماره موبایل قبلاً توسط حساب دیگری استفاده شده است.';
            }
        }

        if ($errors === []) {
            if (!$phoneChanged) {
                applyParentProfileUpdate(
                    $pdo,
                    $parentId,
                    $infoOld['first_name'],
                    $infoOld['last_name'],
                    $infoOld['email'],
                    null
                );
            }

            // Stage the whole update and require OTP verification on the new number.
            $staged = [
                'parent_id' => $parentId,
                'new_phone' => $newPhone,
                'first_name' => $infoOld['first_name'],
                'last_name' => $infoOld['last_name'],
                'email' => $infoOld['email'],
                'expires_at' => time() + 600,
                'challenge' => bin2hex(random_bytes(32)),
            ];
            $otp = generateAndSendSmsOtp($newPhone, 'phone_change', [
                'parent_id' => $parentId,
                'challenge' => $staged['challenge'],
            ]);

            if (!$otp['success']) {
                $errors[] = $otp['message'];
            } else {
                $_SESSION['phone_change_pending'] = $staged;
                setFlash('success', 'کد تأیید ۵ رقمی به شماره موبایل جدید شما ارسال شد. پس از وارد کردن کد، تغییرات اعمال می‌شود.');
                redirect(url('parent/profile.php#phone-verify'));
            }
        }
    } elseif ($action === 'verify_phone_change') {
        $pending = getPendingPhoneChange($parentId);

        if ($pending === null) {
            $errors[] = 'درخواست تأیید منقضی شده است. لطفاً دوباره تلاش کنید.';
        } elseif (!checkBruteForce('parent_phone_change', (string) $parentId)) {
            $errors[] = 'تعداد دفعات تلاش بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
        } else {
            $code = (string) ($_POST['otp_code'] ?? '');
            $result = verifySmsOtp($pending['new_phone'], $code, 'phone_change', $pending['challenge']);

            if (!$result['valid']) {
                recordFailedAttempt('parent_phone_change', (string) $parentId);
                $errors[] = $result['message'];
            } elseif (profilePhoneExists($pdo, $pending['new_phone'], $parentId)) {
                // Re-checked right before applying; the UNIQUE index is the final guard.
                unset($_SESSION['phone_change_pending']);
                $errors[] = 'این شماره موبایل قبلاً توسط حساب دیگری استفاده شده است.';
            } else {
                unset($_SESSION['phone_change_pending']);

                try {
                    applyParentProfileUpdate(
                        $pdo,
                        $parentId,
                        $pending['first_name'],
                        $pending['last_name'],
                        $pending['email'],
                        $pending['new_phone']
                    );
                } catch (PDOException $exception) {
                    error_log('Phone change failed for parent ' . $parentId . ': ' . $exception->getMessage());
                    $errors[] = 'تغییر شماره موبایل انجام نشد. لطفاً دوباره تلاش کنید.';
                }
            }
        }
    } elseif ($action === 'resend_phone_otp') {
        $pending = getPendingPhoneChange($parentId);

        if ($pending === null) {
            $errors[] = 'درخواست تأیید منقضی شده است. لطفاً دوباره تلاش کنید.';
        } elseif (!checkBruteForce('parent_phone_change_resend', (string) $parentId)) {
            $errors[] = 'تعداد دفعات تلاش بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر تلاش کنید.';
        } else {
            $otp = generateAndSendSmsOtp($pending['new_phone'], 'phone_change', [
                'parent_id' => $parentId,
                'challenge' => $pending['challenge'],
            ]);

            if (!$otp['success']) {
                $errors[] = $otp['message'];
            } else {
                $_SESSION['phone_change_pending']['expires_at'] = time() + 600;
                setFlash('success', 'کد تأیید جدید به شماره موبایل جدید شما ارسال شد.');
                redirect(url('parent/profile.php#phone-verify'));
            }
        }
    } elseif ($action === 'cancel_phone_change') {
        unset($_SESSION['phone_change_pending']);
        setFlash('success', 'تغییر شماره موبایل لغو شد.');
        redirect(url('parent/profile.php'));
    } elseif ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

        if (!password_verify($currentPassword, $parent['password'])) {
            $errors[] = 'رمز عبور تغییر نکرد. لطفاً اطلاعات وارد شده را بررسی کنید.';
        }

        if (!isStrongProfilePassword($newPassword)) {
            $errors[] = 'رمز عبور جدید باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.';
        }

        if ($newPassword !== $confirmNewPassword) {
            $errors[] = 'تکرار رمز عبور جدید مطابقت ندارد.';
        }

        if ($errors === []) {
            $statement = $pdo->prepare('UPDATE parents SET password = :password WHERE id = :id');
            $statement->execute([
                ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => $parentId,
            ]);

            recordAudit('auth.password_change', 'parent', $parentId);
            setFlash('success', 'رمز عبور تغییر کرد.');
            redirect(url('parent/profile.php'));
        }
    } elseif ($action === 'upload_avatar') {
        $newAvatar = null;

        try {
            $newAvatar = uploadAvatar($_FILES['avatar'] ?? []);
            $statement = $pdo->prepare('UPDATE parents SET avatar = :avatar WHERE id = :id');
            $statement->execute([
                ':avatar' => $newAvatar,
                ':id' => $parentId,
            ]);

            deleteAvatarFile($parent['avatar'] ?? null);
            setFlash('success', 'تصویر پروفایل به‌روزرسانی شد.');
            redirect(url('parent/profile.php'));
        } catch (Throwable $exception) {
            if ($newAvatar !== null) {
                deleteAvatarFile($newAvatar);
            }

            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'تصویر پروفایل به‌روزرسانی نشد.';
        }
    } elseif ($action === 'update_sms_preferences') {
        $prefDaily = isset($_POST['sms_pref_daily_report']) ? 1 : 0;
        $prefAtt   = isset($_POST['sms_pref_attendance']) ? 1 : 0;
        $prefTui   = isset($_POST['sms_pref_tuition']) ? 1 : 0;
        $prefAnn   = isset($_POST['sms_pref_announcements']) ? 1 : 0;

        $upStmt = $pdo->prepare('
            UPDATE parents
            SET sms_pref_daily_report = :daily,
                sms_pref_attendance = :att,
                sms_pref_tuition = :tui,
                sms_pref_announcements = :ann
            WHERE id = :id
        ');
        $upStmt->execute([
            ':daily' => $prefDaily,
            ':att'   => $prefAtt,
            ':tui'   => $prefTui,
            ':ann'   => $prefAnn,
            ':id'    => $parentId,
        ]);

        recordAudit('parent.update_sms_prefs', 'parent', $parentId);
        setFlash('success', 'تنظیمات دریافت پیامک‌های شما با موفقیت ذخیره شد.');
        redirect(url('parent/profile.php#sms-prefs'));
    } elseif ($action === 'remove_avatar') {
        $statement = $pdo->prepare('UPDATE parents SET avatar = NULL WHERE id = :id');
        $statement->execute([':id' => $parentId]);

        deleteAvatarFile($parent['avatar'] ?? null);
        setFlash('success', 'تصویر پروفایل حذف شد.');
        redirect(url('parent/profile.php'));
    } else {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }
}

$displayName = trim($infoOld['first_name'] . ' ' . $infoOld['last_name']);
$pendingPhoneChange = getPendingPhoneChange($parentId);
$status = (string) ($parent['status'] ?? 'pending');
$statusLabel = match ($status) {
    'pending' => 'در انتظار تأیید',
    'active' => 'فعال',
    'suspended' => 'مسدود شده',
    default => $status,
};
$pageTitle = 'پروفایل من';
require_once __DIR__ . '/header.php';
?>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert" role="alert">
        <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="profile-header">
    <div class="profile-avatar-section">
        <?php if (!empty($parent['avatar'])): ?>
            <img class="profile-avatar" src="<?= e(url($parent['avatar'])) ?>" alt="<?= e($displayName) ?>">
        <?php else: ?>
            <div class="profile-avatar profile-avatar-placeholder">
                <?= e(mb_substr(trim($infoOld['first_name'] ?: 'و'), 0, 1, 'UTF-8')) ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="profile-info">
        <h1><?= e($displayName) ?></h1>
        <div class="profile-meta">
            <?php if (!empty($infoOld['phone'])): ?>
                <span class="profile-contact-chip" aria-label="شماره موبایل">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    <span class="profile-contact-value" dir="ltr"><?= e(persianNumber($infoOld['phone'])) ?></span>
                    <?php if (!empty($parent['phone_verified'])): ?>
                        <svg class="profile-chip-check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?php endif; ?>
                </span>
            <?php elseif (!empty($infoOld['email'])): ?>
                <span class="profile-contact-chip" aria-label="ایمیل">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
                    <span class="profile-contact-value" dir="ltr"><?= e($infoOld['email']) ?></span>
                </span>
            <?php endif; ?>
            <span class="status-badge status-badge-<?= e($status) ?>"><?= e($statusLabel) ?></span>
        </div>
    </div>
</div>

<div class="profile-forms-grid">
    <?php if ($pendingPhoneChange !== null): ?>
    <!-- Phone change verification -->
    <div class="profile-card" id="phone-verify" style="grid-column: 1 / -1;border:2px solid var(--primary);">
        <h2>📲 تأیید شماره موبایل جدید</h2>
        <p style="line-height:1.9;">
            کد تأیید ۵ رقمی به شماره <strong dir="ltr"><?= e($pendingPhoneChange['new_phone']) ?></strong> ارسال شد.
            پس از وارد کردن کد، تغییر شماره موبایل و سایر ویرایش‌ها اعمال می‌شود. (مهلت: ۱۰ دقیقه)
        </p>
        <form method="post" action="<?= e(url('parent/profile.php#phone-verify')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="verify_phone_change">
            <div class="form-group">
                <label for="otp_code">کد تأیید</label>
                <input type="text" id="otp_code" name="otp_code" inputmode="numeric" maxlength="5" dir="ltr" required autocomplete="one-time-code" placeholder="۱۲۳۴۵">
            </div>
            <button class="btn btn-primary" type="submit">تأیید و اعمال تغییرات</button>
        </form>
        <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
            <form method="post" action="<?= e(url('parent/profile.php#phone-verify')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="resend_phone_otp">
                <button class="btn btn-outline" type="submit">ارسال مجدد کد</button>
            </form>
            <form method="post" action="<?= e(url('parent/profile.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="cancel_phone_change">
                <button class="btn btn-outline" type="submit">لغو تغییر شماره</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <!-- Personal Info -->
    <div class="profile-card">
        <h2>اطلاعات شخصی</h2>
        <form method="post" action="<?= e(url('parent/profile.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_info">

            <div class="form-group">
                <label for="first_name">نام</label>
                <input type="text" id="first_name" name="first_name" value="<?= e($infoOld['first_name']) ?>" required>
            </div>

            <div class="form-group">
                <label for="last_name">نام خانوادگی</label>
                <input type="text" id="last_name" name="last_name" value="<?= e($infoOld['last_name']) ?>" required>
            </div>

            <div class="form-group">
                <label for="phone">شماره تلفن همراه (شناسه اصلی ورود و پیامک) <span style="color:red">*</span></label>
                <input type="tel" id="phone" name="phone" value="<?= e($infoOld['phone']) ?>" placeholder="09123456789" required dir="ltr">
            </div>

            <div class="form-group">
                <label for="email">آدرس ایمیل <small style="color:var(--muted);font-weight:normal;">(اختیاری)</small></label>
                <input type="email" id="email" name="email" value="<?= e($infoOld['email']) ?>" placeholder="اختیاری" dir="ltr">
            </div>

            <button class="btn btn-primary" type="submit">به‌روزرسانی اطلاعات</button>
        </form>
    </div>

    <!-- Change Password -->
    <div class="profile-card">
        <h2>تغییر رمز عبور</h2>
        <form method="post" action="<?= e(url('parent/profile.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
                <label for="current_password">رمز عبور فعلی</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>

            <div class="form-group">
                <label for="new_password">رمز عبور جدید</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                <small>حداقل ۸ کاراکتر شامل حرف و عدد</small>
            </div>

            <div class="form-group">
                <label for="confirm_new_password">تکرار رمز عبور جدید</label>
                <input type="password" id="confirm_new_password" name="confirm_new_password" autocomplete="new-password" required>
            </div>

            <button class="btn btn-primary" type="submit">تغییر رمز عبور</button>
        </form>
    </div>

    <!-- Profile Picture -->
    <div class="profile-card">
        <h2>تصویر پروفایل</h2>
        <div class="avatar-preview">
            <?php if (!empty($parent['avatar'])): ?>
                <img class="profile-avatar-large" src="<?= e(url($parent['avatar'])) ?>" alt="<?= e($displayName) ?>">
            <?php else: ?>
                <div class="profile-avatar-large profile-avatar-placeholder">
                    <?= e(mb_substr(trim($infoOld['first_name'] ?: 'و'), 0, 1, 'UTF-8')) ?>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" action="<?= e(url('parent/profile.php')) ?>" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="upload_avatar">

            <div class="form-group">
                <label for="avatar">بارگذاری تصویر جدید</label>
                <input type="file" id="avatar" name="avatar" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" required>
                <small>فرمت‌های JPG، PNG یا GIF. حداکثر ۵۰۰ کیلوبایت</small>
            </div>

            <button class="btn btn-primary" type="submit">به‌روزرسانی تصویر</button>
        </form>

        <?php if (!empty($parent['avatar'])): ?>
            <form method="post" action="<?= e(url('parent/profile.php')) ?>" onsubmit="return confirm('آیا می‌خواهید تصویر پروفایل خود را حذف کنید؟');" class="margin-top-md">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="remove_avatar">
                <button class="btn btn-outline" type="submit">حذف تصویر</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- SMS Notification Preferences Card -->
    <div class="profile-card" id="sms-prefs" style="grid-column: 1 / -1;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <h2 style="margin:0;">📱 تنظیمات دریافت پیامک و اعلانات هوشمند</h2>
            <span style="font-size:0.82rem;background:rgba(46,133,64,0.1);color:#1e632b;padding:4px 10px;border-radius:20px;font-weight:600;">
                شماره پیامک: <?= !empty($parent['phone']) ? e(persianNumber($parent['phone'])) : 'ثبت نشده' ?>
                <?= ((int)($parent['phone_verified'] ?? 0) === 1) ? ' (تایید شده ✅)' : '' ?>
            </span>
        </div>
        <p style="color:var(--muted);font-size:0.88rem;margin-bottom:20px;line-height:1.8;">
            شما می‌توانید انتخاب کنید که کدام رویدادها و گزارش‌های مهدکودک به صورت پیامک فوری برای شماره موبایل شما ارسال شوند:
        </p>

        <form method="post" action="<?= e(url('parent/profile.php#sms-prefs')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_sms_preferences">

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;margin-bottom:20px;">
                <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:var(--surface-hover,rgba(0,0,0,0.02));border:1px solid var(--border);border-radius:10px;cursor:pointer;">
                    <input type="checkbox" name="sms_pref_daily_report" value="1" style="width:20px;height:20px;margin-top:2px;cursor:pointer;"
                        <?= ((int)($parent['sms_pref_daily_report'] ?? 1) === 1) ? 'checked' : '' ?>>
                    <div>
                        <strong style="display:block;font-size:0.95rem;color:var(--text);margin-bottom:4px;">🍲 گزارش روزانه مهدکودک</strong>
                        <span style="font-size:0.82rem;color:var(--muted);line-height:1.6;display:block;">ارسال پیامک ثبت گزارش روزانه (تغذیه، خواب، احوال) پس از ثبت توسط مربی</span>
                    </div>
                </label>

                <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:var(--surface-hover,rgba(0,0,0,0.02));border:1px solid var(--border);border-radius:10px;cursor:pointer;">
                    <input type="checkbox" name="sms_pref_attendance" value="1" style="width:20px;height:20px;margin-top:2px;cursor:pointer;"
                        <?= ((int)($parent['sms_pref_attendance'] ?? 1) === 1) ? 'checked' : '' ?>>
                    <div>
                        <strong style="display:block;font-size:0.95rem;color:var(--text);margin-bottom:4px;">⏱️ حضور و غیاب</strong>
                        <span style="font-size:0.82rem;color:var(--muted);line-height:1.6;display:block;">اطلاع‌رسانی پیامکی هنگام ورود کودک به مهد یا ثبت غیبت روزانه</span>
                    </div>
                </label>

                <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:var(--surface-hover,rgba(0,0,0,0.02));border:1px solid var(--border);border-radius:10px;cursor:pointer;">
                    <input type="checkbox" name="sms_pref_tuition" value="1" style="width:20px;height:20px;margin-top:2px;cursor:pointer;"
                        <?= ((int)($parent['sms_pref_tuition'] ?? 1) === 1) ? 'checked' : '' ?>>
                    <div>
                        <strong style="display:block;font-size:0.95rem;color:var(--text);margin-bottom:4px;">💳 یادآوری و تسویه شهریه</strong>
                        <span style="font-size:0.82rem;color:var(--muted);line-height:1.6;display:block;">ارسال پیامک خودکار یادآوری شهریه ماهانه و رسید ثبت پرداخت</span>
                    </div>
                </label>

                <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:var(--surface-hover,rgba(0,0,0,0.02));border:1px solid var(--border);border-radius:10px;cursor:pointer;">
                    <input type="checkbox" name="sms_pref_announcements" value="1" style="width:20px;height:20px;margin-top:2px;cursor:pointer;"
                        <?= ((int)($parent['sms_pref_announcements'] ?? 1) === 1) ? 'checked' : '' ?>>
                    <div>
                        <strong style="display:block;font-size:0.95rem;color:var(--text);margin-bottom:4px;">📢 اطلاعیه‌ها و رویدادهای مهد</strong>
                        <span style="font-size:0.82rem;color:var(--muted);line-height:1.6;display:block;">اطلاع‌رسانی پیامکی جشن‌ها، اردوها، تعطیلات و پیام‌های مستقیم مدیریت مهد</span>
                    </div>
                </label>
            </div>

            <p style="font-size:0.82rem;color:var(--muted);margin:0 0 20px;line-height:1.8;">نوع پیامک گزارش روزانه توسط مدیریت مهد تنظیم می‌شود.</p>

            <button class="btn btn-primary" type="submit">💾 ذخیره اولویت‌های پیامک</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
