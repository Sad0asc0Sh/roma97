<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/** Preserve the enrollment date when saving the same class again. */
function saveClassroomEnrollment(PDO $pdo, int $childId, ?int $classroomId): bool
{
    if ($classroomId === null) {
        $pdo->prepare('DELETE FROM child_classroom WHERE child_id = ?')->execute([$childId]);
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO child_classroom (child_id, classroom_id, enrollment_date) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            enrollment_date = IF(classroom_id = VALUES(classroom_id), enrollment_date, VALUES(enrollment_date)),
            classroom_id = VALUES(classroom_id)'
    );
    $stmt->execute([$childId, $classroomId, date('Y-m-d')]);

    $close = $pdo->prepare(
        'UPDATE classroom_waitlist SET fulfilled_at = ?
         WHERE child_id = ? AND classroom_id = ? AND fulfilled_at IS NULL'
    );
    $close->execute([date('Y-m-d H:i:s'), $childId, $classroomId]);
    return $close->rowCount() > 0;
}
