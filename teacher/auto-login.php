<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$rawToken = trim((string) ($_GET['token'] ?? ''));

if ($rawToken === '' || strlen($rawToken) > 64) {
    redirect(url('teacher/login.php'));
}

try {
    initializeTeachersTables();
    $pdo = getDb();

    $stmt = $pdo->prepare(
        'SELECT lt.id, lt.teacher_id, lt.expires_at, lt.used,
                t.first_name, t.last_name, t.status
         FROM login_tokens lt
         INNER JOIN teachers t ON t.id = lt.teacher_id
         WHERE lt.token = :token
         LIMIT 1'
    );
    $stmt->execute([':token' => $rawToken]);
    $row = $stmt->fetch();

    if ($row === false) {
        // Token not found
        redirect(url('teacher/login.php'));
    }

    if ((int) $row['used'] === 1) {
        // Already used
        redirect(url('teacher/login.php'));
    }

    $expires = new DateTimeImmutable((string) $row['expires_at']);
    $now     = new DateTimeImmutable();
    if ($now > $expires) {
        // Expired
        redirect(url('teacher/login.php'));
    }

    if ((string) $row['status'] !== 'active') {
        // Teacher deactivated
        redirect(url('teacher/login.php'));
    }

    // Mark token as used
    $mark = $pdo->prepare('UPDATE login_tokens SET used = 1 WHERE id = :id');
    $mark->execute([':id' => (int) $row['id']]);

    // Set teacher session — admin session keys remain untouched
    session_regenerate_id(true);
    $_SESSION['teacher_id']   = (int) $row['teacher_id'];
    $_SESSION['teacher_name'] = trim($row['first_name'] . ' ' . $row['last_name']);

    redirect(url('teacher/index.php'));
} catch (Throwable $e) {
    error_log($e->getMessage());
    redirect(url('teacher/login.php'));
}
