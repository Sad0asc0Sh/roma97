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
require_once __DIR__ . '/../includes/admin_family_helpers.php';

requireLogin();

function parseAdminParentId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($id) ? $id : 0;
}

$parentId = parseAdminParentId($_GET['id'] ?? $_POST['parent_id'] ?? null);
if ($parentId === 0) {
    setFlash('error', 'شناسه والد مشخص نشده است.');
    redirect(url('admin/parents.php'));
}

$errors = [];
$parent = null;
$parentChildren = [];

try {
    initializeParentTables();
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM parents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $parentId]);
    $parent = $stmt->fetch() ?: null;
    if ($parent) {
        $chStmt = $pdo->prepare('SELECT id, first_name, last_name, status FROM children WHERE parent_id = :pid ORDER BY created_at ASC');
        $chStmt->execute([':pid' => $parentId]);
        $parentChildren = $chStmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'بارگذاری اطلاعات والد امکان‌پذیر نیست.');
    redirect(url('admin/parents.php'));
}

if (!$parent) {
    setFlash('error', 'والد یافت نشد.');
    redirect(url('admin/parents.php'));
}

$old = [
    'first_name' => (string) ($parent['first_name'] ?? ''),
    'last_name' => (string) ($parent['last_name'] ?? ''),
    'phone' => (string) ($parent['phone'] ?? ''),
    'email' => (string) ($parent['email'] ?? ''),
    'status' => (string) ($parent['status'] ?? 'pending'),
    'phone_verified' => (int) ($parent['phone_verified'] ?? 0),
    'sms_pref_daily_report' => (int) ($parent['sms_pref_daily_report'] ?? 1),
    'sms_pref_attendance' => (int) ($parent['sms_pref_attendance'] ?? 1),
    'sms_pref_tuition' => (int) ($parent['sms_pref_tuition'] ?? 1),
    'sms_pref_announcements' => (int) ($parent['sms_pref_announcements'] ?? 1),
];

