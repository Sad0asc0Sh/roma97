<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';
require_once __DIR__ . '/../includes/school_planning.php';

requireParentLogin();

/**
 * @param array<string,mixed>|null $rec
 */
function renderParentAttendanceCell(?array $rec): void
{
    if ($rec === null) {
        echo '<span class="attendance-dash">—</span>';
        return;
    }

    $status = (string) ($rec['status'] ?? '');
    $badgeClass = parentPortalAttendanceBadgeClass($status);
    $label = parentPortalAttendanceStatusLabel($status);
    $in = formatIranTime($rec['check_in'] ?? null);
    $out = formatIranTime($rec['check_out'] ?? null);
    $times = [];

    if ($in !== '') {
        $times[] = 'ورود ' . $in;
    }

    if ($out !== '') {
        $times[] = 'خروج ' . $out;
    }

    $timeStr = $times === [] ? '' : implode(' · ', $times);
    ?>
    <span class="attendance-badge <?= e($badgeClass) ?>"><?= e($label)?></span>
    <?php if ($timeStr !== ''): ?>
        <span class="attendance-cell-times"><?= e($timeStr)?></span>
    <?php endif; ?>
    <?php
}

$parentId = (int) $_SESSION['parent_id'];
$today = new DateTimeImmutable('today');
$todayYmd = $today->format('Y-m-d');
$thisSaturday = iranWeekStart($today);
$anchor = iranDateTime(parseIranDate($_GET['week'] ?? null));
$weekStart = $anchor === null ? $thisSaturday : iranWeekStart($anchor);

$weekEnd = $weekStart->modify('+6 day');
$weekStartYmd = $weekStart->format('Y-m-d');
$weekEndYmd = $weekEnd->format('Y-m-d');

$prevWeek = shamsiDate($weekStart->modify('-7 days')->format('Y-m-d'), 'Y-m-d');
$nextWeek = shamsiDate($weekStart->modify('+7 days')->format('Y-m-d'), 'Y-m-d');
$thisWeekParam = shamsiDate($thisSaturday->format('Y-m-d'), 'Y-m-d');

$children = [];
/** @var array<int, array<string, array<string, mixed>>> */
$attendanceByChildDate = [];
/** @var list<array<string, mixed>> */
$weekScheduledEvents = [];
$upcomingNotices = [];
$noticeChildren = [];
$noticeSuccess = getFlash('success');
$noticeError = getFlash('error');
$noticeUrl = url('parent/attendance.php?week=' . shamsiDate($weekStartYmd, 'Y-m-d'));

