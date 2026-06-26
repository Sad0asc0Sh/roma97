<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

/** @return list<string> */
function adminEventCategories(): array
{
    return ['general', 'trip', 'celebration', 'meeting', 'holiday'];
}

/** @return list<string> */
function adminEventStatuses(): array
{
    return ['scheduled', 'cancelled', 'completed'];
}

function adminEventCategoryLabel(string $cat): string
{
    return match ($cat) {
        'trip' => 'اردو',
        'celebration' => 'جشن',
        'meeting' => 'جلسه',
        'holiday' => 'تعطیلی',
        default => 'عمومی',
    };
}

function adminEventCategoryClass(string $cat): string
{
    return match ($cat) {
        'trip' => 'event-category-trip',
        'celebration' => 'event-category-celebration',
        'meeting' => 'event-category-meeting',
        'holiday' => 'event-category-holiday',
        default => 'event-category-general',
    };
}

function adminEventStatusLabel(string $st): string
{
    return match ($st) {
        'cancelled' => 'لغو شده',
        'completed' => 'برگزار شده',
        default => 'برنامه‌ریزی شده',
    };
}

function adminEventStringLen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function parseAdminEventId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return is_int($id) ? $id : 0;
}

function parseAdminEventDate(string $raw): ?string
{
    $raw = trim($raw);

    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) !== 1) {
        return null;
    }

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

    return $d !== false && $d->format('Y-m-d') === $raw ? $raw : null;
}

function normalizeAdminEventTime(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }

    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $raw) === 1) {
        return $raw . ':00';
    }

    if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d:[0-5]\d\z/', $raw) === 1) {
        return $raw;
    }

    return null;
}

function adminEventTimeForInput(?string $sqlTime): string
{
    if ($sqlTime === null || $sqlTime === '') {
        return '';
    }

    if (preg_match('/^(\d{2}:\d{2})/', $sqlTime, $matches)) {
        return $matches[1];
    }

    return '';
}

function findAdminEvent(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, title, description, event_date, start_time, end_time, category, status, created_at
         FROM events WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();

    return $row ?: null;
}

/**
 * @return list<string>
 */
function adminEventFiltersFromGet(): array
{
    $cat = (string) ($_GET['category'] ?? 'all');

    if (!in_array($cat, [...adminEventCategories(), 'all'], true)) {
        $cat = 'all';
    }

    $st = (string) ($_GET['status'] ?? 'all');

    if (!in_array($st, [...adminEventStatuses(), 'all'], true)) {
        $st = 'all';
    }

    return [$cat, $st];
}

/** @return array<string, string> */
function adminEventFilterQueryParts(string $category, string $status): array
{
    $parts = [];

    if ($category !== 'all') {
        $parts['category'] = $category;
    }

    if ($status !== 'all') {
        $parts['status'] = $status;
    }

    return $parts;
}

function adminEventsBuildQuery(string $category, string $status): string
{
    $q = [];

    if ($category !== 'all') {
        $q['category'] = $category;
    }

    if ($status !== 'all') {
        $q['status'] = $status;
    }

    return $q === [] ? '' : ('?' . http_build_query($q));
}

function adminEventsRedirectFormUrl(int $editId, string $filterCategory, string $filterStatus): string
{
    $base = 'admin/events.php';

    if ($editId > 0) {
        $q = array_merge(
            adminEventFilterQueryParts($filterCategory, $filterStatus),
            ['action' => 'edit', 'id' => (string) $editId]
        );

        return url($base . '?' . http_build_query($q));
    }

    $q = array_merge(
        adminEventFilterQueryParts($filterCategory, $filterStatus),
        ['action' => 'add']
    );

    return url($base . '?' . http_build_query($q));
}

$pdo = null;
$listEvents = [];
$pagination = paginate(0, 1, 20);
$editEvent = null;
$deleteEvent = null;
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$action = strtolower(trim((string) ($_GET['action'] ?? '')));

if ($action === '') {
    $action = 'list';
}

[$filterCategory, $filterStatus] = adminEventFiltersFromGet();

