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

requireLogin();
require_once __DIR__ . '/../includes/portal_rules.php';

function parseAttendanceDateString(string $raw): ?string
{
    $parsed = parseJalaliDate($raw);
    if ($parsed !== null) {
        return $parsed;
    }

    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

    return $date !== false && $date->format('Y-m-d') === $raw ? $raw : null;
}

function attendanceTimeForInput(?string $sqlTime): string
{
    if ($sqlTime === null || $sqlTime === '') {
        return '';
    }

    if (preg_match('/^\d{2}:\d{2}/', $sqlTime, $matches)) {
        return $matches[0];
    }

    return '';
}

function normalizeAttendanceTime(?string $raw): ?string
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

function attendanceAgeFromDob(string $dateOfBirth): string
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
            return persianNumber((string) $months) . ' ماه';
        }

        return persianNumber((string) $years) . ' سال';
    } catch (Throwable) {
        return '—';
    }
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
            >
            <?= e($label) ?>
        </label>
        <?php
    }
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$dateInput = $_GET['date'] ?? null;
$selectedDate = is_string($dateInput) ? parseAttendanceDateString($dateInput) ?? $today : $today;
$filterLabels = [
    'all' => 'همه وضعیت‌ها',
    'missing' => 'حضورهای ثبت‌نشده',
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
    'admin/attendance.php?' . http_build_query(['date' => $date] + $filterQuery)
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
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

try {
    initializeParentTables();
    $pdo = getDb();

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? null;
        $postDate = $_POST['attendance_date'] ?? null;
        $parsedPostDate = is_string($postDate) ? parseAttendanceDateString($postDate) : null;

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

        $attendanceData = $_POST['attendance'] ?? $_POST['mobile_attendance'] ?? [];

        if (!is_array($attendanceData)) {
            $attendanceData = [];
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
                WHERE a.child_id = :child_id AND a.attendance_date = :attendance_date AND ' . PORTAL_OPEN_CHECKOUT,
            default => $insertSql . ' ' . <<<'SQL'
ON DUPLICATE KEY UPDATE
    status    = VALUES(status),
    check_in  = VALUES(check_in),
    check_out = VALUES(check_out),
    notes     = VALUES(notes)
SQL,
        };
        $saveAttendance = $pdo->prepare($saveSql);
        $changedAttendance = [];

        // All-or-nothing: a failure halfway through a 200-child sheet must not
        // leave half the register saved and the rest lost.
        $pdo->beginTransaction();

        try {
            foreach ($attendanceData as $childKey => $fields) {
                if (!is_array($fields)) {
                    continue;
                }

                $childId = filter_var($childKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if (!is_int($childId) || !isset($allowedIds[$childId])) {
                    continue;
                }

                $status = portalAttendanceStatus($fields['status'] ?? null);
                if ($status === null) {
                    continue;
                }

                $checkInRaw = isset($fields['check_in']) ? (string) $fields['check_in'] : '';
                $checkOutRaw = isset($fields['check_out']) ? (string) $fields['check_out'] : '';
                $notesRaw = isset($fields['notes']) ? trim((string) $fields['notes']) : '';

                if (function_exists('mb_strlen')) {
                    if (mb_strlen($notesRaw, 'UTF-8') > 5000) {
                        $notesRaw = mb_substr($notesRaw, 0, 5000, 'UTF-8');
                    }
                } elseif (strlen($notesRaw) > 5000) {
                    $notesRaw = substr($notesRaw, 0, 5000);
                }

                $notes = $notesRaw === '' ? null : $notesRaw;
                $checkIn = normalizeAttendanceTime($checkInRaw);
                $checkOut = normalizeAttendanceTime($checkOutRaw);
                [$checkIn, $checkOut] = portalAttendanceTimes($status, $checkIn, $checkOut);

                $saveAttendance->execute([
                    ':child_id' => $childId,
                    ':attendance_date' => $parsedPostDate,
                    ':status' => $status,
                    ':check_in' => $checkIn,
                    ':check_out' => $checkOut,
                    ':notes' => $notes,
                ]);
                if ($saveAttendance->rowCount() > 0 && ($previousStatuses[$childId] ?? null) !== $status) {
                    $changedAttendance[$childId] = $status;
                }
            }

            $pdo->commit();
        } catch (Throwable $transactionError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
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
        recordAudit('attendance.save', 'attendance', null, [
            'date'     => $parsedPostDate,
            'changed'  => count($changedAttendance),
            'notified' => count($notifyResults),
        ]);

        $flashMessage = 'حضور و غیاب برای تاریخ ' . $displayFlashDate . ' ذخیره شد.';

        if ($changedAttendance === []) {
            $flashMessage .= ' هیچ تغییری در وضعیت کودکان نبود، بنابراین پیامک تکراری ارسال نشد.';
        } else {
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
    cl.name AS classroom_name,
    a.status AS attendance_status,
    a.check_in,
    a.check_out,
    a.notes AS attendance_notes
FROM children c
INNER JOIN parents p ON p.id = c.parent_id
LEFT JOIN child_classroom cc ON cc.child_id = c.id
LEFT JOIN classrooms cl ON cl.id = cc.classroom_id
LEFT JOIN attendance a ON a.child_id = c.id AND a.attendance_date = :attendance_date
SQL;
    $listStmt = $pdo->prepare($listSql . ' WHERE ' . $scope . $condition . ' ORDER BY c.last_name, c.first_name, c.id');
    $listStmt->execute(['attendance_date' => $selectedDate] + $scopeParams);
    $rows = $listStmt->fetchAll();
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
    <div class="app-toolbar">
        <h1 style="margin:0;font-size:1.5rem;font-weight:800">حضور و غیاب روزانه</h1>
    </div>

    <?php if ($successMessage !== null): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($successMessage) ?>, 'success'));</script>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($errorMessage) ?>, 'danger'));</script>
        <div class="alert alert-error" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-section mb-md">
        <form class="date-selector date-selector-form attendance-filter-bar" method="get" action="<?= e(url('admin/attendance.php')) ?>">
            <?php if ($selectedChildId !== null): ?>
                <input type="hidden" name="child_id" value="<?= e($selectedChildId) ?>">
            <?php endif; ?>
            <div class="attendance-filter-field">
                <label for="attendance_date_pick" class="form-label">تاریخ</label>
                <input type="text" id="attendance_date_pick" name="date" class="form-control shamsi-datepicker" value="<?= e(shamsiDate($selectedDate, 'numeric')) ?>">
            </div>
            <div class="attendance-filter-field">
                <label for="attendance_filter" class="form-label">وضعیت</label>
                <select name="filter" id="attendance_filter" class="form-control">
                    <?php foreach ($filterLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="attendance-filter-field">
                <label for="attendance_classroom" class="form-label">کلاس</label>
                <select name="classroom_id" id="attendance_classroom" class="form-control">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classrooms as $option): ?>
                        <option value="<?= e($option['id']) ?>" <?= $classroomId === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">اعمال فیلتر</button>
            <?php if ($filterQuery !== []): ?>
                <a href="<?= e(url('admin/attendance.php?date=' . $selectedDate)) ?>" class="btn btn-outline">نمایش همه</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="admin-section">
        <?php if ($rows === []): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <h3><?= e(match ($filter) {
                    'missing' => 'مورد ثبت‌نشده‌ای در این فهرست وجود ندارد',
                    'open_checkout' => 'خروج ثبت‌نشده‌ای در این فهرست وجود ندارد',
                    default => 'کودکی در این فهرست یافت نشد',
                }) ?></h3>
                <p><?= e($displayDateLabel) ?></p>
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
                                        <tr>
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
                                            </td>
                                            <td><?= e($parentName !== '' ? $parentName : '—') ?></td>
                                            <td>
                                                <div class="status-radio-group status-radio-group-table flex-items-center gap-xs">
                                                    <?php renderAttendanceRadios($child, $cid); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <input
                                                    class="form-control time-input"
                                                    type="time"
                                                    name="attendance[<?= e((string) $cid) ?>][check_in]"
                                                    value="<?= e(attendanceTimeForInput($child['check_in'] ?? null)) ?>"
                                                    style="padding:4px 8px;"
                                                >
                                            </td>
                                            <td>
                                                <input
                                                    class="form-control time-input"
                                                    type="time"
                                                    name="attendance[<?= e((string) $cid) ?>][check_out]"
                                                    value="<?= e(attendanceTimeForInput($child['check_out'] ?? null)) ?>"
                                                    style="padding:4px 8px;"
                                                >
                                            </td>
                                            <td>
                                                <textarea
                                                    class="form-control notes-field notes-field-table"
                                                    name="attendance[<?= e((string) $cid) ?>][notes]"
                                                    rows="1"
                                                    maxlength="5000"
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
                            <article class="attendance-card">
                                <div class="attendance-card-top">
                                    <?php if (!empty($child['photo'])): ?>
                                        <img class="child-photo-small" src="<?= e(url((string) $child['photo'])) ?>" alt="">
                                    <?php else: ?>
                                        <span class="child-photo-small-placeholder avatar-placeholder-circle" aria-hidden="true"><?= e($initial ?: '?') ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <h2 class="attendance-card-name" style="font-size:1rem;font-weight:700;margin:0;"><?= e($fullName !== '' ? $fullName : 'کودک') ?></h2>
                                        <p class="attendance-card-meta" style="font-size:0.8rem;color:var(--adm-text-muted);margin:2px 0 0;"><?= e(attendanceAgeFromDob((string) ($child['date_of_birth'] ?? ''))) ?> · <?= e($parentName !== '' ? $parentName : '—') ?><?php if ($classroomId === null && !empty($child['classroom_name'])): ?> · <?= e($child['classroom_name']) ?><?php endif; ?></p>
                                    </div>
                                </div>
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
                                            type="time"
                                            name="mobile_attendance[<?= e((string) $cid) ?>][check_in]"
                                            value="<?= e(attendanceTimeForInput($child['check_in'] ?? null)) ?>"
                                            disabled
                                        >
                                    </label>
                                    <label class="time-input-label">
                                        خروج
                                        <input
                                            class="form-control time-input"
                                            type="time"
                                            name="mobile_attendance[<?= e((string) $cid) ?>][check_out]"
                                            value="<?= e(attendanceTimeForInput($child['check_out'] ?? null)) ?>"
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
            el.disabled = !desktop;
        });
        cardControls.forEach(function (el) {
            el.disabled = desktop;
        });
        // Toggle the fieldset disabled state so its child radios follow
        mobileFieldsets.forEach(function (fs) {
            fs.disabled = desktop;
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