try {
    initializeParentTables();
    $pdo = getDb();
    ensureSchoolPlanningTables($pdo);

    if (isPostRequest()) {
        try {
            if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
                throw new InvalidArgumentException('درخواست نامعتبر است. دوباره تلاش کنید.');
            }
            $noticeChildId = filter_var($_POST['child_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $noticeDate = parseIranDate($_POST['notice_date'] ?? null);
            if (!is_int($noticeChildId) || $noticeDate === null || $noticeDate < $todayYmd) {
                throw new InvalidArgumentException('کودک و تاریخ امروز یا روزهای بعد را انتخاب کنید.');
            }
            $action = $_POST['action'] ?? null;
            if ($action === 'save_notice') {
                $type = $_POST['notice_type'] ?? null;
                if (!is_string($type) || !isset(ATTENDANCE_NOTICE_TYPES[$type])) {
                    throw new InvalidArgumentException('غیبت یا تأخیر را انتخاب کنید.');
                }
                $arrival = $type === 'late'
                    ? optionalIranTime($_POST['expected_arrival'] ?? null, 'ساعت تقریبی ورود را به صورت ۲۴ ساعته وارد کنید، مانند ۱۴:۳۰.')
                    : null;
                $note = $_POST['note'] ?? '';
                if (!is_string($note) || mb_strlen($note, 'UTF-8') > 500) {
                    throw new InvalidArgumentException('یادداشت می‌تواند حداکثر ۵۰۰ نویسه باشد.');
                }
                saveParentAttendanceNotice($pdo, $parentId, $noticeChildId, $noticeDate, $type,
                    $arrival, trim($note) === '' ? null : trim($note));
                setFlash('success', 'اعلام شما برای پذیرش و مربی ثبت شد.');
            } elseif ($action === 'cancel_notice') {
                saveParentAttendanceNotice($pdo, $parentId, $noticeChildId, $noticeDate, null, null, null);
                setFlash('success', 'اعلام قبلی لغو شد.');
            } else {
                throw new InvalidArgumentException('درخواست نامعتبر است.');
            }
        } catch (Throwable $exception) {
            if (!$exception instanceof InvalidArgumentException) {
                error_log('Parent attendance notice: ' . $exception->getMessage());
            }
            setFlash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'ذخیره اعلام انجام نشد. دوباره تلاش کنید.');
        }
        redirect($noticeUrl . '#attendance-notice');
    }

    $childStmt = $pdo->prepare(
        'SELECT c.id, c.first_name, c.last_name, c.photo, c.status,
                EXISTS (SELECT 1 FROM classroom_workdays w WHERE w.classroom_id = cc.classroom_id) AS has_workdays
         FROM children c LEFT JOIN child_classroom cc ON cc.child_id = c.id
         WHERE c.parent_id = :parent_id
         ORDER BY c.last_name ASC, c.first_name ASC'
    );
    $childStmt->execute([':parent_id' => $parentId]);
    $children = $childStmt->fetchAll();
    $noticeChildren = array_filter($children, static fn (array $row): bool => $row['status'] === 'active' && (int) $row['has_workdays'] === 1);
    $noticeStmt = $pdo->prepare(
        'SELECT n.*, c.first_name, c.last_name, a.id AS attendance_id FROM attendance_notices n
         INNER JOIN children c ON c.id = n.child_id AND c.parent_id = n.parent_id
         LEFT JOIN attendance a ON a.child_id = n.child_id AND a.attendance_date = n.notice_date
         WHERE n.parent_id = ? AND n.notice_date >= ?
         ORDER BY n.notice_date, n.child_id'
    );
    $noticeStmt->execute([$parentId, $todayYmd]);
    $upcomingNotices = $noticeStmt->fetchAll();

    $attStmt = $pdo->prepare(
        <<<'SQL'
SELECT
    a.child_id,
    a.attendance_date,
    a.status,
    a.check_in,
    a.check_out,
    a.notes
FROM attendance a
INNER JOIN children c ON c.id = a.child_id AND c.parent_id = :parent_id
WHERE a.attendance_date >= :d_start AND a.attendance_date <= :d_end
SQL
    );
    $attStmt->execute([
        ':parent_id' => $parentId,
        ':d_start' => $weekStartYmd,
        ':d_end' => $weekEndYmd,
    ]);

    while ($row = $attStmt->fetch()) {
        $cid = (int) $row['child_id'];
        $d = (string) $row['attendance_date'];

        if (!isset($attendanceByChildDate[$cid])) {
            $attendanceByChildDate[$cid] = [];
        }

        $attendanceByChildDate[$cid][$d] = $row;
    }

    $eventStmt = $pdo->prepare(
        <<<'SQL'
SELECT title, description, event_date, start_time, end_time, category
FROM events
WHERE event_date >= :start AND event_date <= :end AND status = 'scheduled'
ORDER BY event_date ASC, start_time IS NULL ASC, start_time ASC, id ASC
SQL
    );
    $eventStmt->execute([
        ':start' => $weekStartYmd,
        ':end' => $weekEndYmd,
    ]);
    $weekScheduledEvents = $eventStmt->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $noticeError ??= 'بارگذاری حضور و غیاب انجام نشد.';
}

$selectedNoticeChild = (int) filter_var($_GET['notice_child'] ?? null, FILTER_VALIDATE_INT);
$selectedNoticeDate = parseIranDate($_GET['notice_date'] ?? null);
$selectedNotice = null;
$eligibleNoticeChildIds = array_fill_keys(array_column($noticeChildren, 'id'), true);
foreach ($upcomingNotices as $notice) {
    if (isset($eligibleNoticeChildIds[$selectedNoticeChild]) && (int) $notice['child_id'] === $selectedNoticeChild
        && $notice['notice_date'] === $selectedNoticeDate && $notice['attendance_id'] === null) {
        $selectedNotice = $notice;
        break;
    }
}

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekDays[] = $weekStart->modify('+' . $i . ' day');
}