try {
    initializeEventTables();
    $pdo = getDb();

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        $postAction = (string) ($_POST['form_action'] ?? '');

        if (!validateCsrfToken($csrfToken)) {
            setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            redirect(url('admin/events.php' . adminEventsBuildQuery($filterCategory, $filterStatus)));
        }

        if ($postAction === 'delete_event') {
            $delId = parseAdminEventId($_POST['event_id'] ?? null);
            $evt = $delId > 0 ? findAdminEvent($pdo, $delId) : null;

            if ($evt === null) {
                setFlash('error', 'رویداد پیدا نشد.');
                redirect(url('admin/events.php'));
            }

            $delStmt = $pdo->prepare('DELETE FROM events WHERE id = :id');
            $delStmt->execute([':id' => $delId]);

            recordAudit('event.delete', 'event', (int) $delId);
            setFlash('success', 'رویداد حذف شد.');
            redirect(url('admin/events.php' . adminEventsBuildQuery($filterCategory, $filterStatus)));
        }

        if ($postAction === 'save_event') {
            $editId = parseAdminEventId($_POST['event_id'] ?? null);
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
            $startRaw = (string) ($_POST['start_time'] ?? '');
            $endRaw = (string) ($_POST['end_time'] ?? '');
            $category = (string) ($_POST['category'] ?? 'general');
            $statusVal = (string) ($_POST['status'] ?? 'scheduled');

            if ($title === '' || adminEventStringLen($title) > 255) {
                setFlash('error', 'لطفاً عنوان معتبر وارد کنید (الزامی، حداکثر ۲۵۵ کاراکتر).');
                redirect(adminEventsRedirectFormUrl($editId, $filterCategory, $filterStatus));
            }

            $eventDate = parseAdminEventDate($eventDateRaw);

            if ($eventDate === null) {
                setFlash('error', 'لطفاً تاریخ رویداد معتبر وارد کنید.');
                redirect(adminEventsRedirectFormUrl($editId, $filterCategory, $filterStatus));
            }

            if (!in_array($category, adminEventCategories(), true)) {
                $category = 'general';
            }

            if (!in_array($statusVal, adminEventStatuses(), true)) {
                $statusVal = 'scheduled';
            }

            $startTime = normalizeAdminEventTime($startRaw);
            $endTime = normalizeAdminEventTime($endRaw);

            if (trim($startRaw) !== '' && $startTime === null) {
                setFlash('error', 'زمان شروع نامعتبر است. از قالب HH:MM استفاده کنید یا خالی بگذارید.');
                redirect(adminEventsRedirectFormUrl($editId, $filterCategory, $filterStatus));
            }

            if (trim($endRaw) !== '' && $endTime === null) {
                setFlash('error', 'زمان پایان نامعتبر است. از قالب HH:MM استفاده کنید یا خالی بگذارید.');
                redirect(adminEventsRedirectFormUrl($editId, $filterCategory, $filterStatus));
            }

            if ($description !== '' && adminEventStringLen($description) > 65535) {
                $description = function_exists('mb_substr')
                    ? mb_substr($description, 0, 65535, 'UTF-8')
                    : substr($description, 0, 65535);
            }

            if ($editId > 0) {
                $existing = findAdminEvent($pdo, $editId);

                if ($existing === null) {
                    setFlash('error', 'رویداد پیدا نشد.');
                    redirect(url('admin/events.php'));
                }

                $up = $pdo->prepare(
                    <<<'SQL'
UPDATE events SET
    title = :title,
    description = :description,
    event_date = :event_date,
    start_time = :start_time,
    end_time = :end_time,
    category = :category,
    status = :status
WHERE id = :id
SQL
                );
                $up->execute([
                    ':title' => $title,
                    ':description' => $description === '' ? null : $description,
                    ':event_date' => $eventDate,
                    ':start_time' => $startTime,
                    ':end_time' => $endTime,
                    ':category' => $category,
                    ':status' => $statusVal,
                    ':id' => $editId,
                ]);

                recordAudit('event.update', 'event', (int) $editId);
                setFlash('success', 'رویداد به‌روزرسانی شد.');
            } else {
                $ins = $pdo->prepare(
                    <<<'SQL'
INSERT INTO events (title, description, event_date, start_time, end_time, category, status)
VALUES (:title, :description, :event_date, :start_time, :end_time, :category, :status)
SQL
                );
                $ins->execute([
                    ':title' => $title,
                    ':description' => $description === '' ? null : $description,
                    ':event_date' => $eventDate,
                    ':start_time' => $startTime,
                    ':end_time' => $endTime,
                    ':category' => $category,
                    ':status' => $statusVal,
                ]);

                recordAudit('event.create', 'event', (int) $pdo->lastInsertId());
                setFlash('success', 'رویداد ایجاد شد.');
            }

            redirect(url('admin/events.php' . adminEventsBuildQuery($filterCategory, $filterStatus)));
        }

        setFlash('error', 'درخواست نامعتبر است.');
        redirect(url('admin/events.php'));
    }

    $sql = 'SELECT id, title, description, event_date, start_time, end_time, category, status, created_at FROM events WHERE 1=1';
    $countSql = 'SELECT COUNT(*) FROM events WHERE 1=1';
    $execParams = [];

    if ($filterCategory !== 'all') {
        $sql .= ' AND category = :filter_category';
        $countSql .= ' AND category = :filter_category';
        $execParams[':filter_category'] = $filterCategory;
    }

    if ($filterStatus !== 'all') {
        $sql .= ' AND status = :filter_status';
        $countSql .= ' AND status = :filter_status';
        $execParams[':filter_status'] = $filterStatus;
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($execParams);
    $pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);

    $sql .= ' ORDER BY event_date DESC, start_time IS NULL ASC, start_time DESC, id DESC';
    $sql .= ' LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'];
    $listStmt = $pdo->prepare($sql);
    $listStmt->execute($execParams);
    $listEvents = $listStmt->fetchAll();

    if ($action === 'edit') {
        $editIdGet = parseAdminEventId($_GET['id'] ?? null);

        if ($editIdGet === 0) {
            setFlash('error', 'یک رویداد برای ویرایش انتخاب کنید.');
            redirect(url('admin/events.php' . adminEventsBuildQuery($filterCategory, $filterStatus)));
        }

        $editEvent = findAdminEvent($pdo, $editIdGet);

        if ($editEvent === null) {
            setFlash('error', 'رویداد پیدا نشد.');
            redirect(url('admin/events.php'));
        }
    }

    if ($action === 'delete') {
        $deleteId = parseAdminEventId($_GET['id'] ?? null);
        $deleteEvent = $deleteId > 0 ? findAdminEvent($pdo, $deleteId) : null;

        if ($deleteEvent === null && $deleteId > 0) {
            setFlash('error', 'رویداد پیدا نشد.');
            redirect(url('admin/events.php'));
        }
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());

    if ($errorMessage === null || $errorMessage === '') {
        $errorMessage = 'رویدادها موقتاً در دسترس نیستند.';
    }
}

