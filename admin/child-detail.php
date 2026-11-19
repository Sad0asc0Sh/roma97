<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

function parseAdminDetailChildId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return is_int($id) ? $id : 0;
}

function adminDetailGenderLabel(?string $gender): string
{
    return match ($gender) {
        'male' => 'پسر',
        'female' => 'دختر',
        'other' => 'سایر',
        default => $gender !== null && trim($gender) !== '' ? trim($gender) : 'مشخص نشده',
    };
}

$childId = parseAdminDetailChildId($_GET['id'] ?? null);
$successMessage = getFlash('success');
$errorMessage = getFlash('error');
$row = null;
$detailRedirect = url('admin/children.php');

if ($childId === 0) {
    setFlash('error', 'شناسه کودک مشخص نشده است.');
    redirect(url('admin/children.php'));
}

try {
    initializeParentTables();
    $pdo = getDb();

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.preferred_name,
    c.date_of_birth,
    c.gender,
    c.allergies,
    c.medical_notes,
    c.second_guardian_name,
    c.second_guardian_phone,
    c.photo,
    c.status,
    c.created_at,
    p.first_name AS parent_first_name,
    p.last_name AS parent_last_name,
    p.email AS parent_email,
    p.phone AS parent_phone
FROM children c
INNER JOIN parents p ON p.id = c.parent_id
WHERE c.id = :id
LIMIT 1
SQL
    );
    $statement->execute([':id' => $childId]);
    $row = $statement->fetch() ?: null;
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'بارگذاری پروفایل این کودک در حال حاضر امکان‌پذیر نیست.');
    redirect(url('admin/children.php'));
}

if ($row === null) {
    setFlash('error', 'سابقه کودک پیدا نشد.');
    redirect(url('admin/children.php'));
}

$detailRedirect = url('admin/child-detail.php?id=' . $childId);

$fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
$parentName = trim((string) $row['parent_first_name'] . ' ' . (string) $row['parent_last_name']);
$initial = userInitial((string) ($row['first_name'] ?? ''));
$status = (string) ($row['status'] ?? 'pending');
$allergiesText = trim((string) ($row['allergies'] ?? ''));
$badgeClass = match ($status) {
    'active' => 'badge-active',
    'inactive' => 'badge-inactive',
    'graduated' => 'badge-graduated',
    'withdrawn' => 'badge-withdrawn',
    default => 'badge-pending',
};

$pageTitle = 'جزئیات کودک | ' . siteName();
require_once __DIR__ . '/header.php';

// Classroom query
try {
    initializeTeachersTables();
    $clPdo = getDb();

    $clStmt = $clPdo->query(
        'SELECT cl.id, cl.name,
                CONCAT(t.first_name, " ", t.last_name) AS teacher_name
         FROM classrooms cl
         LEFT JOIN teachers t ON t.id = cl.teacher_id
         ORDER BY cl.name'
    );
    $allClassrooms = $clStmt ? $clStmt->fetchAll() : [];

    $currentCl = $clPdo->prepare(
        'SELECT cc.classroom_id, cl.name AS classroom_name
         FROM child_classroom cc
         INNER JOIN classrooms cl ON cl.id = cc.classroom_id
         WHERE cc.child_id = :cid LIMIT 1'
    );
    $currentCl->execute([':cid' => $childId]);
    $assignedClass = $currentCl->fetch() ?: null;
} catch (Throwable) {
    $allClassrooms = [];
    $assignedClass = null;
}

$statusLabel = match ($status) {
    'active' => 'فعال',
    'inactive' => 'غیرفعال',
    'graduated' => 'فارغ‌التحصیل',
    'withdrawn' => 'انصراف‌داده',
    default => 'در انتظار تأیید',
};
$sgName = trim((string) ($row['second_guardian_name'] ?? ''));
$sgPhone = trim((string) ($row['second_guardian_phone'] ?? ''));
?>

