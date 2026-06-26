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
    default => 'badge-pending',
};

$pageTitle = 'جزئیات کودک | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>جزئیات ثبت‌نام کودک</h1>

    <p><a class="back-to-children" href="<?= e(url('admin/children.php')) ?>">→ بازگشت به لیست کودکان</a></p>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <article class="child-detail-card">
        <div class="child-detail-header">
            <div class="child-detail-photo-wrap">
                <?php if (!empty($row['photo'])): ?>
                    <img class="child-detail-photo" src="<?= e(url((string) $row['photo'])) ?>" alt="<?= e($fullName) ?>">
                <?php else: ?>
                    <div class="child-detail-photo child-detail-photo-placeholder" aria-hidden="true"><?= e($initial ?: '?') ?></div>
                <?php endif; ?>
            </div>
            <div class="child-detail-title-block">
                <h2><?= e($fullName !== '' ? $fullName : 'کودک بدون نام') ?></h2>
                <?php if (!empty($row['preferred_name'])): ?>
                    <p class="child-detail-nickname">نام مستعار: <strong><?= e((string) $row['preferred_name']) ?></strong></p>
                <?php endif; ?>
                <p class="child-detail-meta">
                    <?= e(adminDetailChildAge((string) ($row['date_of_birth'] ?? ''))) ?>
                    · <?= e(adminDetailGenderLabel(($row['gender'] ?? '') !== '' ? (string) $row['gender'] : null)) ?>
                </p>
                <p class="child-detail-meta">
                    تاریخ تولد: <strong><?= e((string) ($row['date_of_birth'] ?? '')) ?></strong>
                </p>
                <p class="child-detail-meta">
                    وضعیت ثبت‌نام:
                    <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($status)) ?></span>
                </p>
                <p class="child-detail-meta muted">
                    تاریخ ثبت‌نام: <?= e(formatAdminEnrollmentDate((string) ($row['created_at'] ?? ''))) ?>
                </p>
            </div>
        </div>

        <?php if ($allergiesText !== ''): ?>
            <section class="child-detail-highlight allergen-highlight" aria-labelledby="allergy-heading">
                <h3 id="allergy-heading">حساسیت‌ها</h3>
                <p><?= nl2br(e($allergiesText)) ?></p>
            </section>
        <?php else: ?>
            <section class="child-detail-section" aria-labelledby="allergy-heading">
                <h3 id="allergy-heading">حساسیت‌ها</h3>
                <p class="muted">گزارش نشده است.</p>
            </section>
        <?php endif; ?>

        <section class="child-detail-section" aria-labelledby="medical-heading">
            <h3 id="medical-heading">نکات پزشکی</h3>
            <?php if (trim((string) ($row['medical_notes'] ?? '')) !== ''): ?>
                <p><?= nl2br(e((string) $row['medical_notes'])) ?></p>
            <?php else: ?>
                <p class="muted">ارائه نشده است.</p>
            <?php endif; ?>
        </section>

        <section class="child-detail-section" aria-labelledby="second-guardian-heading">
            <h3 id="second-guardian-heading">والد دوم</h3>
            <?php
            $sgName = trim((string) ($row['second_guardian_name'] ?? ''));
            $sgPhone = trim((string) ($row['second_guardian_phone'] ?? ''));
            ?>
            <?php if ($sgName !== '' || $sgPhone !== ''): ?>
                <?php if ($sgName !== ''): ?>
                    <p><strong><?= e($sgName) ?></strong></p>
                <?php endif; ?>
                <?php if ($sgPhone !== ''): ?>
                    <p>تلفن: <a href="tel:<?= e(preg_replace('/\s+/', '', $sgPhone)) ?>"><?= e($sgPhone) ?></a></p>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">ثبت نشده است</p>
            <?php endif; ?>
        </section>

        <section class="child-detail-section" aria-labelledby="parent-heading">
            <h3 id="parent-heading">والد اصلی</h3>
            <p><strong><?= e($parentName !== '' ? $parentName : 'والدین') ?></strong></p>
            <p>ایمیل: <a href="mailto:<?= e((string) $row['parent_email']) ?>"><?= e((string) $row['parent_email']) ?></a></p>
            <?php if (trim((string) ($row['parent_phone'] ?? '')) !== ''): ?>
                <p>تلفن: <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $row['parent_phone'])) ?>"><?= e((string) $row['parent_phone']) ?></a></p>
            <?php else: ?>
                <p class="muted">شماره تلفن ثبت نشده است.</p>
            <?php endif; ?>
        </section>

        <?php
        // ── Classroom Assignment ──────────────────────────────────────────────
        try {
            initializeTeachersTables();
            $clPdo = getDb();

            // Get all classrooms
            $clStmt = $clPdo->query(
                'SELECT cl.id, cl.name,
                        CONCAT(t.first_name, " ", t.last_name) AS teacher_name
                 FROM classrooms cl
                 LEFT JOIN teachers t ON t.id = cl.teacher_id
                 ORDER BY cl.name'
            );
            $allClassrooms = $clStmt ? $clStmt->fetchAll() : [];

            // Current assignment
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
        ?>
        <section class="child-detail-section child-classroom-section" aria-labelledby="classroom-assign-heading">
            <h3 id="classroom-assign-heading">اختصاص کلاس</h3>
            <?php if ($assignedClass !== null): ?>
                <p>کلاس فعلی: <strong><?= e((string) $assignedClass['classroom_name']) ?></strong></p>
            <?php else: ?>
                <p class="muted">به هیچ کلاسی اختصاص داده نشده است</p>
            <?php endif; ?>
            <?php if (!empty($allClassrooms) && $status === 'active'): ?>
            <form method="post" action="<?= e(url('admin/child-action.php')) ?>" class="inline-form classroom-assign-form">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="assign_classroom">
                <input type="hidden" name="child_id" value="<?= e((string) $childId) ?>">
                <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                <select name="classroom_id" class="form-control child-detail-select">
                    <option value="0">— بدون کلاس —</option>
                    <?php foreach ($allClassrooms as $cl): ?>
                        <option value="<?= (int) $cl['id'] ?>"
                            <?= ((int) ($assignedClass['classroom_id'] ?? 0)) === (int) $cl['id'] ? 'selected' : '' ?>>
                            <?= e((string) $cl['name']) ?>
                            <?= !empty($cl['teacher_name']) ? '(' . e((string) $cl['teacher_name']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">ذخیره اختصاص</button>
            </form>
            <?php endif; ?>
        </section>

        <div class="child-detail-actions">
            <?php if ($status !== 'active'): ?>
                <form
                    method="post"
                    action="<?= e(url('admin/child-action.php')) ?>"
                    class="inline-form"
                    onsubmit="return confirm('ثبت‌نام این کودک تأیید شود؟');"
                >
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-approve">تأیید</button>
                </form>
            <?php endif; ?>

            <?php if ($status !== 'inactive'): ?>
                <form
                    method="post"
                    action="<?= e(url('admin/child-action.php')) ?>"
                    class="inline-form"
                    onsubmit="return confirm('این ثبت‌نام غیرفعال شود؟');"
                >
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-reject btn-secondary">رد</button>
                </form>
            <?php endif; ?>

            <?php if ($status === 'inactive'): ?>
                <form
                    method="post"
                    action="<?= e(url('admin/child-action.php')) ?>"
                    class="inline-form"
                    onsubmit="return confirm('این کودک به وضعیت فعال برگردد؟');"
                >
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="activate">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-approve">فعال‌سازی</button>
                </form>
            <?php endif; ?>

            <?php if ($status === 'active'): ?>
                <form
                    method="post"
                    action="<?= e(url('admin/child-action.php')) ?>"
                    class="inline-form"
                    onsubmit="return confirm('ثبت‌نام این کودک غیرفعال شود؟');"
                >
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="redirect" value="<?= e($detailRedirect) ?>">
                    <button type="submit" class="btn btn-secondary">غیرفعال‌سازی</button>
                </form>
            <?php endif; ?>
        </div>
    </article>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
