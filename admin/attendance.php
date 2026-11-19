<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/attendance_pickup.php';
require_once __DIR__ . '/../includes/school_planning.php';

requireLogin();
require_once __DIR__ . '/../includes/portal_rules.php';

function normalizeAttendanceNotes(string $notes): ?string
{
    $notes = mb_substr(trim($notes), 0, 5000, 'UTF-8');
    return $notes === '' ? null : $notes;
}

function attendanceStatusValue(array $child): string
{
    $s = (string) ($child['attendance_status'] ?? '');

    return portalAttendanceStatus($s) ?? '';
}

/** @param array<string,mixed> $child */
function renderAttendanceRadios(array $child, int $childId, bool $mobile = false): void
{
    $current = attendanceStatusValue($child);
    $nameAttr = ($mobile ? 'mobile_attendance' : 'attendance') . '[' . $childId . '][status]';
    $labels = [
        'present' => 'حاضر',
        'late' => 'تأخیر',
        'excused' => 'غیبت موجه',
        'absent' => 'غایب',
    ];

    foreach ($labels as $value => $label) {
        $id = 'att-' . $childId . '-' . $value . ($mobile ? '-mob' : '-tbl');
        ?>
        <label class="status-radio-label" for="<?= e($id) ?>">
            <input
                type="radio"
                id="<?= e($id) ?>"
                name="<?= e($nameAttr) ?>"
                value="<?= e($value) ?>"
                <?= $current === $value ? 'checked' : '' ?>
                <?= $child['pickup_verified_by'] !== null ? 'disabled' : '' ?>
            >
            <?= e($label) ?>
        </label>
        <?php
    }
}

function renderFamilyAttendanceNotice(?array $notice): void
{
    if ($notice === null) {
        return;
    }
    ?>
    <div class="attendance-family-notice">
        <strong><?= e(attendanceNoticeLabel($notice)) ?></strong>
        <?php if ($notice['note'] !== null): ?><small><?= e($notice['note']) ?></small><?php endif; ?>
    </div>
    <?php
}

