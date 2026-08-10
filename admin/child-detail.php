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

function adminDetailChildAge(string $dateOfBirth): string
{
    try {
        $birthDate = new DateTimeImmutable($dateOfBirth);
        $today = new DateTimeImmutable('today');

        if ($birthDate >= $today) {
            return 'نوزاد';
        }

        $years = $birthDate->diff($today)->y;
        $months = $birthDate->diff($today)->m;

        if ($years < 1) {
            return $months . ' ماهه';
        }

        return $years . ' ساله';
    } catch (Throwable) {
        return 'نامشخص';
    }
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

function formatAdminEnrollmentDate(string $datetime): string
{
    $ts = strtotime($datetime);

    return $ts === false ? $datetime : formatPersianDate($datetime);
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
$initial = strtoupper(substr((string) ($row['first_name'] ?? ''), 0, 1));
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

$sgName = trim((string) ($row['second_guardian_name'] ?? ''));
$sgPhone = trim((string) ($row['second_guardian_phone'] ?? ''));
?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 class="admin-page-title" style="font-size: 1.5rem; font-weight: 700; margin: 0 0 4px 0;">جزئیات ثبت‌نام کودک</h1>
        <p class="admin-page-subtitle" style="color: var(--adm-text-muted); font-size: 0.88rem; margin: 0;">مشاهده پرونده کامل، اطلاعات سرپرست و مدیریت اختصاص کلاس</p>
    </div>
    <div class="admin-page-actions" style="display: flex; gap: 10px;">
        <a href="<?= e(url('admin/children.php')) ?>" class="btn btn-secondary">→ بازگشت به لیست کودکان</a>
        <a href="<?= e(url('admin/edit-child.php?id=' . $childId)) ?>" class="btn btn-primary">✏️ ویرایش اطلاعات</a>
    </div>
</div>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status" style="margin-bottom: 20px;"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert" style="margin-bottom: 20px;"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if (isset($_SESSION['waitlist_offer']) && (int)($_SESSION['waitlist_offer']['child_id'] ?? 0) === $childId): ?>
    <?php $wlClassId = (int) $_SESSION['waitlist_offer']['classroom_id']; unset($_SESSION['waitlist_offer']); ?>
    <div class="card" style="padding:16px; margin-bottom:20px; background:#FEF3C7; border-right:4px solid #F59E0B;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="font-size:0.9rem; font-weight:600; color:#92400E;">
                ظرفیت کلاس انتخابی تکمیل است. آیا مایلید این کودک به لیست انتظار این کلاس اضافه شود؟
            </div>
            <form method="post" action="<?= e(url('admin/child-action.php')) ?>" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_waitlist">
                <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                <input type="hidden" name="classroom_id" value="<?= e($wlClassId) ?>">
                <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                <button type="submit" class="btn btn-primary btn-sm">📋 افزودن به لیست انتظار این کلاس</button>
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

        <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 6px 0;"><?= e($fullName !== '' ? $fullName : 'کودک بدون نام') ?></h2>
        <?php if (!empty($row['preferred_name'])): ?>
            <p style="color: var(--adm-text-muted); font-size: 0.85rem; margin: 0 0 10px 0;">نام مستعار: <strong><?= e((string) $row['preferred_name']) ?></strong></p>
        <?php endif; ?>

        <div style="margin: 10px 0 16px 0;">
            <span class="badge <?= e($badgeClass) ?>" style="font-size: 0.85rem; padding: 4px 12px;"><?= e(ucfirst($status)) ?></span>
        </div>

        <div class="sidebar-info-list" style="text-align: right; font-size: 0.88rem; display: flex; flex-direction: column; gap: 10px; border-top: 1px solid var(--adm-border-light, #e2e8f0); padding-top: 16px; margin-top: 16px;">
            <div style="display: flex; justify-content: space-between;"><span style="color: var(--adm-text-muted);">جنسیت:</span> <strong><?= e(adminDetailGenderLabel(($row['gender'] ?? '') !== '' ? (string) $row['gender'] : null)) ?></strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color: var(--adm-text-muted);">رده سنی:</span> <strong><?= e(adminDetailChildAge((string) ($row['date_of_birth'] ?? ''))) ?></strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color: var(--adm-text-muted);">تاریخ تولد:</span> <strong><?= e(shamsiDate((string) ($row['date_of_birth'] ?? ''))) ?></strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color: var(--adm-text-muted);">تاریخ ثبت‌نام:</span> <strong><?= e(formatAdminEnrollmentDate((string) ($row['created_at'] ?? ''))) ?></strong></div>
        </div>

        <div class="admin-child-actions-sidebar" style="margin-top: 24px; border-top: 1px solid var(--adm-border-light, #e2e8f0); padding-top: 16px; display: flex; flex-direction: column; gap: 10px;">
            <?php if ($status !== 'active'): ?>
                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" onsubmit="return confirm('ثبت‌نام این کودک تأیید شود؟');">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-approve" style="width: 100%; justify-content: center;">✅ تأیید ثبت‌نام / فعال‌سازی</button>
                </form>
            <?php endif; ?>

            <?php if ($status === 'active'): ?>
                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" onsubmit="return confirm('این کودک فارغ‌التحصیل شود؟');">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="graduate">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-secondary" style="width: 100%; justify-content: center; background:#F3E8FF; color:#6B21A8; border-color:#E9D5FF;">🎓 تغییر به فارغ‌التحصیل</button>
                </form>

                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" onsubmit="return confirm('ثبت انصراف این کودک انجام شود؟');">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="withdraw">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-secondary" style="width: 100%; justify-content: center;">🚪 ثبت انصراف</button>
                </form>

                <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form" onsubmit="return confirm('ثبت‌نام این کودک غیرفعال شود؟');">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-secondary" style="width: 100%; justify-content: center;">⏸️ غیرفعال‌سازی</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Section with Cards -->
    <div class="admin-child-detail-main">
        <!-- Parent & Guardian Card -->
        <div class="card">
            <div class="card-header">
                <h3 style="font-size: 1.05rem; font-weight: 700; margin: 0;">👨‍👩‍👧 اطلاعات سرپرست و والدین</h3>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
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
                                <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $row['parent_phone'])) ?>"><?= e((string) $row['parent_phone']) ?></a>
                            <?php else: ?>
                                <span style="color: var(--adm-text-muted);">ثبت نشده</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <span class="detail-label">والد دوم / سرپرست مکمل:</span>
                        <div class="detail-val">
                            <?php if ($sgName !== '' || $sgPhone !== ''): ?>
                                <strong><?= e($sgName !== '' ? $sgName : 'والد دوم') ?></strong>
                                <?php if ($sgPhone !== ''): ?>
                                    <br><a href="tel:<?= e(preg_replace('/\s+/', '', $sgPhone)) ?>"><?= e($sgPhone) ?></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: var(--adm-text-muted);">ثبت نشده است</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Health & Medical Card -->
        <div class="card">
            <div class="card-header">
                <h3 style="font-size: 1.05rem; font-weight: 700; margin: 0;">🏥 وضعیت سلامت و حساسیت‌ها</h3>
            </div>
            <div class="card-body" style="display: flex; flex-direction: column; gap: 20px;">
                <div>
                    <span class="detail-label" style="color: #e53e3e; font-weight: 700;">⚠️ حساسیت‌ها و آلرژی‌ها:</span>
                    <?php if ($allergiesText !== ''): ?>
                        <div style="margin-top: 6px; padding: 12px 16px; background: rgba(229,62,62,0.08); border-right: 4px solid #e53e3e; border-radius: 6px; color: var(--adm-text); font-size: 0.92rem; line-height: 1.6;">
                            <?= nl2br(e($allergiesText)) ?>
                        </div>
                    <?php else: ?>
                        <p style="color: var(--adm-text-muted); font-size: 0.9rem; margin: 4px 0 0 0;">هیچ‌گونه حساسیتی گزارش نشده است.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <span class="detail-label">📋 نکات و ملاحظات پزشکی:</span>
                    <?php if (trim((string) ($row['medical_notes'] ?? '')) !== ''): ?>
                        <div style="margin-top: 6px; padding: 12px 16px; background: var(--adm-bg, #f8fafc); border: 1px solid var(--adm-border, #e2e8f0); border-radius: 6px; color: var(--adm-text); font-size: 0.92rem; line-height: 1.6;">
                            <?= nl2br(e((string) $row['medical_notes'])) ?>
                        </div>
                    <?php else: ?>
                        <p style="color: var(--adm-text-muted); font-size: 0.9rem; margin: 4px 0 0 0;">نکته پزشکی خاصی ثبت نشده است.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Classroom Assignment Card -->
        <div class="card">
            <div class="card-header">
                <h3 style="font-size: 1.05rem; font-weight: 700; margin: 0;">🏫 اختصاص به کلاس</h3>
            </div>
            <div class="card-body">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <span class="detail-label">کلاس اختصاص یافته:</span>
                        <div class="detail-val" style="font-size: 1.1rem; font-weight: 700; margin-top: 4px;">
                            <?php if ($assignedClass !== null): ?>
                                <span style="color: var(--adm-primary); display: inline-flex; align-items: center; gap: 6px;">
                                    <span>🏫</span> <?= e((string) $assignedClass['classroom_name']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--adm-text-muted); font-weight: normal; font-size: 0.95rem;">به هیچ کلاسی اختصاص داده نشده است</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($allClassrooms) && $status === 'active'): ?>
                        <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form classroom-assign-form" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                            <input type="hidden" name="action" value="assign_classroom">
                            <input type="hidden" name="child_id" value="<?= e((string) $childId) ?>">
                            <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                            <select name="classroom_id" class="form-control child-detail-select" style="min-width: 220px; padding: 8px 12px;">
                                <option value="0">— بدون کلاس —</option>
                                <?php foreach ($allClassrooms as $cl): ?>
                                    <option value="<?= (int) $cl['id'] ?>"
                                        <?= ((int) ($assignedClass['classroom_id'] ?? 0)) === (int) $cl['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $cl['name']) ?>
                                        <?= !empty($cl['teacher_name']) ? '(' . e((string) $cl['teacher_name']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.85rem; cursor: pointer; color: var(--adm-text-muted);">
                                <input type="checkbox" name="force_over_capacity" value="1">
                                تخصیص خارج از ظرفیت (نیاز فوری)
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
