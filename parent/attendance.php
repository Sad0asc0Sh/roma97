<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';

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
    $in = parentPortalFormatTimeShort($rec['check_in'] ?? null);
    $out = parentPortalFormatTimeShort($rec['check_out'] ?? null);
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
$thisMonday = parentPortalMondayOfDate($today);

$weekParam = trim((string) ($_GET['week'] ?? ''));
$parsedWeek = $weekParam === '' ? null : parentPortalParseDateYmd($weekParam);

if ($parsedWeek === null) {
    $weekStart = $thisMonday;
} else {
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $parsedWeek);
    $weekStart = $anchor !== false ? parentPortalMondayOfDate($anchor) : $thisMonday;
}

$weekEnd = $weekStart->modify('+6 day');
$weekStartYmd = $weekStart->format('Y-m-d');
$weekEndYmd = $weekEnd->format('Y-m-d');

$prevMonday = $weekStart->modify('-7 day')->format('Y-m-d');
$nextMonday = $weekStart->modify('+7 day')->format('Y-m-d');
$thisWeekParam = $thisMonday->format('Y-m-d');

$children = [];
/** @var array<int, array<string, array<string, mixed>>> */
$attendanceByChildDate = [];
/** @var list<array<string, mixed>> */
$weekScheduledEvents = [];

try {
    initializeParentTables();
    $pdo = getDb();

    $childStmt = $pdo->prepare(
        'SELECT id, first_name, last_name, photo, status
         FROM children
         WHERE parent_id = :parent_id
         ORDER BY last_name ASC, first_name ASC'
    );
    $childStmt->execute([':parent_id' => $parentId]);
    $children = $childStmt->fetchAll();

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
    <h1>حضور و غیاب 📅</h1>
    <p class="page-subtitle"><?= e($weekRangeLabel)?></p>
</div>

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
    <a class="btn btn-outline" href="<?= e(url('parent/attendance.php?week=' . rawurlencode($prevMonday))) ?>">‹ هفته قبل</a>
    <a class="btn btn-primary" href="<?= e(url('parent/attendance.php?week=' . rawurlencode($thisWeekParam))) ?>">این هفته</a>
    <a class="btn btn-outline" href="<?= e(url('parent/attendance.php?week=' . rawurlencode($nextMonday))) ?>">هفته بعد ›</a>
</nav>

<?php if ($children === []): ?>
    <div class="parent-empty-state">
        <div class="empty-state-icon">📅</div>
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
                                <span class="day-name"><?= e(persianDayNameShort($day->format('Y-m-d'))) ?></span>
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
                                $in = parentPortalFormatTimeShort($rec['check_in'] ?? null);
                                $out = parentPortalFormatTimeShort($rec['check_out'] ?? null);
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
                $st = parentPortalFormatTimeShort($ev['start_time'] ?? null);
                $et = parentPortalFormatTimeShort($ev['end_time'] ?? null);
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