if (isPostRequest()) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $old = [
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'phone' => normalizeIranianPhone(trim((string) ($_POST['phone'] ?? ''))),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'status' => in_array($_POST['status'] ?? '', ['pending', 'active', 'suspended'], true) ? (string) $_POST['status'] : (string) $parent['status'],
        'phone_verified' => isset($_POST['phone_verified']) ? 1 : 0,
        'sms_pref_daily_report' => isset($_POST['sms_pref_daily_report']) ? 1 : 0,
        'sms_pref_attendance' => isset($_POST['sms_pref_attendance']) ? 1 : 0,
        'sms_pref_tuition' => isset($_POST['sms_pref_tuition']) ? 1 : 0,
        'sms_pref_announcements' => isset($_POST['sms_pref_announcements']) ? 1 : 0,
    ];
    $newPassword = (string) ($_POST['password'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }
    if (!adminFamilyIsValidParentName($old['first_name'])) {
        $errors[] = 'لطفاً نام معتبر وارد کنید.';
    }
    if (!adminFamilyIsValidParentName($old['last_name'])) {
        $errors[] = 'لطفاً نام خانوادگی معتبر وارد کنید.';
    }
    if (!isValidIranianMobile($old['phone'])) {
        $errors[] = 'لطفاً شماره موبایل معتبر وارد کنید (مثال: 09123456789).';
    }
    if ($old['email'] !== '' && (adminFamilyStringLength($old['email']) > 150 || !filter_var($old['email'], FILTER_VALIDATE_EMAIL))) {
        $errors[] = 'لطفاً یک آدرس ایمیل معتبر وارد کنید یا فیلد ایمیل را خالی بگذارید.';
    }
    if ($newPassword !== '' && !adminFamilyIsStrongPassword($newPassword)) {
        $errors[] = 'رمز عبور جدید باید ۸ تا ۷۲ بایت و شامل حرف انگلیسی و عدد باشد.';
    }

    if ($errors === []) {
        try {
            if (adminFamilyParentPhoneExists($pdo, $old['phone'], $parentId)) {
                $errors[] = 'والد دیگری با این شماره موبایل قبلاً ثبت شده است.';
            } elseif ($old['email'] !== '' && adminFamilyParentEmailExists($pdo, $old['email'], $parentId)) {
                $errors[] = 'والد دیگری با این ایمیل قبلاً ثبت شده است.';
            } else {
                $params = [
                    ':fn' => $old['first_name'],
                    ':ln' => $old['last_name'],
                    ':em' => $old['email'] !== '' ? $old['email'] : null,
                    ':ph' => $old['phone'],
                    ':pv' => $old['phone_verified'],
                    ':st' => $old['status'],
                    ':d' => $old['sms_pref_daily_report'],
                    ':a' => $old['sms_pref_attendance'],
                    ':t' => $old['sms_pref_tuition'],
                    ':an' => $old['sms_pref_announcements'],
                    ':id' => $parentId,
                ];
                $pwClause = '';
                if ($newPassword !== '') {
                    $pwClause = ', password = :pw';
                    $params[':pw'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }
                $upd = $pdo->prepare(
                    "UPDATE parents SET first_name = :fn, last_name = :ln, email = :em, phone = :ph,
                        phone_verified = :pv, status = :st,
                        sms_pref_daily_report = :d, sms_pref_attendance = :a,
                        sms_pref_tuition = :t, sms_pref_announcements = :an{$pwClause}
                     WHERE id = :id"
                );
                $upd->execute($params);
                recordAudit('parent.update', 'parent', $parentId, [
                    'source' => 'admin_manual',
                    'status' => $old['status'],
                    'phone_verified' => $old['phone_verified'],
                    'password_changed' => $newPassword !== '',
                ]);
                setFlash('success', 'اطلاعات والد با موفقیت به‌روزرسانی شد.');
                redirect(url('admin/parents.php'));
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $errors[] = 'به‌روزرسانی والد با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
        }
    }
}

$fullName = trim($old['first_name'] . ' ' . $old['last_name']);
$pageTitle = 'ویرایش والد | ' . $fullName;
require_once __DIR__ . '/header.php';
$flashSuccess = getFlash('success');
$flashError = getFlash('error');
?>
<section class="dashboard">
    <h1>ویرایش والد</h1>
    <p><a href="<?= e(url('admin/parents.php')) ?>">→ بازگشت به مدیریت والدین</a></p>

    <?php if ($flashSuccess !== null): ?><div class="notice" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError !== null): ?><div class="alert" role="alert"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert" role="alert"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="post" action="<?= e(url('admin/edit-parent.php?id=' . $parentId)) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="parent_id" value="<?= $parentId ?>">

            <div class="form-card">
                <h2>اطلاعات اصلی</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name">نام <span class="req">*</span></label>
                        <input type="text" id="first_name" name="first_name" maxlength="100" value="<?= e($old['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">نام خانوادگی <span class="req">*</span></label>
                        <input type="text" id="last_name" name="last_name" maxlength="100" value="<?= e($old['last_name']) ?>" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="phone">شماره موبایل (شناسه ورود) <span class="req">*</span></label>
                        <input type="tel" id="phone" name="phone" dir="ltr" value="<?= e($old['phone']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">ایمیل (اختیاری)</label>
                        <input type="email" id="email" name="email" dir="ltr" value="<?= e($old['email']) ?>">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="status">وضعیت حساب</label>
                        <select id="status" name="status">
                            <option value="pending" <?= $old['status'] === 'pending' ? 'selected' : '' ?>>در انتظار تأیید</option>
                            <option value="active" <?= $old['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                            <option value="suspended" <?= $old['status'] === 'suspended' ? 'selected' : '' ?>>مسدود</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="password">رمز عبور جدید (خالی = بدون تغییر)</label>
                        <input type="password" id="password" name="password" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" id="phone_verified" name="phone_verified" value="1" <?= $old['phone_verified'] === 1 ? 'checked' : '' ?>>
                    <label for="phone_verified" style="margin:0;">شماره موبایل تأییدشده است</label>
                </div>
                <small style="color:var(--muted);line-height:1.8;display:block;">تغییر شماره از این صفحه مستقیم اعمال می‌شود و کد پیامکی نمی‌خواهد، چون تغییر توسط مدیریت انجام می‌شود؛ در پنل خود والد، تغییر شماره همچنان فقط با کد تأیید انجام می‌شود.</small>
            </div>

            <div class="form-card">
                <h2>تنظیمات دریافت پیامک والد</h2>
                <p style="color:var(--muted);line-height:1.8;">همان گزینه‌هایی که والد در پروفایل خود می‌بیند؛ مدیریت می‌تواند هنگام ثبت دستی آن‌ها را تنظیم کند.</p>
                <?php
                $prefs = [
                    'sms_pref_daily_report' => 'گزارش روزانه',
                    'sms_pref_attendance' => 'حضور و غیاب',
                    'sms_pref_tuition' => 'یادآوری شهریه',
                    'sms_pref_announcements' => 'اطلاعیه‌ها و رویدادها',
                ];
                foreach ($prefs as $key => $label):
                ?>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= (int) $old[$key] === 1 ? 'checked' : '' ?>>
                        <label for="<?= e($key) ?>" style="margin:0;"><?= e($label) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
                <a class="btn btn-outline" href="<?= e(url('admin/parents.php')) ?>">انصراف</a>
            </div>
        </form>
    </div>

    <div class="form-card" style="margin-top:20px;">
        <h2>کودکان این والد</h2>
        <?php if ($parentChildren === []): ?>
            <p style="color:var(--muted);">هنوز کودکی برای این والد ثبت نشده است.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($parentChildren as $ch): ?>
                    <li>
                        <?= e(trim((string) $ch['first_name'] . ' ' . (string) $ch['last_name'])) ?>
                        — <a href="<?= e(url('admin/child-detail.php?id=' . (int) $ch['id'])) ?>">جزئیات</a>
                        · <a href="<?= e(url('admin/edit-child.php?id=' . (int) $ch['id'])) ?>">ویرایش</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= e(url('admin/add-child.php?parent_id=' . $parentId)) ?>">➕ افزودن کودک برای این والد</a>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