function renderPickupControls(array $child): void
{
    if ($child['pickup_verified_by'] !== null) {
        ?>
        <div class="attendance-pickup">
            <span class="badge badge-success">تحویل تأیید شد</span>
            <strong><?= e($child['picked_up_by']) ?></strong>
            <small>تأییدکننده <?= e($child['pickup_verifier'] ?? ('کاربر ' . persianNumber((int) $child['pickup_verified_by']))) ?></small>
        </div>
        <?php
        return;
    }
    if ($child['check_in'] === null || !in_array($child['attendance_status'], ['present', 'late'], true)) {
        return;
    }
    ?>
    <div class="attendance-pickup">
        <?php if ($child['check_out'] !== null): ?>
            <span class="badge badge-warning">در انتظار تأیید تحویل</span>
        <?php endif; ?>
        <?php foreach (attendancePickupGuardians($child) as $kind => $name): ?>
            <button type="submit" name="confirm_pickup"
                    value="<?= e((int) $child['id'] . ':' . $kind . ':' . hash('sha256', $name)) ?>"
                    class="btn btn-sm btn-outline"
                    aria-label="<?= e('تأیید تحویل ' . $child['first_name'] . ' به ' . $name) ?>">
                <?= e('تحویل به ' . $name) ?>
                <small><?= $kind === 'parent' ? 'والد' : 'سرپرست دوم' ?></small>
            </button>
        <?php endforeach; ?>
    </div>
    <?php
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$dateInput = $_GET['date'] ?? null;
$parsedDate = parseIranDate($dateInput);
$selectedDate = $parsedDate ?? $today;
$filterLabels = [
    'all' => 'همه وضعیت‌ها',
    'expected' => 'کودکان مورد انتظار',
    'missing' => 'حضورهای ثبت‌نشده',
    'notices' => 'اعلام‌های خانواده',
    'open_checkout' => 'خروج‌های باز',
];
$filter = $_GET['filter'] ?? 'all';
$filter = is_string($filter) && isset($filterLabels[$filter]) ? $filter : 'all';
$classroomId = filter_var($_GET['classroom_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$selectedChildId = filter_var($_GET['child_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$filterQuery = array_filter([
    'filter' => $filter === 'all' ? null : $filter,
    'classroom_id' => $classroomId,
    'child_id' => $selectedChildId,
]);
$attendanceUrl = static fn (string $date): string => url(
    'admin/attendance.php?' . http_build_query(['date' => shamsiDate($date, 'Y-m-d')] + $filterQuery)
);

$scope = "c.status = 'active'";
$scopeParams = [];
if ($classroomId !== null) {
    $scope .= ' AND cc.classroom_id = :classroom_id';
    $scopeParams['classroom_id'] = $classroomId;
}
if ($selectedChildId !== null) {
    $scope .= ' AND c.id = :child_id';
    $scopeParams['child_id'] = $selectedChildId;
}
$condition = match ($filter) {
    'missing' => ' AND a.id IS NULL',
    'open_checkout' => ' AND ' . PORTAL_OPEN_CHECKOUT,
    default => '',
};

$pdo = null;
$rows = [];
$classrooms = [];
$plan = ['holiday' => null, 'expected' => [], 'missing' => [], 'notices' => []];
$successMessage = getFlash('success');
$errorMessage = getFlash('error');
if ($dateInput !== null && $parsedDate === null) {
    $errorMessage ??= 'تاریخ حضور را به شمسی وارد کنید، مانند ۱۴۰۵/۰۵/۱۸.';
}

try {
    initializeAttendanceTable();
    $pdo = getDb();
    $plan = dailyAttendancePlan($pdo, $selectedDate, $classroomId);

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? null;
        $postDate = $_POST['attendance_date'] ?? null;
        $parsedPostDate = parseIranDate($postDate);

        if (!is_string($csrfToken) || !validateCsrfToken($csrfToken)) {
            setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            redirect($attendanceUrl($selectedDate));
        }

        if ($parsedPostDate === null || $parsedPostDate > $today) {
            setFlash('error', 'تاریخ حضور باید معتبر و مربوط به امروز یا روزهای گذشته باشد.');
            redirect($attendanceUrl($selectedDate));
        }

        $activeStmt = $pdo->prepare(
            'SELECT c.id FROM children c LEFT JOIN child_classroom cc ON cc.child_id = c.id WHERE ' . $scope
        );
        $activeStmt->execute($scopeParams);
        $allowedIds = array_fill_keys($activeStmt->fetchAll(PDO::FETCH_COLUMN), true);
        $postPlan = $parsedPostDate === $selectedDate ? $plan : dailyAttendancePlan($pdo, $parsedPostDate, $classroomId);
        $allowedIds = match ($filter) {
            'expected', 'missing' => array_intersect_key($allowedIds, $postPlan['expected']),
            'notices' => array_intersect_key($allowedIds, $postPlan['notices']),
            default => $allowedIds,
        };

        $attendanceData = $_POST['attendance'] ?? $_POST['mobile_attendance'] ?? [];

        if (!is_array($attendanceData)) {
            $attendanceData = [];
        }
        $pickup = null;
        if (array_key_exists('confirm_pickup', $_POST)) {
            $selection = $_POST['confirm_pickup'];
            if (!is_string($selection) || preg_match('/\A([1-9]\d*):(parent|guardian):([a-f0-9]{64})\z/', $selection, $pickup) !== 1
                || !isset($allowedIds[(int) $pickup[1]])) {
                setFlash('error', 'تحویل‌گیرندهٔ انتخاب‌شده معتبر نیست.');
                redirect($attendanceUrl($parsedPostDate));
            }
        }

        // Snapshot the stored statuses for this date so notifications only go
        // out for children whose status actually changed. Re-saving the sheet
        // (a very common action while children arrive) must not re-send SMS.
        $previousStatuses = [];
        $prevStmt = $pdo->prepare(
            'SELECT child_id, status FROM attendance WHERE attendance_date = :adate'
        );
        $prevStmt->execute([':adate' => $parsedPostDate]);
        foreach ($prevStmt->fetchAll() as $prevRow) {
            $previousStatuses[(int) $prevRow['child_id']] = (string) $prevRow['status'];
        }

        // VALUES() syntax: the row-alias form ("AS new") is MySQL 8+ only and
        // fails on MariaDB (the typical shared-hosting database).
        $insertSql = <<<'SQL'
INSERT INTO attendance (child_id, attendance_date, status, check_in, check_out, notes)
VALUES (:child_id, :attendance_date, :status, :check_in, :check_out, :notes)
SQL;
        $saveSql = match ($filter) {
            // A filtered register can go stale while a teacher updates it.
            'missing' => $insertSql . ' ON DUPLICATE KEY UPDATE id = id',
            'open_checkout' => 'UPDATE attendance a
                SET status = :status, check_in = :check_in, check_out = :check_out, notes = :notes
                WHERE a.child_id = :child_id AND a.attendance_date = :attendance_date
                  AND a.pickup_verified_by IS NULL AND a.check_out <=> :original_checkout AND ' . PORTAL_OPEN_CHECKOUT,
            default => $insertSql . ' ' . PORTAL_UPDATE_UNVERIFIED_ATTENDANCE,
        };
        $saveAttendance = $pdo->prepare($saveSql);
        $changedAttendance = [];

        // All-or-nothing: a failure halfway through a 200-child sheet must not
        // leave half the register saved and the rest lost.
        $pdo->beginTransaction();

        try {
            $pickupChildId = $pickup === null ? null : (int) $pickup[1];
            if ($pickupChildId !== null) {
                $fields = $attendanceData[$pickupChildId] ?? [];
                $checkout = optionalIranTime($fields['check_out'] ?? null, 'ساعت خروج را به صورت ۲۴ ساعته وارد کنید، مانند ۱۴:۳۰.');
                $originalCheckout = optionalIranTime($fields['original_checkout'] ?? null, 'ساعت خروج ثبت‌شده معتبر نیست. صفحه را دوباره باز کنید.');
                if ($checkout === $originalCheckout) {
                    $checkout = null;
                }
                $pickupNotes = $fields['notes'] ?? null;
                $confirmedCheckout = confirmAttendancePickup(
                    $pdo, $pickupChildId, $parsedPostDate, (int) $_SESSION['admin_id'], $pickup[2], $pickup[3], $checkout, $originalCheckout,
                    is_string($pickupNotes), is_string($pickupNotes) ? normalizeAttendanceNotes($pickupNotes) : null
                );
            }
            foreach ($attendanceData as $childKey => $fields) {
                if (!is_array($fields)) {
                    continue;
                }

                $childId = filter_var($childKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if (!is_int($childId) || $childId === $pickupChildId || !isset($allowedIds[$childId])) {
                    continue;
                }

                $status = portalAttendanceStatus($fields['status'] ?? null);
                if ($status === null) {
                    continue;
                }

                $notes = normalizeAttendanceNotes(is_string($fields['notes'] ?? null) ? $fields['notes'] : '');
                $checkIn = optionalIranTime($fields['check_in'] ?? null, 'ساعت ورود را به صورت ۲۴ ساعته وارد کنید، مانند ۰۸:۳۰.');
                $checkOut = optionalIranTime($fields['check_out'] ?? null, 'ساعت خروج را به صورت ۲۴ ساعته وارد کنید، مانند ۱۴:۳۰.');
                [$checkIn, $checkOut] = portalAttendanceTimes($status, $checkIn, $checkOut);

                $values = [
                    ':child_id' => $childId,
                    ':attendance_date' => $parsedPostDate,
                    ':status' => $status,
                    ':check_in' => $checkIn,
                    ':check_out' => $checkOut,
                    ':notes' => $notes,
                ];
                if ($filter === 'open_checkout') {
                    $values[':original_checkout'] = optionalIranTime($fields['original_checkout'] ?? null, 'ساعت خروج ثبت‌شده معتبر نیست. صفحه را دوباره باز کنید.');
                }
                $saveAttendance->execute($values);
                if ($saveAttendance->rowCount() > 0 && ($previousStatuses[$childId] ?? null) !== $status) {
                    $changedAttendance[$childId] = $status;
                }
            }

            $pdo->commit();
        } catch (Throwable $transactionError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($transactionError instanceof InvalidArgumentException) {
                setFlash('error', $transactionError->getMessage());
                redirect($attendanceUrl($parsedPostDate));
            }
            throw $transactionError;
        }

        // ── Notify parents of attendance changes only ────────────────────────
        // Determine which children's status genuinely changed in this submit.
        $statusFaLabels = [
            'present' => 'با لبخند وارد مهد شد و در حال بازیه 🌈',
            'absent'  => 'امروز جاش خیلی خالیه و دلتنگشیم 🎈',
            'late'    => 'با کمی تاخیر اومد پیشمون و به جمع دوستانش پیوست ⏰',
            'excused' => 'امروز در حال استراحت در کنار شماست 🏡',
        ];

        $notifyResults = [];

        if ($changedAttendance !== []) {
            $childIdList = implode(',', array_map('intval', array_keys($changedAttendance)));
            $recipientStmt = $pdo->query("
                SELECT c.id AS child_id, c.first_name AS c_first, c.last_name AS c_last, p.id AS parent_id
                FROM children c
                INNER JOIN parents p ON p.id = c.parent_id
                WHERE c.id IN ({$childIdList})
            ");
            $recipients = $recipientStmt ? $recipientStmt->fetchAll() : [];

            $dateFa = shamsiDate($parsedPostDate);

            foreach ($recipients as $row) {
                $childId = (int) $row['child_id'];
                $status  = (string) ($changedAttendance[$childId] ?? 'present');
                $childName = trim((string) $row['c_first'] . ' ' . (string) $row['c_last']);

                $notifyResults[] = notifyParent((int) $row['parent_id'], 'attendance', [
                    'args' => [$childName, $dateFa, $statusFaLabels[$status] ?? 'حاضر'],
                    // Keyed by the resulting status: a correction (present → absent)
                    // legitimately notifies again, a plain re-save does not.
                    'dedup_key' => "attendance:{$childId}:{$parsedPostDate}:{$status}",
                ]);
            }

            smsQueueFlushInline($pdo);
        }

        $displayFlashDate = shamsiDate($parsedPostDate);
        if ($pickupChildId !== null) {
            recordAudit('attendance.pickup.verify', 'child', $pickupChildId, [
                'date' => $parsedPostDate,
                'check_out' => $confirmedCheckout,
                'guardian' => $pickup[2],
            ]);
        }
        recordAudit('attendance.save', 'attendance', null, [
            'date'     => $parsedPostDate,
            'changed'  => count($changedAttendance),
            'notified' => count($notifyResults),
        ]);

        $flashMessage = $pickupChildId !== null
            ? 'تحویل کودک در ساعت ' . formatIranTime($confirmedCheckout) . ' تأیید شد.'
            : 'حضور و غیاب برای تاریخ ' . $displayFlashDate . ' ذخیره شد.';

        if ($changedAttendance === [] && $pickupChildId === null) {
            $flashMessage .= ' هیچ تغییری در وضعیت کودکان نبود، بنابراین پیامک تکراری ارسال نشد.';
        } elseif ($changedAttendance !== []) {
            $flashMessage .= ' ' . persianNumber((string) count($changedAttendance)) . ' تغییر وضعیت ثبت شد.';
            $summary = summarizeNotificationResults($notifyResults);
            if ($summary !== '') {
                $flashMessage .= ' ' . $summary;
            }
        }

        setFlash('success', $flashMessage);
        redirect($attendanceUrl($parsedPostDate));
    }

    $classrooms = $pdo->query('SELECT id, name FROM classrooms ORDER BY name, id')->fetchAll();
    $listSql = <<<'SQL'
SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.date_of_birth,
    c.photo,
    p.first_name AS parent_first_name,
    p.last_name AS parent_last_name,
    c.second_guardian_name,
    cl.name AS classroom_name,
    a.status AS attendance_status,
    a.check_in,
    a.check_out,
    a.picked_up_by,
    a.pickup_verified_by,
    verifier.username AS pickup_verifier,
    a.notes AS attendance_notes
FROM children c
INNER JOIN parents p ON p.id = c.parent_id
LEFT JOIN child_classroom cc ON cc.child_id = c.id
LEFT JOIN classrooms cl ON cl.id = cc.classroom_id
LEFT JOIN attendance a ON a.child_id = c.id AND a.attendance_date = :attendance_date
LEFT JOIN admins verifier ON verifier.id = a.pickup_verified_by
SQL;
    $listStmt = $pdo->prepare($listSql . ' WHERE ' . $scope . $condition . ' ORDER BY c.last_name, c.first_name, c.id');
    $listStmt->execute(['attendance_date' => $selectedDate] + $scopeParams);
    $rows = $listStmt->fetchAll();
    if (in_array($filter, ['expected', 'missing', 'notices'], true)) {
        $visible = $plan[$filter];
        $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($visible[(int) $row['id']])));
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());

    if ($errorMessage === null || $errorMessage === '') {
        $errorMessage = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'ذخیره یا بارگذاری حضور و غیاب انجام نشد. لطفاً دوباره تلاش کنید.';
    }
}

$displayDateLabel = shamsiDate($selectedDate);
$pageTitle = 'حضور و غیاب روزانه | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="admin-page">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <div class="adm-page-title-row">
                <h1>حضور و غیاب روزانه</h1>
                <span class="adm-count-pill"><?= e($displayDateLabel) ?></span>
            </div>
            <p class="adm-page-subtitle">ثبت وضعیت ورود و خروج، تحویل‌گیرنده مجاز و مشاهده اعلام‌های خانواده‌ها</p>
        </div>
    </div>

    <?php if ($successMessage !== null): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($successMessage) ?>, 'success'));</script>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($errorMessage) ?>, 'danger'));</script>
        <div class="alert alert-error" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($plan['holiday'] !== null): ?>
        <p class="notice" role="status">تعطیلی مهد · <?= e($plan['holiday']) ?></p>
    <?php endif; ?>

    <form class="date-selector date-selector-form attendance-filter-bar adm-filter-panel" method="get" action="<?= e(url('admin/attendance.php')) ?>">
        <?php if ($selectedChildId !== null): ?>
            <input type="hidden" name="child_id" value="<?= e($selectedChildId) ?>">
        <?php endif; ?>
        <div class="attendance-filter-field adm-filter-field">
            <label for="attendance_date_pick" class="form-label">تاریخ شمسی</label>
            <input type="text" id="attendance_date_pick" name="date" class="form-control shamsi-datepicker" dir="ltr" inputmode="numeric" autocomplete="off" required value="<?= e(shamsiDate($selectedDate, 'numeric')) ?>">
        </div>
        <div class="attendance-filter-field adm-filter-field">
            <label for="attendance_filter" class="form-label">وضعیت</label>
            <select name="filter" id="attendance_filter" class="form-control">
                <?php foreach ($filterLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="attendance-filter-field adm-filter-field">
            <label for="attendance_classroom" class="form-label">کلاس</label>
            <select name="classroom_id" id="attendance_classroom" class="form-control">
                <option value="">همه کلاس‌ها</option>
                <?php foreach ($classrooms as $option): ?>
                    <option value="<?= e($option['id']) ?>" <?= $classroomId === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="adm-filter-actions">
            <button type="submit" class="btn btn-secondary">اعمال فیلتر</button>
            <?php if ($filterQuery !== []): ?>
                <a href="<?= e(url('admin/attendance.php?date=' . $selectedDate)) ?>" class="btn btn-outline">نمایش همه</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="admin-section">
        <?php if ($rows === []): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <h3><?= e(match ($filter) {
                    'expected' => 'کودکی برای حضور در این روز انتظار نمی‌رود',
                    'notices' => 'اعلامی از طرف خانواده‌ها ثبت نشده است',
                    'missing' => 'مورد ثبت‌نشده‌ای در این فهرست وجود ندارد',
                    'open_checkout' => 'تحویل تأییدنشده‌ای در این فهرست وجود ندارد',
                    default => 'کودکی در این فهرست یافت نشد',
                }) ?></h3>
                <p><?= e($displayDateLabel) ?></p>
            </div>
        <?php elseif ($selectedDate > $today): ?>
            <h2 class="admin-section-title"><?= e($filter === 'all' ? 'فهرست کودکان' : $filterLabels[$filter]) ?> (<?= e(persianNumber(count($rows))) ?>)</h2>
            <p class="muted"><?= e($displayDateLabel) ?> · ثبت ورود و خروج از همان روز در دسترس است.</p>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th scope="col">کودک</th><th scope="col">کلاس</th><th scope="col">اعلام خانواده</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $child): ?>
                            <?php $notice = $plan['notices'][(int) $child['id']] ?? null; ?>
                            <tr>
                                <td><?= e(trim($child['first_name'] . ' ' . $child['last_name'])) ?></td>
                                <td><?= e($child['classroom_name'] ?? '—') ?></td>
                                <td><?php if ($notice !== null): ?><?php renderFamilyAttendanceNotice($notice); ?><?php else: ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <form class="attendance-save-form" method="post" action="<?= e($attendanceUrl($selectedDate)) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">

                <div class="flex-between-center mb-md">
                    <h2 class="admin-section-title m-0"><?= e($filter === 'all' ? 'لیست کودکان' : $filterLabels[$filter]) ?> (<?= e(persianNumber(count($rows))) ?>)</h2>
                    <button type="submit" class="btn btn-primary">ذخیره حضور و غیاب</button>
                </div>

                <div class="attendance-container">
                    <div class="attendance-table-wrap attendance-desktop admin-table-wrap">
                        <div class="attendance-table-scroll">
                            <table class="attendance-table admin-table">
                                <thead>
                                    <tr>
                                        <th scope="col">تصویر</th>
                                        <th scope="col">کودک</th>
                                        <th scope="col">والدین</th>
                                        <th scope="col">وضعیت</th>
                                        <th scope="col">ورود</th>
                                        <th scope="col">خروج</th>
                                        <th scope="col">تحویل‌گیرنده</th>
                                        <th scope="col">یادداشت</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $child): ?>
                                        <?php
                                        $cid = (int) $child['id'];
                                        $fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                                        $parentName = trim((string) $child['parent_first_name'] . ' ' . (string) $child['parent_last_name']);
                                        $initial = userInitial((string) ($child['first_name'] ?? ''));
                                        $notesVal = (string) ($child['attendance_notes'] ?? '');
                                        ?>
                                        <tr <?= $child['pickup_verified_by'] !== null ? 'data-attendance-locked' : '' ?>>
                                            <td>
                                                <?php if (!empty($child['photo'])): ?>
                                                    <img class="child-photo-small avatar-circle" src="<?= e(url((string) $child['photo'])) ?>" alt="">
                                                <?php else: ?>
                                                    <span class="child-photo-small child-photo-small-placeholder avatar-placeholder-circle" aria-hidden="true"><?= e($initial ?: '?') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="font-semibold">
                                                <?= e($fullName !== '' ? $fullName : 'کودک') ?>
                                                <?php if ($classroomId === null && !empty($child['classroom_name'])): ?>
                                                    <br><small class="muted"><?= e($child['classroom_name']) ?></small>
                                                <?php endif; ?>
                                                <?php renderFamilyAttendanceNotice($plan['notices'][$cid] ?? null); ?>
                                            </td>
                                            <td><?= e($parentName !== '' ? $parentName : '—') ?></td>
                                            <td>
                                                <div class="status-radio-group status-radio-group-table flex-items-center gap-xs">
                                                    <?php renderAttendanceRadios($child, $cid); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="hidden" name="attendance[<?= e($cid) ?>][original_checkout]" value="<?= e($child['check_out']) ?>">
                                                <input
                                                    class="form-control time-input"
                                                    type="text" data-iran-time dir="ltr" inputmode="numeric" placeholder="۱۴:۳۰"
                                                    name="attendance[<?= e((string) $cid) ?>][check_in]"
                                                    value="<?= e(iranTimeForInput($child['check_in'] ?? null)) ?>"
                                                    <?= $child['pickup_verified_by'] !== null ? 'disabled' : '' ?>
                                                    style="padding:4px 8px;"
                                                >
                                            </td>
                                            <td>
                                                <input
                                                    class="form-control time-input"
                                                    type="text" data-iran-time dir="ltr" inputmode="numeric" placeholder="۱۴:۳۰"
                                                    name="attendance[<?= e((string) $cid) ?>][check_out]"
                                                    value="<?= e(iranTimeForInput($child['check_out'] ?? null)) ?>"
                                                    <?= $child['pickup_verified_by'] !== null ? 'disabled' : '' ?>
                                                    style="padding:4px 8px;"
                                                >
                                            </td>
                                            <td class="attendance-pickup-cell"><?php renderPickupControls($child); ?></td>
                                            <td>
                                                <textarea
                                                    class="form-control notes-field notes-field-table"
                                                    name="attendance[<?= e((string) $cid) ?>][notes]"
                                                    rows="1"
                                                    maxlength="5000"
                                                    <?= $child['pickup_verified_by'] !== null ? 'disabled' : '' ?>
                                                    style="min-height:36px;font-size:0.85rem;"
                                                ><?= e($notesVal) ?></textarea>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="attendance-cards attendance-mobile" aria-label="حضور و غیاب بر اساس کودک" data-attendance-mobile>
                        <?php foreach ($rows as $child): ?>
                            <?php
                            $cid = (int) $child['id'];
                            $fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                            $parentName = trim((string) $child['parent_first_name'] . ' ' . (string) $child['parent_last_name']);
                            $initial = userInitial((string) ($child['first_name'] ?? ''));
                            $notesVal = (string) ($child['attendance_notes'] ?? '');
                            ?>
                            <article class="attendance-card" <?= $child['pickup_verified_by'] !== null ? 'data-attendance-locked' : '' ?>>
                                <div class="attendance-card-top">
                                    <?php if (!empty($child['photo'])): ?>
                                        <img class="child-photo-small" src="<?= e(url((string) $child['photo'])) ?>" alt="">
                                    <?php else: ?>
                                        <span class="child-photo-small-placeholder avatar-placeholder-circle" aria-hidden="true"><?= e($initial ?: '?') ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <h2 class="attendance-card-name" style="font-size:1rem;font-weight:700;margin:0;"><?= e($fullName !== '' ? $fullName : 'کودک') ?></h2>
                                        <p class="attendance-card-meta" style="font-size:0.8rem;color:var(--adm-text-muted);margin:2px 0 0;"><?= e(formatIranAge((string) ($child['date_of_birth'] ?? ''))) ?> · <?= e($parentName !== '' ? $parentName : '—') ?><?php if ($classroomId === null && !empty($child['classroom_name'])): ?> · <?= e($child['classroom_name']) ?><?php endif; ?></p>
                                    </div>
                                </div>
                                <?php renderFamilyAttendanceNotice($plan['notices'][$cid] ?? null); ?>
                                <?php renderPickupControls($child); ?>
                                <fieldset class="attendance-fieldset" disabled data-mobile-fieldset>
                                    <legend class="sr-only">وضعیت برای <?= e($fullName) ?></legend>
                                    <p class="attendance-field-label">وضعیت</p>
                                    <div class="status-radio-group">
                                        <?php renderAttendanceRadios($child, $cid, true); ?>
                                    </div>
                                </fieldset>
                                <div class="time-inputs">
                                    <label class="time-input-label">
                                        ورود
                                        <input
                                            class="form-control time-input"
                                            type="text" data-iran-time dir="ltr" inputmode="numeric" placeholder="۱۴:۳۰"
                                            name="mobile_attendance[<?= e((string) $cid) ?>][check_in]"
                                            value="<?= e(iranTimeForInput($child['check_in'] ?? null)) ?>"
                                            disabled
                                        >
                                    </label>
                                    <label class="time-input-label">
                                        خروج
                                        <input type="hidden" name="mobile_attendance[<?= e($cid) ?>][original_checkout]" value="<?= e($child['check_out']) ?>" disabled>
                                        <input
                                            class="form-control time-input"
                                            type="text" data-iran-time dir="ltr" inputmode="numeric" placeholder="۱۴:۳۰"
                                            name="mobile_attendance[<?= e((string) $cid) ?>][check_out]"
                                            value="<?= e(iranTimeForInput($child['check_out'] ?? null)) ?>"
                                            disabled
                                        >
                                    </label>
                                </div>
                                <label class="notes-field-label">
                                    یادداشت
                                    <textarea
                                        class="form-control notes-field"
                                        name="mobile_attendance[<?= e((string) $cid) ?>][notes]"
                                        rows="2"
                                        maxlength="5000"
                                        disabled
                                    ><?= e($notesVal) ?></textarea>
                                </label>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:16px;text-align:left;">
                    <button type="submit" class="btn btn-primary">ذخیره حضور و غیاب</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var mq = window.matchMedia('(min-width: 768px)');
    var tableWrap = document.querySelector('.attendance-table-wrap');
    var cardsWrap = document.querySelector('[data-attendance-mobile]');
    if (!tableWrap || !cardsWrap) {
        return;
    }
    // Mobile card controls + fieldset are disabled in HTML by default (so they
    // don't submit without JS). JS enables the appropriate set by viewport.
    var tableControls = tableWrap.querySelectorAll('input, textarea');
    var cardControls = cardsWrap.querySelectorAll('input, textarea');
    var mobileFieldsets = cardsWrap.querySelectorAll('[data-mobile-fieldset]');
    var previousDesktop = null;

    function controlKey(control) {
        var field = control.name.slice(control.name.indexOf('['));
        return control.type === 'radio' ? field + '=' + control.value : field;
    }

    function controlValue(control) {
        return control.type === 'radio' ? control.checked : control.value;
    }

    var originalValues = new Map();
    [...tableControls, ...cardControls].forEach(function (control) {
        originalValues.set(control, controlValue(control));
    });
    tableWrap.closest('form').addEventListener('formdata', function (event) {
        var controls = previousDesktop ? tableControls : cardControls;
        var pickup = event.formData.get('confirm_pickup');
        var changedRows = new Set(typeof pickup === 'string' ? [pickup.split(':')[0]] : []);
        controls.forEach(function (control) {
            if (!control.disabled && controlValue(control) !== originalValues.get(control)) {
                changedRows.add(control.name.match(/\[(\d+)\]/)[1]);
            }
        });
        controls.forEach(function (control) {
            if (!changedRows.has(control.name.match(/\[(\d+)\]/)[1])) {
                event.formData.delete(control.name);
            }
        });
    });

    function apply() {
        var desktop = mq.matches;
        if (previousDesktop !== null && desktop !== previousDesktop) {
            var source = desktop ? cardControls : tableControls;
            var destination = desktop ? tableControls : cardControls;
            var edits = new Map(Array.from(source, function (control) {
                return [controlKey(control), control];
            }));
            destination.forEach(function (control) {
                var original = edits.get(controlKey(control));
                if (!original) return;
                if (control.type === 'radio') control.checked = original.checked;
                else control.value = original.value;
            });
        }
        tableControls.forEach(function (el) {
            el.disabled = !desktop || el.closest('[data-attendance-locked]') !== null;
        });
        cardControls.forEach(function (el) {
            el.disabled = desktop || el.closest('[data-attendance-locked]') !== null;
        });
        // Toggle the fieldset disabled state so its child radios follow
        mobileFieldsets.forEach(function (fs) {
            fs.disabled = desktop || fs.closest('[data-attendance-locked]') !== null;
        });
        previousDesktop = desktop;
    }
    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', apply);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(apply);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
