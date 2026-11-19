<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sms.php';

requireLogin();

/** @var list<string> $allowedStatuses */
$allowedStatuses = ['all', 'pending', 'active', 'inactive', 'graduated', 'withdrawn'];
$searchInput = $_GET['q'] ?? null;
$directorySearch = is_string($searchInput) ? mb_substr(trim($searchInput), 0, 100, 'UTF-8') : '';
$statusInput = $_GET['status'] ?? null;
$statusFilter = is_string($statusInput) ? strtolower(trim($statusInput)) : '';

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = $directorySearch === '' ? 'pending' : 'all';
}
$withoutClassroom = ($_GET['classroom'] ?? null) === 'unassigned';
if ($withoutClassroom) {
    $statusFilter = 'active';
}
$searchQuery = $directorySearch === '' ? [] : ['q' => $directorySearch];
$listQuery = ['status' => $statusFilter] + ($withoutClassroom ? ['classroom' => 'unassigned'] : []) + $searchQuery;
$canViewAttendance = isPageAllowedForRole('admin/attendance.php', currentAdminRole());
$canMessageFamily = isPageAllowedForRole('admin/messages.php', currentAdminRole());

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
    p.id AS parent_id,
    p.status AS parent_status,
    p.first_name AS parent_first_name,
    p.last_name AS parent_last_name,
    p.email AS parent_email,
    p.phone AS parent_phone
SQL;

    $from = ' FROM children c INNER JOIN parents p ON p.id = c.parent_id';
    $conditions = [];
    $params = [];
    if ($statusFilter !== 'all') {
        $conditions[] = 'c.status = :status';
        $params['status'] = $statusFilter;
    }
    if ($withoutClassroom) {
        $conditions[] = 'NOT EXISTS (SELECT 1 FROM child_classroom cc WHERE cc.child_id = c.id)';
    }
    if ($directorySearch !== '') {
        $normalizedName = strtr($directorySearch, ['ي' => 'ی', 'ك' => 'ک', '‌' => ' ']);
        $normalizedName = preg_replace('/\s+/u', ' ', trim($normalizedName)) ?? $directorySearch;
        $namePattern = '%' . strtr($normalizedName, ['!' => '!!', '%' => '!%', '_' => '!_', ' ' => '%']) . '%';
        $matches = [
            "REPLACE(REPLACE(CONCAT_WS(' ', c.first_name, c.last_name), 'ي', 'ی'), 'ك', 'ک') LIKE :child_name ESCAPE '!'",
            "REPLACE(REPLACE(CONCAT_WS(' ', p.first_name, p.last_name), 'ي', 'ی'), 'ك', 'ک') LIKE :parent_name ESCAPE '!'",
        ];
        $params['child_name'] = $namePattern;
        $params['parent_name'] = $namePattern;
        if (preg_match('/\A[\p{Nd}\s()+-]+\z/u', $directorySearch) === 1) {
            $phone = normalizeIranianPhone($directorySearch);
            if ($phone !== '') {
                // A full Iranian mobile also matches a legacy record with a country prefix.
                $phone = isValidIranianMobile($phone) ? substr($phone, 1) : $phone;
                $matches[] = "REPLACE(REPLACE(REPLACE(REPLACE(p.phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE :phone";
                $params['phone'] = '%' . $phone . '%';
            }
        }
        $conditions[] = '(' . implode(' OR ', $matches) . ')';
    }

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
    $countStmt->execute($params);
    $pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);
    $statement = $pdo->prepare($sql . $from . $where
        . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset']);
    $statement->execute($params);
    $children = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    if ($errorMessage === null || $errorMessage === '') {
        $errorMessage = 'اطلاعات ثبت‌نام کودکان موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
    }
}

$listRedirect = url('admin/children.php?' . http_build_query($listQuery + ['p' => $pagination['page']]));

$pageTitle = 'مدیریت ثبت‌نام کودکان | ' . siteName();
require_once __DIR__ . '/header.php';

$filterHref = static fn (string $value): string => url('admin/children.php?' . http_build_query(
    ($value === 'unassigned' ? ['status' => 'active', 'classroom' => 'unassigned'] : ['status' => $value]) + $searchQuery
));
?>

