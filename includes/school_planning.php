<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

const SCHOOL_WEEKDAYS = [5 => 'شنبه', 6 => 'یکشنبه', 0 => 'دوشنبه', 1 => 'سه‌شنبه',
    2 => 'چهارشنبه', 3 => 'پنجشنبه', 4 => 'جمعه'];
const ATTENDANCE_NOTICE_TYPES = ['absent' => 'غیبت', 'late' => 'تأخیر'];

function ensureSchoolPlanningTables(PDO $pdo): void
{
    static $ready = false;
    if (!$ready) {
        ensureSqlMigrationTables($pdo, '015_school_day_planning.sql', ['classroom_workdays', 'school_holidays', 'attendance_notices']);
        $ready = true;
    }
}

/** A classroom with zero rows still needs its weekly schedule configured. */
function classroomWorkdays(PDO $pdo, ?int $classroomId = null): array
{
    ensureSchoolPlanningTables($pdo);
    $query = 'SELECT classroom_id, weekday FROM classroom_workdays';
    $stmt = $pdo->prepare($classroomId === null ? $query : $query . ' WHERE classroom_id = ?');
    $stmt->execute($classroomId === null ? [] : [$classroomId]);
    $byClass = [];
    foreach ($stmt->fetchAll() as $row) {
        $byClass[(int) $row['classroom_id']][] = (int) $row['weekday'];
    }
    return $byClass;
}

/** Called in the same transaction as the classroom save. */
function saveClassroomWorkdays(PDO $pdo, int $classroomId, array $days): void
{
    $pdo->prepare('DELETE FROM classroom_workdays WHERE classroom_id = ?')->execute([$classroomId]);
    $insert = $pdo->prepare('INSERT INTO classroom_workdays (classroom_id, weekday) VALUES (?, ?)');
    foreach ($days as $day) {
        $insert->execute([$classroomId, $day]);
    }
}

function schoolHolidayTitle(PDO $pdo, string $date): ?string
{
    ensureSchoolPlanningTables($pdo);
    // Calendar events remain the source of truth, including legacy/imported holidays.
    $stmt = $pdo->prepare(
        "SELECT COALESCE(
            (SELECT e.title FROM school_holidays h INNER JOIN events e ON e.id = h.event_id
             WHERE h.holiday_date = ? AND e.event_date = h.holiday_date
               AND e.category = 'holiday' AND e.status IN ('scheduled', 'completed') ORDER BY e.id LIMIT 1),
            (SELECT e.title FROM events e WHERE e.event_date = ? AND e.category = 'holiday'
               AND e.status IN ('scheduled', 'completed') ORDER BY e.id LIMIT 1)
        )"
    );
    $stmt->execute([$date, $date]);
    $title = $stmt->fetchColumn();
    return is_string($title) ? $title : null;
}

/** Keep the full-day closure in sync with its calendar event. */
function syncSchoolHoliday(PDO $pdo, int $eventId, string $date, string $category, string $status): void
{
    if ($category === 'holiday' && in_array($status, ['scheduled', 'completed'], true)) {
        $pdo->prepare(
            'INSERT INTO school_holidays (event_id, holiday_date) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE holiday_date = VALUES(holiday_date)'
        )->execute([$eventId, $date]);
    } else {
        $pdo->prepare('DELETE FROM school_holidays WHERE event_id = ?')->execute([$eventId]);
    }
}

