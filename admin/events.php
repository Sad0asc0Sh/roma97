<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/school_planning.php';

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
    ensureSchoolPlanningTables($pdo);

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
            $category = (string) ($_POST['category'] ?? 'general');
            $statusVal = (string) ($_POST['status'] ?? 'scheduled');

            if ($title === '' || adminEventStringLen($title) > 255) {
                setFlash('error', 'لطفاً عنوان معتبر وارد کنید (الزامی، حداکثر ۲۵۵ کاراکتر).');
                redirect(adminEventsRedirectFormUrl($editId, $filterCategory, $filterStatus));
            }

            $eventDate = parseIranDate($_POST['event_date'] ?? null);

            if ($eventDate === null) {
                setFlash('error', 'تاریخ رویداد را به شمسی وارد کنید، مانند ۱۴۰۵/۰۵/۱۸.');
                redirect(adminEventsRedirectFormUrl($editId, $filterCategory, $filterStatus));
            }

            if (!in_array($category, adminEventCategories(), true)) {
                $category = 'general';
            }

            if (!in_array($statusVal, adminEventStatuses(), true)) {
                $statusVal = 'scheduled';
            }

            try {
                $startTime = optionalIranTime($_POST['start_time'] ?? null, 'ساعت شروع را به صورت ۲۴ ساعته وارد کنید، مانند ۱۴:۳۰.');
                $endTime = optionalIranTime($_POST['end_time'] ?? null, 'ساعت پایان را به صورت ۲۴ ساعته وارد کنید، مانند ۱۵:۳۰.');
            } catch (InvalidArgumentException $exception) {
                setFlash('error', $exception->getMessage());
                redirect(adminEventsRedirectFormUrl($editId, $filterCategory, $filterStatus));
            }

            if ($description !== '' && adminEventStringLen($description) > 65535) {
                $description = function_exists('mb_substr')
                    ? mb_substr($description, 0, 65535, 'UTF-8')
                    : substr($description, 0, 65535);
            }

            $pdo->beginTransaction();
            if ($editId > 0) {
                $lock = $pdo->prepare('SELECT id FROM events WHERE id = ? FOR UPDATE');
                $lock->execute([$editId]);
                if ($lock->fetchColumn() === false) {
                    $pdo->rollBack();
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

                $eventId = $editId;
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

                $eventId = (int) $pdo->lastInsertId();
            }
            syncSchoolHoliday($pdo, $eventId, $eventDate, $category, $statusVal);
            $pdo->commit();
            recordAudit($editId > 0 ? 'event.update' : 'event.create', 'event', $eventId);
            setFlash('success', $editId > 0 ? 'رویداد به‌روزرسانی شد.' : 'رویداد ایجاد شد.');

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
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
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
    'category' => $filterCategory === 'holiday' ? 'holiday' : 'general',
    'status' => 'scheduled',
    'event_id' => 0,
];

