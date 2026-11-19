<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

function ensureClassroomActivitiesTables(PDO $pdo): void
{
    $ready = $pdo->query(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classroom_activity_attendance'"
    )->fetchColumn();
    if ($ready !== false) {
        return;
    }

    // Older installations can also apply this migration before deploying the pages.
    $migration = file_get_contents(__DIR__ . '/../db/migrations/013_classroom_daily_activities.sql');
    if ($migration === false) {
        throw new RuntimeException('Classroom activities migration is missing.');
    }
    foreach (explode(';', $migration) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
}

/** Already scoped to the teacher's classroom. Save the text and its audience together. */
function saveClassroomActivity(PDO $pdo, int $classroomId, string $date, string $activity): void
{
    $key = ['classroom_id' => $classroomId, 'activity_date' => $date];
    if ($activity === '') {
        $stmt = $pdo->prepare(
            'DELETE FROM classroom_daily_activities WHERE classroom_id = :classroom_id AND activity_date = :activity_date'
        );
        $stmt->execute($key);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO classroom_daily_activities (classroom_id, activity_date, activity)
         VALUES (:classroom_id, :activity_date, :activity)
         ON DUPLICATE KEY UPDATE activity = VALUES(activity)'
    );
    $stmt->execute($key + ['activity' => $activity]);

    // Keep the original audience across later class transfers. Re-saving can
    // also include children whose attendance was recorded after the first save.
    $stmt = $pdo->prepare(
        "INSERT INTO classroom_activity_attendance (activity_id, attendance_id)
         SELECT ca.id, a.id FROM classroom_daily_activities ca
         INNER JOIN child_classroom cc ON cc.classroom_id = ca.classroom_id AND cc.enrollment_date <= ca.activity_date
         INNER JOIN attendance a ON a.child_id = cc.child_id AND a.attendance_date = ca.activity_date
         WHERE ca.classroom_id = :classroom_id AND ca.activity_date = :activity_date
           AND a.status IN ('present', 'late')
         ON DUPLICATE KEY UPDATE attendance_id = classroom_activity_attendance.attendance_id"
    );
    $stmt->execute($key);
}

/** The caller must authorize the child before fetching their shared activities. */
function childClassroomActivities(PDO $pdo, int $childId, string $since): array
{
    ensureClassroomActivitiesTables($pdo);
    $stmt = $pdo->prepare(
        "SELECT ca.activity_date, ca.activity, cl.name AS classroom_name
         FROM attendance a
         INNER JOIN classroom_activity_attendance caa ON caa.attendance_id = a.id
         INNER JOIN classroom_daily_activities ca ON ca.id = caa.activity_id
         INNER JOIN classrooms cl ON cl.id = ca.classroom_id
         WHERE a.child_id = :child_id AND a.attendance_date >= :since
           AND a.status IN ('present', 'late')
         ORDER BY ca.activity_date DESC, cl.name, cl.id"
    );
    $stmt->execute(['child_id' => $childId, 'since' => $since]);
    return $stmt->fetchAll();
}
