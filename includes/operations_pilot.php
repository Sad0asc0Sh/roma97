<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

function ensureOperationsPilotTables(PDO $pdo): void
{
    static $ready = false;
    if (!$ready) {
        ensureSqlMigrationTables($pdo, '018_operations_pilot.sql', ['operations_pilots', 'operations_pilot_days', 'operations_pilot_reports']);
        $ready = true;
    }
}

/** Two consecutive seven-day windows in the app's timezone. */
function operationsPilotWeeks(string $changeDate): array
{
    $start = (new DateTimeImmutable($changeDate))->modify('-7 days');
    $weeks = ['before' => [], 'after' => []];
    for ($i = 0; $i < 14; $i++) {
        $weeks[$i < 7 ? 'before' : 'after'][] = $start->modify("+{$i} days")->format('Y-m-d');
    }
    return $weeks;
}

function createOperationsPilot(PDO $pdo, int $classroomId, string $changeDate): int
{
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? FOR UPDATE');
        $lock->execute([$classroomId]);
        if ($lock->fetchColumn() === false) {
            throw new InvalidArgumentException('کلاس انتخاب‌شده پیدا نشد.');
        }
        $existing = $pdo->prepare(
            'SELECT id FROM operations_pilots WHERE classroom_id = ?
             AND change_date > DATE_SUB(?, INTERVAL 14 DAY) AND change_date < DATE_ADD(?, INTERVAL 14 DAY) LIMIT 1'
        );
        $existing->execute([$classroomId, $changeDate, $changeDate]);
        if ($existing->fetchColumn() !== false) {
            throw new InvalidArgumentException('برای این کلاس، آزمونی با بازهٔ هم‌پوشان وجود دارد.');
        }
        $insert = $pdo->prepare('INSERT INTO operations_pilots (classroom_id, change_date, created_at) VALUES (?, ?, ?)');
        $insert->execute([$classroomId, $changeDate, date('Y-m-d H:i:s')]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function operationsPilotForClass(PDO $pdo, int $classroomId, string $today): ?int
{
    ensureOperationsPilotTables($pdo);
    $stmt = $pdo->prepare(
        'SELECT id FROM operations_pilots WHERE classroom_id = ?
         AND DATE_SUB(change_date, INTERVAL 7 DAY) <= ? AND ? < DATE_ADD(change_date, INTERVAL 7 DAY)
         ORDER BY change_date LIMIT 1'
    );
    $stmt->execute([$classroomId, $today, $today]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

function operationsPilotViewCandidates(PDO $pdo, int $childId): array
{
    ensureOperationsPilotTables($pdo);
    $stmt = $pdo->prepare(
        'SELECT m.report_id FROM operations_pilot_reports m INNER JOIN daily_reports r ON r.id = m.report_id
         WHERE r.child_id = ? AND m.first_viewed_at IS NULL AND m.published_at >= DATE_SUB(?, INTERVAL 48 HOUR)'
    );
    $stmt->execute([$childId, date('Y-m-d H:i:s')]);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

function recordOperationsPilotReportView(PDO $pdo, int $reportId, int $parentId): void
{
    ensureOperationsPilotTables($pdo);
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'UPDATE operations_pilot_reports m INNER JOIN daily_reports r ON r.id = m.report_id
         INNER JOIN children c ON c.id = r.child_id
         SET m.first_viewed_at = ? WHERE m.report_id = ? AND c.parent_id = ? AND r.report_date <= ?
           AND m.first_viewed_at IS NULL AND m.published_at <= ? AND m.published_at >= DATE_SUB(?, INTERVAL 48 HOUR)'
    );
    $stmt->execute([$now, $reportId, $parentId, date('Y-m-d'), $now, $now]);
}

function saveOperationsPilotDay(PDO $pdo, int $pilotId, string $day, bool $worked, array $values): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO operations_pilot_days (pilot_id, work_date, worked, manager_minutes, open_cases, closed_cases, independent_cases)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE worked = VALUES(worked), manager_minutes = VALUES(manager_minutes),
            open_cases = VALUES(open_cases), closed_cases = VALUES(closed_cases), independent_cases = VALUES(independent_cases)'
    );
    $stmt->execute([$pilotId, $day, (int) $worked, $values['manager_minutes'], $values['open_cases'], $values['closed_cases'], $values['independent_cases']]);
}

/** Ratios use total cases or reports, and blank observations do not become zero. */
function operationsPilotSummary(array $dates, array $days, array $reports, DateTimeImmutable $now): array
{
    $worked = $minutes = $open = $closed = $independent = $recorded = 0;
    foreach ($dates as $date) {
        if (!isset($days[$date])) {
            continue;
        }
        $recorded++;
        $day = $days[$date];
        if (!(bool) $day['worked']) {
            continue;
        }
        $worked++;
        $minutes += (int) $day['manager_minutes'];
        $open += (int) $day['open_cases'];
        $closed += (int) $day['closed_cases'];
        $independent += (int) $day['independent_cases'];
    }

    $published = $measurableViews = $views = $timed = $totalMs = 0;
    $maturedAt = $now->modify('-48 hours')->format('Y-m-d H:i:s');
    foreach ($reports as $report) {
        if (!in_array(substr($report['published_at'], 0, 10), $dates, true)) {
            continue;
        }
        $published++;
        if ($report['work_ms'] !== null) {
            $timed++;
            $totalMs += (int) $report['work_ms'];
        }
        if ($report['published_at'] <= $maturedAt) {
            $measurableViews++;
            $views += (int) ($report['first_viewed_at'] !== null);
        }
    }

    return [
        'days_recorded' => $recorded, 'manager_minutes_total' => $minutes, 'closed' => $closed, 'independent' => $independent,
        'published' => $published, 'measurable_views' => $measurableViews, 'views' => $views, 'timed' => $timed,
        'manager_minutes' => $worked === 0 ? null : $minutes / $worked,
        'open_cases' => $worked === 0 ? null : $open / $worked,
        'independent_cases' => $closed === 0 ? null : $independent * 100 / $closed,
        'report_views' => $measurableViews === 0 ? null : $views * 100 / $measurableViews,
        'report_minutes' => $timed === 0 ? null : $totalMs / 60000 / $timed,
    ];
}