<div class="adm-page-head">
    <div class="adm-page-head-main">
        <div class="adm-page-title-row">
            <h1>جزئیات ثبت‌نام کودک</h1>
            <span class="badge <?= e($badgeClass) ?>"><?= e($statusLabel) ?></span>
        </div>
        <p class="adm-page-subtitle">مشاهده پرونده کامل، اطلاعات سرپرست و مدیریت اختصاص کلاس</p>
    </div>
    <div class="adm-page-actions">
        <a href="<?= e(url('admin/children.php')) ?>" class="adm-back-link">→ بازگشت به لیست کودکان</a>
        <a href="<?= e(url('admin/edit-child.php?id=' . $childId)) ?>" class="btn btn-primary">ویرایش اطلاعات</a>
    </div>
</div>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if (isset($_SESSION['waitlist_offer']) && (int)($_SESSION['waitlist_offer']['child_id'] ?? 0) === $childId): ?>
    <?php $wlClassId = (int) $_SESSION['waitlist_offer']['classroom_id']; unset($_SESSION['waitlist_offer']); ?>
    <div class="adm-callout adm-callout--warning">
        <div class="adm-callout-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>ظرفیت کلاس انتخابی تکمیل است. آیا مایلید این کودک به لیست انتظار این کلاس اضافه شود؟</div>
            <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_waitlist">
                <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                <input type="hidden" name="classroom_id" value="<?= e($wlClassId) ?>">
                <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                <button type="submit" class="btn btn-primary btn-sm">افزودن به لیست انتظار</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="admin-child-detail">
    <!-- Sidebar: Profile Summary & Quick Actions -->
    <div class="admin-child-detail-sidebar">
        <div class="admin-child-photo-wrap">
            <?php if (!empty($row['photo'])): ?>
                <img class="admin-child-photo" src="<?= e(url((string) $row['photo'])) ?>" alt="<?= e($fullName) ?>">
            <?php else: ?>
                <div class="admin-child-photo-placeholder" aria-hidden="true"><?= e($initial ?: '?') ?></div>
            <?php endif; ?>
        </div>

        <h2 style="font-size: 1.2rem; font-weight: 800; margin: 0 0 6px 0;"><?= e($fullName !== '' ? $fullName : 'کودک بدون نام') ?></h2>
        <?php if (!empty($row['preferred_name'])): ?>
            <p class="muted" style="margin: 0 0 10px 0;">نام مستعار: <strong><?= e((string) $row['preferred_name']) ?></strong></p>
        <?php endif; ?>

        <div class="mb-sm">
            <span class="badge <?= e($badgeClass) ?>"><?= e($statusLabel) ?></span>
        </div>

        <div class="adm-profile-meta">
            <div class="adm-profile-row"><span>جنسیت:</span> <strong><?= e(adminDetailGenderLabel(($row['gender'] ?? '') !== '' ? (string) $row['gender'] : null)) ?></strong></div>
            <div class="adm-profile-row"><span>رده سنی:</span> <strong><?= e(formatIranAge($row['date_of_birth'] ?? null, true)) ?></strong></div>
            <div class="adm-profile-row"><span>تاریخ تولد:</span> <strong><?= e(shamsiDate((string) ($row['date_of_birth'] ?? ''))) ?></strong></div>
            <div class="adm-profile-row"><span>تاریخ ثبت‌نام:</span> <strong><?= e(shamsiDbTimestamp($row['created_at'] ?? null)) ?></strong></div>
        </div>

        <div class="admin-child-actions-sidebar">
            <?php if ($status !== 'active'): ?>
                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" data-confirm="آیا ثبت‌نام این کودک تأیید و فعال شود؟">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-approve btn-block">تأیید ثبت‌نام / فعال‌سازی</button>
                </form>
            <?php endif; ?>

            <?php if ($status === 'active'): ?>
                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" data-confirm="آیا وضعیت این کودک به فارغ‌التحصیل تغییر یابد؟">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="graduate">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-secondary btn-graduate btn-block">تغییر به فارغ‌التحصیل</button>
                </form>

                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" data-confirm="آیا ثبت انصراف برای این کودک انجام شود؟">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="withdraw">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-secondary btn-block">ثبت انصراف</button>
                </form>

                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" data-confirm="آیا ثبت‌نام این کودک غیرفعال شود؟">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-secondary btn-block">غیرفعال‌سازی</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Section with Cards -->
    <div class="admin-child-detail-main">
        <!-- Parent & Guardian Card -->
        <div class="card">
            <div class="card-header">
                <h3 class="m-0 font-bold">اطلاعات سرپرست و والدین</h3>
            </div>
            <div class="card-body">
                <div class="adm-detail-grid">
                    <div>
                        <span class="detail-label">والد اصلی:</span>
                        <div class="detail-val"><strong><?= e($parentName !== '' ? $parentName : 'والدین') ?></strong></div>
                    </div>
                    <div>
                        <span class="detail-label">ایمیل والد:</span>
                        <div class="detail-val"><a href="mailto:<?= e((string) $row['parent_email']) ?>"><?= e((string) $row['parent_email']) ?></a></div>
                    </div>
                    <div>
                        <span class="detail-label">شماره تلفن والد:</span>
                        <div class="detail-val">
                            <?php if (trim((string) ($row['parent_phone'] ?? '')) !== ''): ?>
                                <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $row['parent_phone'])) ?>" dir="ltr"><?= e((string) $row['parent_phone']) ?></a>
                            <?php else: ?>
                                <span class="muted">ثبت نشده</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <span class="detail-label">والد دوم / سرپرست مکمل:</span>
                        <div class="detail-val">
                            <?php if ($sgName !== '' || $sgPhone !== ''): ?>
                                <strong><?= e($sgName !== '' ? $sgName : 'والد دوم') ?></strong>
                                <?php if ($sgPhone !== ''): ?>
                                    <br><a href="tel:<?= e(preg_replace('/\s+/', '', $sgPhone)) ?>" dir="ltr"><?= e($sgPhone) ?></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">ثبت نشده است</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Health & Medical Card -->
        <div class="card">
            <div class="card-header">
                <h3 class="m-0 font-bold">وضعیت سلامت و حساسیت‌ها</h3>
            </div>
            <div class="card-body adm-stack">
                <div>
                    <span class="detail-label" style="color: var(--adm-danger);">حساسیت‌ها و آلرژی‌ها:</span>
                    <?php if ($allergiesText !== ''): ?>
                        <div class="adm-note-box adm-note-box--alert">
                            <?= nl2br(e($allergiesText)) ?>
                        </div>
                    <?php else: ?>
                        <p class="muted m-0">هیچ‌گونه حساسیتی گزارش نشده است.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <span class="detail-label">نکات و ملاحظات پزشکی:</span>
                    <?php if (trim((string) ($row['medical_notes'] ?? '')) !== ''): ?>
                        <div class="adm-note-box">
                            <?= nl2br(e((string) $row['medical_notes'])) ?>
                        </div>
                    <?php else: ?>
                        <p class="muted m-0">نکته پزشکی خاصی ثبت نشده است.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Classroom Assignment Card -->
        <div class="card">
            <div class="card-header">
                <h3 class="m-0 font-bold">اختصاص به کلاس</h3>
            </div>
            <div class="card-body">
                <div class="flex-between-center" style="flex-wrap: wrap; gap: 16px;">
                    <div>
                        <span class="detail-label">کلاس اختصاص یافته:</span>
                        <div class="detail-val font-bold" style="margin-top: 4px;">
                            <?php if ($assignedClass !== null): ?>
                                <span style="color: var(--adm-primary);"><?= e((string) $assignedClass['classroom_name']) ?></span>
                            <?php else: ?>
                                <span class="muted">به هیچ کلاسی اختصاص داده نشده است</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($allClassrooms) && $status === 'active'): ?>
                        <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form classroom-assign-form" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                            <input type="hidden" name="action" value="assign_classroom">
                            <input type="hidden" name="child_id" value="<?= e((string) $childId) ?>">
                            <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                            <select name="classroom_id" class="form-control child-detail-select" style="min-width: 220px;">
                                <option value="0">— بدون کلاس —</option>
                                <?php foreach ($allClassrooms as $cl): ?>
                                    <option value="<?= (int) $cl['id'] ?>"
                                        <?= ((int) ($assignedClass['classroom_id'] ?? 0)) === (int) $cl['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $cl['name']) ?>
                                        <?= !empty($cl['teacher_name']) ? '(' . e((string) $cl['teacher_name']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-check">
                                <input type="checkbox" name="force_over_capacity" value="1">
                                <span class="form-check-label">تخصیص خارج از ظرفیت (نیاز فوری)</span>
                            </label>
                            <button type="submit" class="btn btn-secondary">ذخیره تغییرات</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
