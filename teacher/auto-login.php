<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

$rawToken = trim((string) ($_GET['token'] ?? ''));

if ($rawToken === '' || strlen($rawToken) > 64) {
    redirect(url('login.php'));
}

try {
    initializeTeachersTables();
    $pdo = getDb();

    $claim = $pdo->prepare(
        'UPDATE login_tokens
         SET used = 1
         WHERE token = :token
           AND used = 0
           AND expires_at > NOW()'
    );
    $claim->execute([':token' => $rawToken]);

    if ($claim->rowCount() === 0) {
        redirect(url('login.php'));
    }

    $stmt = $pdo->prepare(
        'SELECT lt.id, lt.teacher_id,
                t.first_name, t.last_name, t.status, t.role_title, t.avatar
         FROM login_tokens lt
         INNER JOIN teachers t ON t.id = lt.teacher_id
         WHERE lt.token = :token
         LIMIT 1'
    );
    $stmt->execute([':token' => $rawToken]);
    $row = $stmt->fetch();

    if ($row === false || (string) $row['status'] !== 'active') {
        redirect(url('login.php'));
    }

    session_regenerate_id(true);
    $_SESSION['last_activity']      = time();
    $_SESSION['teacher_id']         = (int) $row['teacher_id'];
    $_SESSION['teacher_name']       = trim($row['first_name'] . ' ' . $row['last_name']);
    $_SESSION['teacher_first_name'] = (string) $row['first_name'];
    $_SESSION['teacher_role_title'] = (string) ($row['role_title'] ?? 'مربی مهد');
    $_SESSION['teacher_avatar']     = (string) ($row['avatar'] ?? '');
    rotateCsrfToken();

    recordAudit('auth.login_token', 'teacher', (int) $row['teacher_id']);

    redirect(url('teacher/index.php'));
} catch (Throwable $e) {
    error_log($e->getMessage());
    redirect(url('login.php'));
}
