<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

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

function isValidProfilePhone(string $phone): bool
{
    return $phone === ''
        || (profileStringLength($phone) <= 15 && preg_match('/\A[0-9+\-\s]{7,15}\z/', $phone) === 1);
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

        if ($infoOld['phone'] === '' || !isValidProfilePhone($infoOld['phone'])) {
            $errors[] = 'لطفاً شماره تلفن معتبر وارد کنید.';
        }

        if ($errors === []) {
            $statement = $pdo->prepare(
                'UPDATE parents SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone WHERE id = :id'
            );
            $statement->execute([
                ':first_name' => $infoOld['first_name'],
                ':last_name'  => $infoOld['last_name'],
                ':email'      => $infoOld['email'] === '' ? null : $infoOld['email'],
                ':phone'      => $infoOld['phone'],
                ':id'         => $parentId,
            ]);

            $_SESSION['parent_name'] = trim($infoOld['first_name'] . ' ' . $infoOld['last_name']);
            $_SESSION['parent_first_name'] = $infoOld['first_name'];
            recordAudit('parent.update_profile', 'parent', $parentId);
            setFlash('success', 'پروفایل به‌روزرسانی شد.');
            redirect(url('parent/profile.php'));
        }
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
        $errors[] = 'Invalid request. Please try again.';
    }
}

$displayName = trim($infoOld['first_name'] . ' ' . $infoOld['last_name']);
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
                <?= e(userInitial($infoOld['first_name'])) ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="profile-info">
        <h1><?= e($displayName) ?></h1>
        <p class="profile-email" dir="ltr" style="text-align:right;font-family:monospace;"><?= !empty($infoOld['phone']) ? e(persianNumber($infoOld['phone'])) : e($infoOld['email']) ?></p>
        <span class="status-badge status-badge-<?= e($status) ?>"><?= e($statusLabel) ?></span>
    </div>
</div>

<div class="profile-forms-grid">
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
                    <?= e(userInitial($infoOld['first_name'])) ?>
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