$filterQuerySuffix = adminEventsBuildQuery($filterCategory, $filterStatus);
$listUrl = url('admin/events.php' . $filterQuerySuffix);
$formPreset = [
    'title' => '',
    'description' => '',
    'event_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    'start_time' => '',
    'end_time' => '',
    'category' => 'general',
    'status' => 'scheduled',
    'event_id' => 0,
];

if ($editEvent !== null) {
    $formPreset = [
        'title' => (string) $editEvent['title'],
        'description' => (string) ($editEvent['description'] ?? ''),
        'event_date' => (string) $editEvent['event_date'],
        'start_time' => adminEventTimeForInput($editEvent['start_time'] ?? null),
        'end_time' => adminEventTimeForInput($editEvent['end_time'] ?? null),
        'category' => (string) ($editEvent['category'] ?: 'general'),
        'status' => (string) ($editEvent['status'] ?: 'scheduled'),
        'event_id' => (int) $editEvent['id'],
    ];
}

$pageTitle = 'رویدادها | ' . siteName();
require_once __DIR__ . '/header.php';

$showForm = ($action === 'add' || $action === 'edit');
?>

<section class="dashboard admin-events-dashboard">
    <h1>رویدادها</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($deleteEvent !== null): ?>
        <div class="alert" role="alert">
            <p>رویداد «<?= e((string) $deleteEvent['title']) ?>" (<?= e(shamsiDate((string) $deleteEvent['event_date'] ?? '')) ?>)?</p>
            <form method="post" action="<?= e(url('admin/events.php' . adminEventsBuildQuery($filterCategory, $filterStatus))) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="form_action" value="delete_event">
                <input type="hidden" name="event_id" value="<?= e((string) $deleteEvent['id']) ?>">
                <button type="submit" class="btn">حذف</button>
                <a href="<?= e($listUrl) ?>">انصراف</a>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <section class="form-card admin-event-form-card" aria-labelledby="event-form-title">
            <h2 id="event-form-title"><?= $formPreset['event_id'] > 0 ? 'ویرایش رویداد' : 'افزودن رویداد' ?></h2>
            <form class="admin-event-form" method="post" action="<?= e(url('admin/events.php' . $filterQuerySuffix)) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="form_action" value="save_event">
                <?php if ($formPreset['event_id'] > 0): ?>
                    <input type="hidden" name="event_id" value="<?= e((string) $formPreset['event_id']) ?>">
                <?php endif; ?>

                <label for="title">عنوان</label>
                <input type="text" id="title" name="title" maxlength="255" value="<?= e($formPreset['title']) ?>" required>

                <label for="description">توضیحات</label>
                <textarea id="description" name="description" rows="5"><?= e($formPreset['description']) ?></textarea>

                <label for="event_date">تاریخ رویداد</label>
                <input type="date" id="event_date" name="event_date" value="<?= e($formPreset['event_date']) ?>" required>

                <div class="admin-event-times">
                    <div>
                        <label for="start_time">زمان شروع (اختیاری)</label>
                        <input type="time" id="start_time" name="start_time" value="<?= e($formPreset['start_time']) ?>">
                    </div>
                    <div>
                        <label for="end_time">زمان پایان (اختیاری)</label>
                        <input type="time" id="end_time" name="end_time" value="<?= e($formPreset['end_time']) ?>">
                    </div>
                </div>

                <label for="category">دسته‌بندی</label>
                <select id="category" name="category">
                    <?php foreach (adminEventCategories() as $c): ?>
                        <option value="<?= e($c) ?>" <?= $formPreset['category'] === $c ? 'selected' : '' ?>><?= e(adminEventCategoryLabel($c)) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="status">وضعیت</label>
                <select id="status" name="status">
                    <?php foreach (adminEventStatuses() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $formPreset['status'] === $s ? 'selected' : '' ?>><?= e(adminEventStatusLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="form-actions">
                    <button type="submit" class="btn"><?= $formPreset['event_id'] > 0 ? 'ذخیره تغییرات' : 'ایجاد رویداد' ?></button>
                    <a href="<?= e($listUrl) ?>">بازگشت به فهرست</a>
                </div>
            </form>
        </section>
    <?php else: ?>

        <div class="admin-events-toolbar">
            <form class="events-filter-form" method="get" action="<?= e(url('admin/events.php')) ?>">
                <label for="filter_category">دسته‌بندی</label>
                <select id="filter_category" name="category" onchange="this.form.submit()">
                    <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>همه</option>
                    <?php foreach (adminEventCategories() as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterCategory === $c ? 'selected' : '' ?>><?= e(adminEventCategoryLabel($c)) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="filter_status">وضعیت</label>
                <select id="filter_status" name="status" onchange="this.form.submit()">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>همه</option>
                    <?php foreach (adminEventStatuses() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= e(adminEventStatusLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-secondary">اعمال فیلتر</button></noscript>
            </form>
            <?php
            $addQ = adminEventFilterQueryParts($filterCategory, $filterStatus);
            $addQ['action'] = 'add';
            $addUrl = url('admin/events.php?' . http_build_query($addQ));
            ?>
            <a class="btn" href="<?= e($addUrl) ?>">افزودن رویداد</a>
        </div>

        <?php if ($listEvents === []): ?>
            <p class="muted">هنوز رویدادی وجود ندارد. <a href="<?= e($addUrl) ?>">یکی اضافه کنید</a></p>
        <?php else: ?>
            <?php
            $buildManageQuery = static function (array $extra) use ($filterCategory, $filterStatus): string {
                $q = adminEventFilterQueryParts($filterCategory, $filterStatus);

                foreach ($extra as $k => $v) {
                    $q[$k] = $v;
                }

                return $q === [] ? '' : ('?' . http_build_query($q));
            };
            ?>

            <div class="events-list events-table-wrap">
                <div class="events-table-scroll">
                    <table class="events-admin-table">
                        <thead>
                            <tr>
                                <th scope="col">تاریخ</th>
                                <th scope="col">عنوان</th>
                                <th scope="col">زمان</th>
                                <th scope="col">دسته‌بندی</th>
                                <th scope="col">وضعیت</th>
                                <th scope="col">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($listEvents as $ev): ?>
                                <?php
                                $eid = (int) $ev['id'];
                                $st = adminEventTimeForInput($ev['start_time'] ?? null);
                                $et = adminEventTimeForInput($ev['end_time'] ?? null);
                                $timeStr = $st !== '' ? $st . ($et !== '' ? ' – ' . $et : '') : ($et !== '' ? $et : '—');
                                ?>
                                <tr>
                                    <td><?= e((string) $ev['event_date']) ?></td>
                                    <td><?= e((string) $ev['title']) ?></td>
                                    <td><?= e($timeStr) ?></td>
                                    <td>
                                        <?php $cat = (string) ($ev['category'] ?: 'general'); ?>
                                        <span class="event-badge <?= e(adminEventCategoryClass($cat)) ?>"><?= e(adminEventCategoryLabel($cat)) ?></span>
                                    </td>
                                    <td><?= e(adminEventStatusLabel((string) ($ev['status'] ?? ''))) ?></td>
                                    <td>
                                        <?php $editHref = url('admin/events.php' . $buildManageQuery(['action' => 'edit', 'id' => $eid])); ?>
                                        <?php $delHref = url('admin/events.php' . $buildManageQuery(['action' => 'delete', 'id' => $eid])); ?>
                                        <a href="<?= e($editHref) ?>">ویرایش</a>
                                        ·
                                        <a href="<?= e($delHref) ?>">حذف</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="events-cards" aria-label="فهرست رویدادها">
                <?php foreach ($listEvents as $ev): ?>
                    <?php
                    $eid = (int) $ev['id'];
                    $st = adminEventTimeForInput($ev['start_time'] ?? null);
                    $et = adminEventTimeForInput($ev['end_time'] ?? null);
                    $timeStr = $st !== '' ? $st . ($et !== '' ? ' – ' . $et : '') : ($et !== '' ? $et : '');
                    $cat = (string) ($ev['category'] ?: 'general');
                    $editHref = url('admin/events.php' . $buildManageQuery(['action' => 'edit', 'id' => $eid]));
                    $delHref = url('admin/events.php' . $buildManageQuery(['action' => 'delete', 'id' => $eid]));
                    ?>
                    <article class="event-card admin-event-card">
                        <header class="event-card-header">
                            <p class="event-card-date"><?= e((string) $ev['event_date']) ?></p>
                            <?php $stLabel = adminEventStatusLabel((string) ($ev['status'] ?? '')); ?>
                            <span class="event-card-status <?= e(($ev['status'] ?? '') === 'cancelled' ? 'muted' : '') ?>"><?= e($stLabel) ?></span>
                        </header>
                        <h2 class="event-card-title"><?= e((string) $ev['title']) ?></h2>
                        <?php if (trim((string) ($ev['description'] ?? '')) !== ''): ?>
                            <p class="event-card-desc"><?= e((string) $ev['description']) ?></p>
                        <?php endif; ?>
                        <?php if ($timeStr !== ''): ?>
                            <p class="event-card-time"><?= e($timeStr) ?></p>
                        <?php endif; ?>
                        <p><span class="event-badge <?= e(adminEventCategoryClass($cat)) ?>"><?= e(adminEventCategoryLabel($cat)) ?></span></p>
                        <div class="event-card-actions">
                            <a class="btn btn-secondary" href="<?= e($editHref) ?>">ویرایش</a>
                            <a class="btn btn-secondary" href="<?= e($delHref) ?>">حذف</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($pagination['total'] > $pagination['perPage']): ?>
                <?php
                $pagerQuery = [];
                if ($filterCategory !== 'all') {
                    $pagerQuery['category'] = $filterCategory;
                }
                if ($filterStatus !== 'all') {
                    $pagerQuery['status'] = $filterStatus;
                }
                ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> رویداد
                </p>
                <?= renderPagination($pagination, url('admin/events.php'), $pagerQuery) ?>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