if ($editEvent !== null) {
    $formPreset = [
        'title' => (string) $editEvent['title'],
        'description' => (string) ($editEvent['description'] ?? ''),
        'event_date' => (string) $editEvent['event_date'],
        'start_time' => iranTimeForInput($editEvent['start_time'] ?? null),
        'end_time' => iranTimeForInput($editEvent['end_time'] ?? null),
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
    <?php
    $addQ = adminEventFilterQueryParts($filterCategory, $filterStatus);
    $addQ['action'] = 'add';
    $addUrl = url('admin/events.php?' . http_build_query($addQ));
    ?>
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <div class="adm-page-title-row">
                <h1>رویدادها و تقویم مهد</h1>
                <?php if (!$showForm && isset($pagination['total'])): ?>
                    <span class="adm-count-pill"><?= e(persianNumber((string) $pagination['total'])) ?> رویداد</span>
                <?php endif; ?>
            </div>
            <p class="adm-page-subtitle">برنامه‌ریزی جشن‌ها، اردوهای آموزشی، جلسات والدین و تعطیلات رسمی مهد</p>
        </div>
        <?php if ($showForm): ?>
            <div class="adm-page-actions">
                <a href="<?= e($listUrl) ?>" class="adm-back-link">→ بازگشت به فهرست رویدادها</a>
            </div>
        <?php else: ?>
            <div class="adm-page-actions">
                <a class="btn btn-outline" href="<?= e(url('admin/events.php?action=add&category=holiday')) ?>">ثبت تعطیلی مهد</a>
                <a class="btn btn-primary" href="<?= e($addUrl) ?>">+ افزودن رویداد</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($deleteEvent !== null): ?>
        <div class="alert alert-danger" role="alert">
            <p>آیا رویداد «<?= e((string) $deleteEvent['title']) ?>» (<?= e(shamsiDate((string) $deleteEvent['event_date'] ?? '')) ?>) حذف شود؟</p>
            <form method="post" action="<?= e(url('admin/events.php' . adminEventsBuildQuery($filterCategory, $filterStatus))) ?>" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="form_action" value="delete_event">
                <input type="hidden" name="event_id" value="<?= e((string) $deleteEvent['id']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                <a href="<?= e($listUrl) ?>" class="btn btn-outline btn-sm" style="margin-inline-start:8px;">انصراف</a>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title" id="event-form-title"><?= $formPreset['event_id'] > 0 ? 'ویرایش رویداد' : 'افزودن رویداد' ?></h2>
            </div>
            <form class="admin-event-form" method="post" action="<?= e(url('admin/events.php' . $filterQuerySuffix)) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="form_action" value="save_event">
                <?php if ($formPreset['event_id'] > 0): ?>
                    <input type="hidden" name="event_id" value="<?= e((string) $formPreset['event_id']) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="title" class="form-label">عنوان رویداد</label>
                    <input type="text" id="title" name="title" class="form-control" maxlength="255" placeholder="عنوان رویداد..." value="<?= e($formPreset['title']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">توضیحات</label>
                    <textarea id="description" name="description" class="form-control" rows="5" placeholder="توضیحات رویداد..."><?= e($formPreset['description']) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="event_date" class="form-label">تاریخ شمسی رویداد</label>
                    <input type="text" id="event_date" name="event_date" class="form-control shamsi-datepicker" dir="ltr" inputmode="numeric" autocomplete="off" value="<?= e(shamsiDate($formPreset['event_date'], 'numeric')) ?>" placeholder="۱۴۰۵/۰۵/۱۸" required>
                </div>

                <div class="admin-event-times">
                    <div class="form-group">
                        <label for="start_time" class="form-label">زمان شروع (اختیاری)</label>
                        <input type="text" data-iran-time dir="ltr" inputmode="numeric" placeholder="۱۴:۳۰" id="start_time" name="start_time" class="form-control" value="<?= e($formPreset['start_time']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_time" class="form-label">زمان پایان (اختیاری)</label>
                        <input type="text" data-iran-time dir="ltr" inputmode="numeric" placeholder="۱۴:۳۰" id="end_time" name="end_time" class="form-control" value="<?= e($formPreset['end_time']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="category" class="form-label">دستهبندی</label>
                    <select id="category" name="category" class="form-control">
                        <?php foreach (adminEventCategories() as $c): ?>
                            <option value="<?= e($c) ?>" <?= $formPreset['category'] === $c ? 'selected' : '' ?>><?= e(adminEventCategoryLabel($c)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted">«تعطیلی» تمام روز را برای همهٔ کلاس‌ها در تقویم حضور می‌بندد.</small>
                </div>

                <div class="form-group">
                    <label for="status" class="form-label">وضعیت</label>
                    <select id="status" name="status" class="form-control">
                        <?php foreach (adminEventStatuses() as $s): ?>
                            <option value="<?= e($s) ?>" <?= $formPreset['status'] === $s ? 'selected' : '' ?>><?= e(adminEventStatusLabel($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $formPreset['event_id'] > 0 ? 'ذخیره تغییرات' : 'ایجاد رویداد' ?></button>
                    <a href="<?= e($listUrl) ?>" class="btn btn-outline">بازگشت به فهرست</a>
                </div>
            </form>
        </div>
    <?php else: ?>

        <form class="adm-filter-panel events-filter-form" method="get" action="<?= e(url('admin/events.php')) ?>">
            <div class="adm-filter-field">
                <label for="filter_category" class="form-label">دسته‌بندی</label>
                <select id="filter_category" name="category" class="form-control" onchange="this.form.submit()">
                    <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>همه</option>
                    <?php foreach (adminEventCategories() as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterCategory === $c ? 'selected' : '' ?>><?= e(adminEventCategoryLabel($c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="adm-filter-field">
                <label for="filter_status" class="form-label">وضعیت</label>
                <select id="filter_status" name="status" class="form-control" onchange="this.form.submit()">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>همه</option>
                    <?php foreach (adminEventStatuses() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= e(adminEventStatusLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="adm-filter-actions">
                <noscript><button type="submit" class="btn btn-secondary">اعمال فیلتر</button></noscript>
                <?php if ($filterCategory !== 'all' || $filterStatus !== 'all'): ?>
                    <a href="<?= e(url('admin/events.php')) ?>" class="btn btn-outline">نمایش همه</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($listEvents === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <h3>هنوز رویدادی وجود ندارد</h3>
                <p><a href="<?= e($addUrl) ?>">یک رویداد جدید اضافه کنید</a></p>
            </div>
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

            <div class="events-list admin-table-wrap">
                <div class="events-table-scroll">
                    <table class="admin-table">
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
                                $st = formatIranTime($ev['start_time'] ?? null);
                                $et = formatIranTime($ev['end_time'] ?? null);
                                $timeStr = $st !== '' ? $st . ($et !== '' ? ' – ' . $et : '') : ($et !== '' ? $et : '—');
                                ?>
                                <tr>
                                    <td><?= e(shamsiDate((string) $ev['event_date'])) ?></td>
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
                                        <a href="<?= e($editHref) ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                                        <a href="<?= e($delHref) ?>" class="btn btn-sm btn-reject">حذف</a>
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
                    $st = formatIranTime($ev['start_time'] ?? null);
                    $et = formatIranTime($ev['end_time'] ?? null);
                    $timeStr = $st !== '' ? $st . ($et !== '' ? ' – ' . $et : '') : ($et !== '' ? $et : '');
                    $cat = (string) ($ev['category'] ?: 'general');
                    $editHref = url('admin/events.php' . $buildManageQuery(['action' => 'edit', 'id' => $eid]));
                    $delHref = url('admin/events.php' . $buildManageQuery(['action' => 'delete', 'id' => $eid]));
                    ?>
                    <article class="event-card admin-event-card">
                        <header class="event-card-header">
                            <p class="event-card-date"><?= e(shamsiDate((string) $ev['event_date'])) ?></p>
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
