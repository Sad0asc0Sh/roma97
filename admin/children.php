<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

/** @var list<string> $allowedStatuses */
$allowedStatuses = ['all', 'pending', 'active', 'inactive'];
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));

if ($statusFilter === '') {
    $statusFilter = 'pending';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}

function parseAdminChildListId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return is_int($id) ? $id : 0;
}

function adminChildDisplayAge(string $dateOfBirth): string
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
            return $months . ' ماه';
        }

        return $years . ' سال';
    } catch (Throwable) {
        return 'نامشخص';
    }
}

function adminChildGenderLabel(?string $gender): string
{
    return match ($gender) {
        'male' => 'پسر',
        'female' => 'دختر',
        'other' => 'سایر',
        default => $gender !== null && trim($gender) !== '' ? trim($gender) : 'مشخص نشده',
    };
}

$children = [];
$pagination = paginate(0, 1, 20);
$errorMessage = getFlash('error');
$successMessage = getFlash('success');

try {
    initializeParentTables();
    $pdo = getDb();

    $sql = <<<'SQL'
SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.preferred_name,
    c.date_of_birth,
    c.gender,
    c.allergies,
    c.photo,
    c.status,
    c.created_at,
    p.first_name AS parent_first_name,
    p.last_name AS parent_last_name,
    p.email AS parent_email,
    p.phone AS parent_phone
FROM children c
INNER JOIN parents p ON p.id = c.parent_id
SQL;

    $perPage = 20;
    if ($statusFilter === 'all') {
        $childTotal = (int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn();
        $pagination = paginate($childTotal, currentPageNumber(), $perPage);
        $sql .= ' ORDER BY c.created_at DESC LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'];
        $statement = $pdo->prepare($sql);
        $statement->execute();
    } else {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM children WHERE status = :status');
        $countStmt->execute([':status' => $statusFilter]);
        $childTotal = (int) $countStmt->fetchColumn();
        $pagination = paginate($childTotal, currentPageNumber(), $perPage);
        $sql .= ' WHERE c.status = :status ORDER BY c.created_at DESC LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'];
        $statement = $pdo->prepare($sql);
        $statement->execute([':status' => $statusFilter]);
    }

    $children = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    if ($errorMessage === null || $errorMessage === '') {
        $errorMessage = 'اطلاعات ثبت‌نام کودکان موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
    }
}

$listRedirect = url('admin/children.php?' . http_build_query(['status' => $statusFilter]));

$pageTitle = 'مدیریت ثبت‌نام کودکان | ' . siteName();
require_once __DIR__ . '/header.php';

$filterHref = static fn (string $value): string => url(
    'admin/children.php?' . http_build_query(['status' => $value])
);
?>

<section class="dashboard">
    <h1>ثبت‌نام کودکان</h1>

    <p class="admin-attendance-quick">
        <a href="<?= e(url('admin/attendance.php')) ?>">حضور و غیاب امروز</a>
    </p>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <nav class="filter-nav" aria-label="فیلتر بر اساس وضعیت ثبت‌نام کودکان">
        <?php foreach (['all' => 'همه', 'pending' => 'در انتظار', 'active' => 'فعال', 'inactive' => 'غیرفعال'] as $value => $label): ?>
            <a
                href="<?= e($filterHref($value)) ?>"
                class="<?= $statusFilter === $value ? 'is-active' : '' ?>"
            ><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($children === []): ?>
        <p class="admin-children-empty">هیچ کودکی با این فیلتر یافت نشد.</p>
    <?php else: ?>
        <div class="admin-children-grid">
            <?php foreach ($children as $child): ?>
                <?php
                $cid = parseAdminChildListId($child['id'] ?? 0);
                $fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                $initial = strtoupper(substr((string) ($child['first_name'] ?? ''), 0, 1));
                $hasAllergies = trim((string) ($child['allergies'] ?? '')) !== '';
                $parentName = trim((string) ($child['parent_first_name'] ?? '') . ' ' . (string) ($child['parent_last_name'] ?? ''));
                $cs = (string) ($child['status'] ?? 'pending');
                $badgeClass = match ($cs) {
                    'active' => 'badge-active',
                    'inactive' => 'badge-inactive',
                    default => 'badge-pending',
                };
                ?>
                <article class="admin-child-card">
                    <div class="admin-child-card-photo">
                        <?php if (!empty($child['photo'])): ?>
                            <img src="<?= e(url((string) $child['photo'])) ?>" alt="<?= e($fullName) ?>">
                        <?php else: ?>
                            <div class="admin-child-photo-placeholder" aria-hidden="true"><?= e($initial ?: '?') ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="admin-child-card-body">
                        <div class="admin-child-card-top">
                            <div>
                                <h2><?= e($fullName !== '' ? $fullName : 'بدون نام') ?></h2>
                                <?php if (!empty($child['preferred_name'])): ?>
                                    <p class="admin-child-nickname">لقب: <?= e((string) $child['preferred_name']) ?></p>
                                <?php endif; ?>
                                <p class="admin-child-meta-line">
                                    <span><?= e(adminChildDisplayAge((string) ($child['date_of_birth'] ?? ''))) ?></span>
                                    <span class="meta-sep" aria-hidden="true">•</span>
                                    <span><?= e(adminChildGenderLabel(($child['gender'] ?? '') !== '' ? (string) $child['gender'] : null)) ?></span>
                                </p>
                                <p class="admin-parent-line">
                                    <span><?= e($parentName !== '' ? $parentName : 'والدین') ?></span>
                                    <?php if (!empty($child['parent_email'])): ?>
                                        <br><span class="admin-parent-email"><?= e((string) $child['parent_email']) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="admin-child-card-badges">
                                <span class="badge <?= e($badgeClass) ?>"><?= e($cs) ?></span>
                                <?php if ($hasAllergies): ?>
                                    <span class="allergy-indicator" title="حساسیت گزارش شده">⚠️</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="admin-child-actions">
                            <?php if ($cid > 0): ?>
                                <?php if ($cs !== 'active'): ?>
                                    <form
                                        method="post"
                                        action="<?= e(url('admin/child-action.php')) ?>"
                                        class="inline-form"
                                        onsubmit="return confirm('ثبت‌نام این کودک تأیید شود؟');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                        <input type="hidden" name="child_id" value="<?= e($cid) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="redirect" value="<?= e($listRedirect) ?>">
                                        <button type="submit" class="btn btn-approve">تأیید</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($cs !== 'inactive'): ?>
                                    <form
                                        method="post"
                                        action="<?= e(url('admin/child-action.php')) ?>"
                                        class="inline-form"
                                        onsubmit="return confirm('این ثبت‌نام غیرفعال (رد) شود؟');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                        <input type="hidden" name="child_id" value="<?= e($cid) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="redirect" value="<?= e($listRedirect) ?>">
                                        <button type="submit" class="btn btn-reject btn-secondary">رد</button>
                                    </form>
                                <?php endif; ?>

                                <a class="btn btn-secondary" href="<?= e(url('admin/child-detail.php?id=' . $cid)) ?>">مشاهده جزئیات</a>
                                <a class="btn btn-secondary" href="<?= e(url('admin/child-detail.php?id=' . $cid)) ?>">ویرایش</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($pagination['total'] > $pagination['perPage']): ?>
            <p class="pagination-summary">
                نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> کودک
            </p>
            <?= renderPagination($pagination, url('admin/children.php'), ['status' => $statusFilter]) ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