<section class="dashboard">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:12px;">
        <h1 style="margin:0;">ثبت‌نام کودکان</h1>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="<?= e(url('admin/add-child.php')) ?>" class="btn btn-primary">+ کودک جدید</a>
            <?php if ($directorySearch === '' && !$withoutClassroom): ?>
                <a href="<?= e(url('admin/export-csv.php?' . http_build_query(['type' => 'children', 'status' => $statusFilter]))) ?>" class="btn btn-secondary">📥 دریافت خروجی CSV کودکان</a>
            <?php endif; ?>
        </div>
    </div>

    <p class="admin-attendance-quick">
        <a href="<?= e(url('admin/attendance.php')) ?>">حضور و غیاب امروز</a>
    </p>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <form class="admin-directory-search mb-sm" role="search" aria-label="جست‌وجوی فهرست کودکان" method="get" action="<?= e(url('admin/children.php')) ?>">
        <?php if ($directorySearch !== '' || $withoutClassroom): ?>
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
        <?php endif; ?>
        <?php if ($withoutClassroom): ?>
            <input type="hidden" name="classroom" value="unassigned">
        <?php endif; ?>
        <label for="child_directory_search" class="sr-only">نام کودک، نام والد یا شماره موبایل</label>
        <input type="search" name="q" id="child_directory_search" class="form-control" maxlength="100" value="<?= e($directorySearch) ?>" placeholder="نام کودک، نام والد یا شماره موبایل">
        <button type="submit" class="btn btn-primary">جست‌وجو</button>
        <?php if ($directorySearch !== ''): ?>
            <a class="btn btn-outline" href="<?= e(url('admin/children.php?' . http_build_query(array_diff_key($listQuery, $searchQuery)))) ?>">پاک کردن جست‌وجو</a>
        <?php endif; ?>
    </form>

    <nav class="filter-nav" aria-label="فیلتر بر اساس وضعیت ثبت‌نام کودکان">
        <?php foreach (['all' => 'همه', 'pending' => 'در انتظار', 'active' => 'فعال', 'unassigned' => 'فعالِ بدون کلاس', 'graduated' => 'فارغ‌التحصیل', 'withdrawn' => 'انصراف داده', 'inactive' => 'غیرفعال'] as $value => $label): ?>
            <a
                href="<?= e($filterHref($value)) ?>"
                class="<?= ($withoutClassroom ? 'unassigned' : $statusFilter) === $value ? 'is-active' : '' ?>"
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
                $initial = userInitial((string) ($child['first_name'] ?? ''));
                $hasAllergies = trim((string) ($child['allergies'] ?? '')) !== '';
                $parentName = trim((string) ($child['parent_first_name'] ?? '') . ' ' . (string) ($child['parent_last_name'] ?? ''));
                $cs = (string) ($child['status'] ?? 'pending');
                $badgeClass = match ($cs) {
                    'active' => 'badge-active',
                    'inactive' => 'badge-inactive',
                    'graduated' => 'badge-graduated',
                    'withdrawn' => 'badge-withdrawn',
                    default => 'badge-pending',
                };
                $statusText = match ($cs) {
                    'active' => 'فعال',
                    'pending' => 'در انتظار',
                    'graduated' => 'فارغ‌التحصیل',
                    'withdrawn' => 'انصراف داده',
                    'inactive' => 'غیرفعال',
                    default => $cs,
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
                                    <?php if (!empty($child['parent_phone'])): ?>
                                        <br><bdi><?= e(persianNumber($child['parent_phone'])) ?></bdi>
                                    <?php endif; ?>
                                    <?php if (!empty($child['parent_email'])): ?>
                                        <br><span class="admin-parent-email"><?= e((string) $child['parent_email']) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="admin-child-card-badges">
                                <span class="badge <?= e($badgeClass) ?>"><?= e($statusText) ?></span>
                                <?php if ($hasAllergies): ?>
                                    <span class="allergy-indicator" title="حساسیت گزارش شده"><svg width="14" height="14" aria-hidden="true" style="vertical-align:middle;"><use href="<?= e(asset('assets/img/icons.svg#icon-alert-triangle')) ?>"/></svg></span>
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

                                <a class="btn btn-secondary" href="<?= e(url('admin/child-detail.php?id=' . $cid)) ?>">پرونده کودک</a>
                                <?php if ($canViewAttendance && $cs === 'active'): ?>
                                    <a class="btn btn-secondary" href="<?= e(url('admin/attendance.php?child_id=' . $cid)) ?>">حضور و غیاب</a>
                                <?php endif; ?>
                                <?php if ($canMessageFamily && $child['parent_status'] === 'active'): ?>
                                    <a class="btn btn-secondary" href="<?= e(url('admin/messages.php?parent_id=' . (int) $child['parent_id'] . '#compose-message')) ?>">پیام به خانواده</a>
                                <?php endif; ?>
                                <a class="btn btn-secondary" href="<?= e(url('admin/edit-child.php?id=' . $cid)) ?>">ویرایش</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($directorySearch !== '' || $pagination['total'] > $pagination['perPage']): ?>
            <p class="pagination-summary">
                نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> کودک
            </p>
            <?= renderPagination($pagination, url('admin/children.php'), $listQuery) ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
