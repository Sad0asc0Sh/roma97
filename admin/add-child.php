<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/admin_family_helpers.php';

requireLogin();

$errors = [];
$allParents = [];
$allClassrooms = [];
$preselectParentId = filter_var($_GET['parent_id'] ?? $_POST['parent_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$preselectParentId = is_int($preselectParentId) ? $preselectParentId : 0;

$old = [
    'parent_id' => $preselectParentId,
    'first_name' => '',
    'last_name' => '',
    'preferred_name' => '',
    'date_of_birth' => '',
    'gender' => '',
    'allergies' => '',
    'medical_notes' => '',
    'second_guardian_name' => '',
    'second_guardian_phone' => '',
    'status' => 'active',
    'classroom_id' => 0,
];

try {
    initializeParentTables();
    initializeTeachersTables();
    $pdo = getDb();
    $pStmt = $pdo->query('SELECT id, first_name, last_name, phone FROM parents ORDER BY last_name ASC, first_name ASC');
    $allParents = $pStmt ? $pStmt->fetchAll() : [];
    try {
        $clStmt = $pdo->query(
            'SELECT cl.id, cl.name, CONCAT(t.first_name, " ", t.last_name) AS teacher_name
             FROM classrooms cl LEFT JOIN teachers t ON t.id = cl.teacher_id ORDER BY cl.name'
        );
        $allClassrooms = $clStmt ? $clStmt->fetchAll() : [];
    } catch (Throwable) {
        $allClassrooms = [];
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'بارگذاری فرم ثبت کودک امکان‌پذیر نیست.');
    redirect(url('admin/children.php'));
}

if (isPostRequest()) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $norm = adminFamilyNormalizeChildInput($_POST);
    $old = [
        'parent_id' => filter_var($_POST['parent_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0,
        'first_name' => $norm['first_name'],
        'last_name' => $norm['last_name'],
        'preferred_name' => $norm['preferred_name'],
        'date_of_birth' => $norm['date_of_birth'],
        'gender' => $norm['gender'],
        'allergies' => $norm['allergies'],
        'medical_notes' => $norm['medical_notes'],
        'second_guardian_name' => $norm['second_guardian_name'],
        'second_guardian_phone' => $norm['second_guardian_phone'],
        'status' => $norm['status'],
        'classroom_id' => $norm['classroom_id'],
    ];
    $old['parent_id'] = is_int($old['parent_id']) ? $old['parent_id'] : 0;

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }
    if ((int) $old['parent_id'] === 0) {
        $errors[] = 'لطفاً والد کودک را انتخاب کنید.';
    } else {
        $parentExists = false;
        foreach ($allParents as $p) {
            if ((int) $p['id'] === (int) $old['parent_id']) { $parentExists = true; break; }
        }
        if (!$parentExists) {
            $errors[] = 'والد انتخاب‌شده یافت نشد.';
        }
    }
    [$childErrors, $dob] = adminFamilyValidateChild($norm, 'کودک');
    $errors = array_merge($errors, $childErrors);
    if ((int) $old['classroom_id'] > 0) {
        $found = false;
        foreach ($allClassrooms as $cl) {
            if ((int) $cl['id'] === (int) $old['classroom_id']) { $found = true; break; }
        }
        if (!$found) {
            $errors[] = 'کلاس انتخاب‌شده یافت نشد.';
        }
    }

    if ($errors === []) {
        $photoPath = null;
        try {
            $photoPath = adminFamilyUploadChildPhoto($_FILES['photo'] ?? []);
            $norm['dob_gregorian'] = $dob ?? '';
            $newChildId = adminFamilyCreateChild($pdo, (int) $old['parent_id'], $norm, $photoPath);
            recordAudit('child.create_admin', 'child', $newChildId, [
                'source' => 'admin_manual_add_child',
                'parent_id' => (int) $old['parent_id'],
                'status' => (string) $old['status'],
                'classroom_id' => (int) $old['classroom_id'],
            ]);
            setFlash('success', 'کودک با موفقیت ثبت شد.');
            redirect(url('admin/child-detail.php?id=' . $newChildId));
        } catch (Throwable $e) {
            if ($photoPath !== null) {
                adminFamilyDeleteChildPhoto($photoPath);
            }
            error_log($e->getMessage());
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'ثبت کودک با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
        }
    }
}

$pageTitle = 'ثبت دستی کودک جدید | ' . siteName();
require_once __DIR__ . '/header.php';
$flashSuccess = getFlash('success');
$flashError = getFlash('error');
?>
<section class="dashboard">
    <h1>ثبت دستی کودک جدید</h1>
    <p><a href="<?= e(url('admin/children.php')) ?>">→ بازگشت به فهرست کودکان</a></p>
    <p style="color:var(--muted);line-height:1.9;">کودک را برای یک والد موجود مستقیم توسط مدیریت ثبت کنید. تخصیص کلاس فقط برای کودک با وضعیت «فعال» انجام می‌شود.</p>

    <?php if ($flashSuccess !== null): ?><div class="notice" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError !== null): ?><div class="alert" role="alert"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert" role="alert"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="post" action="<?= e(url('admin/add-child.php')) ?>" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

            <div class="form-card">
                <h2>والد کودک</h2>
                <div class="form-group">
                    <label for="parent_id">والد <span class="req">*</span></label>
                    <select id="parent_id" name="parent_id" required>
                        <option value="">— انتخاب والد —</option>
                        <?php foreach ($allParents as $p): ?>
                            <?php $pName = trim((string) $p['first_name'] . ' ' . (string) $p['last_name']); ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) $old['parent_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                                <?= e($pName) ?><?= !empty($p['phone']) ? ' — ' . e((string) $p['phone']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>والدی در فهرست نیست؟ ابتدا از <a href="<?= e(url('admin/add-parent.php')) ?>">ثبت دستی والد</a> او را ثبت کنید.</small>
                </div>
            </div>

            <div class="form-card">
                <h2>اطلاعات کودک</h2>
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
                        <label for="preferred_name">نام مستعار (اختیاری)</label>
                        <input type="text" id="preferred_name" name="preferred_name" maxlength="100" value="<?= e($old['preferred_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_of_birth">تاریخ تولد (جلالی) <span class="req">*</span></label>
                        <input type="text" id="date_of_birth" name="date_of_birth" class="shamsi-datepicker" data-datepicker="birth" data-year-past="15" placeholder="۱۳۹۹/۰۵/۱۸" value="<?= e($old['date_of_birth']) ?>" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="gender">جنسیت</label>
                        <select id="gender" name="gender">
                            <option value="" <?= $old['gender'] === '' ? 'selected' : '' ?>>نامشخص</option>
                            <option value="male" <?= $old['gender'] === 'male' ? 'selected' : '' ?>>پسر</option>
                            <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>>دختر</option>
                            <option value="other" <?= $old['gender'] === 'other' ? 'selected' : '' ?>>سایر</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">وضعیت</label>
                        <select id="status" name="status">
                            <?php foreach (['active'=>'فعال','pending'=>'در انتظار','inactive'=>'غیرفعال','graduated'=>'فارغ‌التحصیل','withdrawn'=>'انصراف‌داده'] as $sVal=>$sLabel): ?>
                                <option value="<?= e($sVal) ?>" <?= $old['status'] === $sVal ? 'selected' : '' ?>><?= e($sLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="classroom_id">کلاس (اختیاری)</label>
                    <select id="classroom_id" name="classroom_id">
                        <option value="0">— بدون کلاس —</option>
                        <?php foreach ($allClassrooms as $cl): ?>
                            <option value="<?= (int) $cl['id'] ?>" <?= (int) $old['classroom_id'] === (int) $cl['id'] ? 'selected' : '' ?>>
                                <?= e((string) $cl['name']) ?><?= !empty($cl['teacher_name']) ? ' (' . e((string) $cl['teacher_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-card">
                <h2>اطلاعات سلامت</h2>
                <div class="form-group">
                    <label for="allergies">حساسیت‌ها</label>
                    <textarea id="allergies" name="allergies" maxlength="5000" rows="4"><?= e($old['allergies']) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="medical_notes">توضیحات پزشکی</label>
                    <textarea id="medical_notes" name="medical_notes" maxlength="5000" rows="4"><?= e($old['medical_notes']) ?></textarea>
                </div>
            </div>

            <div class="form-card">
                <h2>ولی دوم (اختیاری)</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="second_guardian_name">نام و نام خانوادگی</label>
                        <input type="text" id="second_guardian_name" name="second_guardian_name" maxlength="200" value="<?= e($old['second_guardian_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="second_guardian_phone">شماره تماس</label>
                        <input type="tel" id="second_guardian_phone" name="second_guardian_phone" maxlength="20" value="<?= e($old['second_guardian_phone']) ?>">
                    </div>
                </div>
            </div>

            <div class="form-card">
                <h2>تصویر کودک (اختیاری)</h2>
                <div class="form-group">
                    <label for="photo">بارگذاری تصویر</label>
                    <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif">
                    <small>فرمت‌های مجاز: JPG، PNG یا GIF. حداکثر حجم ۵۰۰ کیلوبایت</small>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">ثبت کودک</button>
                <a class="btn btn-outline" href="<?= e(url('admin/children.php')) ?>">انصراف</a>
            </div>
        </form>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
