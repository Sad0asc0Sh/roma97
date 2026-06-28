<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

$rawToken = trim((string) ($_GET['token'] ?? ''));

if ($rawToken === '' || strlen($rawToken) > 64) {
    redirect(url('teacher/login.php'));
}

try {
    initializeTeachersTables();
    $pdo = getDb();

    // Atomically claim the token with a conditional UPDATE. This closes the
    // race where two concurrent requests with the same token could both pass
    // the SELECT guard and each create a session. The UPDATE locks the row and
    // affects exactly one request; the other sees 0 affected rows.
    $claim = $pdo->prepare(
        'UPDATE login_tokens
         SET used = 1
         WHERE token = :token
           AND used = 0
           AND expires_at > NOW()'
    );
    $claim->execute([':token' => $rawToken]);

    if ($claim->rowCount() === 0) {
        // Token missing, already used, or expired.
        redirect(url('teacher/login.php'));
    }

    // Load the now-claimed token + teacher (teacher must still be active).
    $stmt = $pdo->prepare(
        'SELECT lt.id, lt.teacher_id,
                t.first_name, t.last_name, t.status
         FROM login_tokens lt
         INNER JOIN teachers t ON t.id = lt.teacher_id
         WHERE lt.token = :token
         LIMIT 1'
    );
    $stmt->execute([':token' => $rawToken]);
    $row = $stmt->fetch();

    if ($row === false || (string) $row['status'] !== 'active') {
        // Teacher deactivated since the token was issued.
        redirect(url('teacher/login.php'));
    }

    // Set teacher session — admin session keys remain untouched
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
    $_SESSION['teacher_id']   = (int) $row['teacher_id'];
    $_SESSION['teacher_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
    rotateCsrfToken();

    recordAudit('auth.login_token', 'teacher', (int) $row['teacher_id']);

    redirect(url('teacher/index.php'));
} catch (Throwable $e) {
    error_log($e->getMessage());
    redirect(url('teacher/login.php'));
}