// Build Persian week range label
$weekStartShamsi = shamsiDate($weekStartYmd);
$weekEndShamsi = shamsiDate($weekEndYmd);
$weekRangeLabel = 'از ' . $weekStartShamsi . ' تا ' . $weekEndShamsi;

/** @var array<string, list<array<string, mixed>>> */
$eventsGroupedByDay = [];

foreach ($weekScheduledEvents as $evRow) {
    $dk = (string) $evRow['event_date'];

    if (!isset($eventsGroupedByDay[$dk])) {
        $eventsGroupedByDay[$dk] = [];
    }

    $eventsGroupedByDay[$dk][] = $evRow;
}

// Calculate weekly summary
$totalPresent = 0;
$totalAbsent = 0;
$totalLate = 0;
$totalExcused = 0;

foreach ($attendanceByChildDate as $childAtt) {
    foreach ($childAtt as $att) {
        $status = (string) ($att['status'] ?? '');
        match($status) {
            'present' => $totalPresent++,
            'absent' => $totalAbsent++,
            'late' => $totalLate++,
            'excused' => $totalExcused++,
            default => null
        };
    }
}

$pageTitle = 'حضور و غیاب';
require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <h1>حضور و غیاب <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-left: 6px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></h1>
    <p class="page-subtitle"><?= e($weekRangeLabel)?></p>
</div>

<?php require __DIR__ . '/partials/attendance-notices.php'; ?>

<!-- Weekly Summary -->
<?php if ($children !== []): ?>
    <div class="attendance-summary-card">
        <h2>خلاصه این هفته</h2>
        <div class="summary-stats">
            <div class="stat-item">
                <span class="stat-value"><?= e(persianNumber((string) $totalPresent))?></span>
                <span class="stat-label">حاضر</span>
           </div>
            <div class="stat-item">
                <span class="stat-value"><?= e(persianNumber((string) $totalAbsent))?></span>
                <span class="stat-label">غایب</span>
           </div>
            <div class="stat-item">
                <span class="stat-value"><?= e(persianNumber((string) $totalLate))?></span>
                <span class="stat-label">تأخیر</span>
           </div>
            <div class="stat-item">
                <span class="stat-value"><?= e(persianNumber((string) $totalExcused))?></span>
                <span class="stat-label">غیبت موجه</span>
           </div>
       </div>
   </div>
<?php endif; ?>

<!-- Week Navigation -->
<nav class="week-navigation" aria-label="انتخاب هفته">
    <a class="btn btn-outline" href="<?= e(url('parent/attendance.php?week=' . rawurlencode($prevWeek))) ?>">‹ هفته قبل</a>
    <a class="btn btn-primary" href="<?= e(url('parent/attendance.php?week=' . rawurlencode($thisWeekParam))) ?>">این هفته</a>
    <a class="btn btn-outline" href="<?= e(url('parent/attendance.php?week=' . rawurlencode($nextWeek))) ?>">هفته بعد ›</a>
</nav>

<?php if ($children === []): ?>
    <div class="parent-empty-state">
        <div class="empty-state-icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <h3>هنوز کودکی ثبت نشده است</h3>
        <p>برای مشاهده سوابق حضور و غیاب، ابتدا یک کودک اضافه کنید</p>
        <a class="btn btn-primary" href="<?= e(url('parent/add-child.php')) ?>">افزودن کودک</a>
   </div>
