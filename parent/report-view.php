<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/operations_pilot.php';

requireParentLogin();

if (!isPostRequest()) {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit;
}
$reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($reportId === false) {
    http_response_code(400);
    exit;
}

try {
    recordOperationsPilotReportView(getDb(), $reportId, (int) $_SESSION['parent_id']);
    http_response_code(204);
} catch (Throwable $exception) {
    error_log('Parent report pilot view: ' . $exception->getMessage());
    http_response_code(503);
}
