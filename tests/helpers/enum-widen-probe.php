<?php
declare(strict_types=1);

/**
 * Subprocess probe used by tests/parent-phone-test.php.
 *
 * Runs initializeFinancialTables() in a FRESH PHP process (mirroring
 * production, where each request is its own process and the static
 * $initialized guard starts unset) against the existing test database,
 * then prints the sms_verification_codes.action_type COLUMN_TYPE.
 *
 * Not a standalone suite: it expects the test database to already exist.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../test-bootstrap.php';
require dirname(__DIR__, 2) . '/includes/db.php';

initializeFinancialTables();

$pdo = testDbOpen();
$colType = $pdo->query("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'sms_verification_codes'
      AND column_name = 'action_type'
")->fetchColumn();

echo (string) $colType . "\n";
