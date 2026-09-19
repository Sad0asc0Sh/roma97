<?php
declare(strict_types=1);

/**
 * Tests for the SMS queue "stuck in sending" fix (includes/notifications.php):
 *  - smsQueueProcess() reaps rows stuck in 'sending' for > 15 minutes and
 *    processes them instead of leaving them invisible forever
 *  - rows stuck for < 15 minutes are left alone (grace period respected)
 *  - the race-safe claim (conditional UPDATE + rowCount) still works
 *
 * The gateway is disabled in tests (DEVELOPMENT_MODE), so sendMeliPayamakPattern()
 * fails gracefully WITHOUT any network call; claimed rows are re-queued or parked.
 *
 * Needs: PHP CLI + MySQL/MariaDB (see tests/README.md).
 * Run: php tests/sms-queue-reaper-test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-bootstrap.php';
require dirname(__DIR__) . '/includes/db.php';
require dirname(__DIR__) . '/includes/sms.php';
require dirname(__DIR__) . '/includes/notifications.php';

echo "SMS queue reaper tests\n";

$pdo = testDbConnect();
initializeFinancialTables();

function queueRow(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, status, attempts, last_error FROM sms_queue WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    testAssert($row !== false, "queue row {$id} exists");
    return $row === false ? [] : $row;
}

// A stale 'sending' row: simulates a worker that crashed 60 minutes ago.
$pdo->exec("INSERT INTO sms_queue (recipient_phone, pattern_body_id, status, attempts, updated_at)
            VALUES ('09120000001', 1, 'sending', 0, DATE_SUB(NOW(), INTERVAL 60 MINUTE))");
$staleId = (int) $pdo->lastInsertId();

// A fresh 'sending' row: a worker is (presumably) still working on it.
$pdo->exec("INSERT INTO sms_queue (recipient_phone, pattern_body_id, status, attempts, updated_at)
            VALUES ('09120000002', 1, 'sending', 0, DATE_SUB(NOW(), INTERVAL 5 MINUTE))");
$freshId = (int) $pdo->lastInsertId();

// A normal pending row (control: must be attempted by this run).
$pdo->exec("INSERT INTO sms_queue (recipient_phone, pattern_body_id, status, attempts)
            VALUES ('09120000003', 1, 'pending', 0)");
$pendingId = (int) $pdo->lastInsertId();

// A sent row (control: must never be touched).
$pdo->exec("INSERT INTO sms_queue (recipient_phone, pattern_body_id, status, attempts, sent_at)
            VALUES ('09120000004', 1, 'sent', 1, NOW())");
$sentId = (int) $pdo->lastInsertId();

$result = smsQueueProcess($pdo, 25);

// ─── The stale row was reaped and processed ─────────────────────────────────
$stale = queueRow($pdo, $staleId);
testAssert($stale['status'] !== 'sending', 'stale sending row is no longer stuck in sending');
testAssert($stale['status'] === 'pending', 'stale row returned to pending after failed attempt (gateway disabled)');
testAssert((int) $stale['attempts'] === 1, 'stale row was actually attempted (attempts incremented)');

// ─── The fresh row was left alone ───────────────────────────────────────────
$fresh = queueRow($pdo, $freshId);
testAssert($fresh['status'] === 'sending', 'fresh sending row (< 15 min) keeps its status');
testAssert((int) $fresh['attempts'] === 0, 'fresh sending row was not attempted');

// ─── Controls ─────────────────────────────────────────────────────────────
$pending = queueRow($pdo, $pendingId);
testAssert((int) $pending['attempts'] === 1, 'pending row was attempted');
testAssert($pending['status'] === 'pending', 'pending row re-queued after graceful failure');

$sent = queueRow($pdo, $sentId);
testAssert($sent['status'] === 'sent', 'sent row untouched');
testAssert((int) $sent['attempts'] === 1, 'sent row attempts untouched');

testAssert($result['attempted'] === 2, 'exactly the 2 eligible rows were attempted (stale + pending)');
testAssert($result['failed'] === 2, 'both attempts recorded as failed (gateway disabled, no network)');

// ─── Reaper is idempotent: a second run changes nothing stuck ───────────────
$result2 = smsQueueProcess($pdo, 25);
$staleAgain = queueRow($pdo, $staleId);
testAssert($staleAgain['status'] !== 'sending', 'second run: stale row still not stuck in sending');

exit(testSummary('sms-queue-reaper-test'));