/** Keys of the expected/missing/notices maps are child IDs. */
function dailyAttendancePlan(PDO $pdo, string $date, ?int $classroomId = null): array
{
    $plan = ['holiday' => schoolHolidayTitle($pdo, $date), 'expected' => [], 'missing' => [], 'notices' => []];
    $stmt = $pdo->prepare(
        "SELECT cc.child_id, w.weekday, a.status AS attendance_status, n.notice_type, n.expected_arrival, n.note
         FROM child_classroom cc INNER JOIN children c ON c.id = cc.child_id AND c.status = 'active'
         LEFT JOIN classroom_workdays w ON w.classroom_id = cc.classroom_id AND w.weekday = WEEKDAY(?)
         LEFT JOIN attendance a ON a.child_id = cc.child_id AND a.attendance_date = ?
         LEFT JOIN attendance_notices n ON n.child_id = cc.child_id AND n.parent_id = c.parent_id AND n.notice_date = ?
         WHERE cc.enrollment_date <= ?" . ($classroomId === null ? '' : ' AND cc.classroom_id = ?')
    );
    $params = [$date, $date, $date, $date];
    if ($classroomId !== null) {
        $params[] = $classroomId;
    }
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $childId = (int) $row['child_id'];
        if ($row['notice_type'] !== null) {
            $plan['notices'][$childId] = $row;
        }
        $announcedAbsence = $row['notice_type'] === 'absent' && !in_array($row['attendance_status'], ['present', 'late'], true);
        if ($plan['holiday'] === null && $row['weekday'] !== null && !$announcedAbsence) {
            $plan['expected'][$childId] = true;
            if ($row['attendance_status'] === null) {
                $plan['missing'][$childId] = true;
            }
        }
    }
    return $plan;
}

function attendanceNoticeLabel(array $notice): string
{
    $label = ATTENDANCE_NOTICE_TYPES[$notice['notice_type']] . ' اعلام‌شده از طرف خانواده';
    return $notice['expected_arrival'] === null
        ? $label : $label . ' · ورود حدود ' . persianNumber(substr($notice['expected_arrival'], 0, 5));
}

/** A null type cancels an existing notice. Actual attendance stays staff-owned. */
function saveParentAttendanceNotice(PDO $pdo, int $parentId, int $childId, string $date, ?string $type, ?string $arrival, ?string $note): void
{
    ensureSchoolPlanningTables($pdo);
    $pdo->beginTransaction();
    try {
        $child = $pdo->prepare(
            'SELECT c.status, cc.classroom_id, cc.enrollment_date FROM children c
             LEFT JOIN child_classroom cc ON cc.child_id = c.id
             WHERE c.id = ? AND c.parent_id = ? FOR UPDATE'
        );
        $child->execute([$childId, $parentId]);
        $row = $child->fetch();
        if (!$row) {
            throw new InvalidArgumentException('پرونده این کودک در دسترس نیست.');
        }
        $attendance = $pdo->prepare('SELECT id FROM attendance WHERE child_id = ? AND attendance_date = ? FOR UPDATE');
        $attendance->execute([$childId, $date]);
        if ($attendance->fetchColumn() !== false) {
            throw new InvalidArgumentException('وضعیت حضور این روز ثبت شده است. برای اصلاح با پذیرش هماهنگ کنید.');
        }
        if ($type === null) {
            $pdo->prepare('DELETE FROM attendance_notices WHERE child_id = ? AND notice_date = ? AND parent_id = ?')
                ->execute([$childId, $date, $parentId]);
            $pdo->commit();
            return;
        }

        if ($row['status'] !== 'active' || $row['classroom_id'] === null || $row['enrollment_date'] > $date) {
            throw new InvalidArgumentException('این کودک در تاریخ انتخاب‌شده کلاس فعال ندارد.');
        }
        $holiday = schoolHolidayTitle($pdo, $date);
        if ($holiday !== null) {
            throw new InvalidArgumentException('مهد در این تاریخ تعطیل است. تاریخ دیگری انتخاب کنید.');
        }
        $workday = $pdo->prepare('SELECT 1 FROM classroom_workdays WHERE classroom_id = ? AND weekday = WEEKDAY(?)');
        $workday->execute([(int) $row['classroom_id'], $date]);
        if ($workday->fetchColumn() === false) {
            throw new InvalidArgumentException('در این تاریخ روز کاری برای کلاس کودک ثبت نشده است.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO attendance_notices (child_id, notice_date, parent_id, notice_type, expected_arrival, note)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), notice_type = VALUES(notice_type),
                expected_arrival = VALUES(expected_arrival), note = VALUES(note)'
        );
        $stmt->execute([$childId, $date, $parentId, $type, $arrival, $note]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