<?php else: ?>
    <!-- Desktop Table View -->
    <div class="attendance-calendar-container">
        <div class="calendar-table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr>
                        <th scope="col" class="child-col">کودک</th>
                        <?php foreach ($weekDays as $day): ?>
                            <th scope="col" class="day-col">
                                <span class="day-name"><?= e(persianDayName($day->format('Y-m-d'))) ?></span>
                                <span class="day-date"><?= e(persianNumber($day->format('j'))) ?></span>
                           </th>
                        <?php endforeach; ?>
                   </tr>
               </thead>
                <tbody>
                    <?php foreach ($children as $child): ?>
                        <?php
                        $cid = (int) $child['id'];
                        $childName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                        ?>
                        <tr>
                            <th scope="row" class="child-name-cell">
                                <?php if (!empty($child['photo'])): ?>
                                    <img class="child-photo-small" src="<?= e(url((string) $child['photo'])) ?>" alt="">
                                <?php endif; ?>
                                <span><?= e($childName)?></span>
                           </th>
                            <?php foreach ($weekDays as $day): ?>
                                <?php
                                $dKey = $day->format('Y-m-d');
                                $rec = $attendanceByChildDate[$cid][$dKey] ?? null;
                                ?>
                                <td class="attendance-cell">
                                    <?php renderParentAttendanceCell($rec); ?>
                               </td>
                            <?php endforeach; ?>
                       </tr>
                    <?php endforeach; ?>
               </tbody>
           </table>
       </div>
   </div>

    <!-- Mobile Card View -->
    <div class="attendance-cards-mobile">
        <?php foreach ($children as $child): ?>
            <?php
            $cid = (int) $child['id'];
            $childName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
            ?>
            <div class="child-attendance-card">
                <h3 class="child-card-name"><?= e($childName)?></h3>
                <div class="attendance-days-grid">
                    <?php foreach ($weekDays as $day): ?>
                        <?php
                        $dKey = $day->format('Y-m-d');
                        $rec = $attendanceByChildDate[$cid][$dKey] ?? null;
                        ?>
                        <div class="attendance-day-item">
                            <span class="day-label"><?= e(persianDayName($dKey) . ' ' . persianNumber($day->format('j'))) ?></span>
                            <?php if ($rec === null): ?>
                                <span class="attendance-dash">—</span>
                            <?php else: ?>
                                <?php
                                $status = (string) ($rec['status'] ?? '');
                                $badgeClass = parentPortalAttendanceBadgeClass($status);
                                $label = parentPortalAttendanceStatusLabel($status);
                                $in = formatIranTime($rec['check_in'] ?? null);
                                $out = formatIranTime($rec['check_out'] ?? null);
                                ?>
                                <span class="attendance-badge <?= e($badgeClass) ?>"><?= e($label)?></span>
                                <?php if ($in !== '' || $out !== ''): ?>
                                    <span class="attendance-times-small">
                                        <?php if ($in !== ''): ?>ورود <?= e($in) ?><?php endif; ?>
                                        <?php if ($out !== ''): ?> · خروج <?= e($out) ?><?php endif; ?>
                                   </span>
                                <?php endif; ?>
                            <?php endif; ?>
                       </div>
                    <?php endforeach; ?>
               </div>
           </div>
        <?php endforeach; ?>
   </div>
<?php endif; ?>

<!-- This Week's Events -->
<?php if ($weekScheduledEvents !== []): ?>
    <div class="events-section">
        <h2>رویدادهای این هفته</h2>
        <div class="events-grid">
            <?php foreach ($weekScheduledEvents as $ev): ?>
                <?php
                $ecat = (string) ($ev['category'] ?: 'general');
                $st = formatIranTime($ev['start_time'] ?? null);
                $et = formatIranTime($ev['end_time'] ?? null);
                $timeLine = $st !== '' ? $st . ($et !== '' ? ' – ' . $et : '') : ($et !== '' ? $et : '');
                $ed = (string) $ev['event_date'];
                $edLabel = persianDayName($ed) . ' ' . shamsiDate($ed);
                ?>
                <div class="event-card">
                    <span class="event-date"><?= e($edLabel)?></span>
                    <h3 class="event-title"><?= e((string) $ev['title'])?></h3>
                    <span class="event-badge event-badge-<?= e($ecat) ?>"><?= e(parentPortalEventCategoryLabel($ecat)) ?></span>
                    <?php if ($timeLine !== ''): ?>
                        <p class="event-time">ساعت <?= e($timeLine)?></p>
                    <?php endif; ?>
                    <?php if (trim((string) ($ev['description'] ?? '')) !== ''): ?>
                        <p class="event-desc"><?= e((string) $ev['description'])?></p>
                    <?php endif; ?>
               </div>
            <?php endforeach; ?>
       </div>
   </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
