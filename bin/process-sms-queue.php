<?php
declare(strict_types=1);

/**
 * ROMA — SMS Queue Worker
 * ------------------------------------------------------------------
 * Drains the outbound SMS queue filled by the notification engine.
 *
 * Why a worker exists
 * -------------------
 * Web requests only spend a small time budget (see
 * `smsInlineTimeBudgetSeconds()`) talking to the SMS provider, so saving a
 * daily report for a 40-child classroom returns instantly instead of holding
 * the browser for 40 sequential HTTP calls. Everything left over lands in
 * `sms_queue` and is delivered here.
 *
 * Installation (cPanel → Cron Jobs), every 5 minutes:
 *   php /home/USER/public_html/bin/process-sms-queue.php
 *
 * The worker is safe to run concurrently with itself and with web requests:
 * every row is claimed with a conditional `UPDATE ... WHERE status='pending'`
 * before the network call, so no message is ever sent twice.
 *
 * Usage:
 *   php bin/process-sms-queue.php [--limit=100] [--budget=50] [--quiet]
 */

define('ROOMA_APP', true);

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    http_response_code(403);
    die('این اسکریپت فقط از طریق خط فرمان (CLI) یا Cron Job قابل اجراست.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';

/** Parse `--key=value` / `--flag` arguments. */
function queueWorkerOption(string $name, ?string $default = null): ?string
{
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if ($arg === '--' . $name) {
            return '1';
        }
        if (str_starts_with((string) $arg, '--' . $name . '=')) {
            return substr((string) $arg, strlen($name) + 3);
        }
    }

    return $default;
}

$quiet = queueWorkerOption('quiet') !== null;

function workerLog(string $message): void
{
    global $quiet;

    if ($quiet) {
        return;
    }

    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

// A cron run may take longer than a web request; a generous but finite budget
// prevents overlapping runs from piling up if the provider is slow.
$limit  = max(1, min(500, (int) queueWorkerOption('limit', '100')));
$budget = max(5, min(240, (int) queueWorkerOption('budget', '50')));

try {
    initializeNotificationTables();
    $pdo = getDb();

    $gateway = smsGatewayStatus($pdo);

    if (!$gateway['enabled']) {
        workerLog('SMS sending is disabled in settings — queue left untouched.');
        exit(0);
    }

    if (!$gateway['api_key_set']) {
        workerLog('ERROR: MeliPayamak API key is not configured. Nothing can be delivered.');
        exit(1);
    }

    $before = smsQueueStats($pdo);

    if ($before['pending'] === 0) {
        workerLog('Queue is empty — nothing to do.');
        exit(0);
    }

    workerLog("Queue has {$before['pending']} pending message(s). Processing up to {$limit} within {$budget}s...");

    $result = smsQueueProcess($pdo, $limit, $budget);

    workerLog(sprintf(
        'Done — attempted: %d, sent: %d, failed: %d, remaining: %d',
        $result['attempted'],
        $result['sent'],
        $result['failed'],
        $result['remaining']
    ));

    // Retire delivered/cancelled rows after 30 days so the queue table stays
    // small; `sms_logs` remains the permanent audit trail for reporting.
    try {
        $pruned = $pdo->exec(
            "DELETE FROM sms_queue
             WHERE status IN ('sent', 'cancelled')
               AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        if ((int) $pruned > 0) {
            workerLog('Pruned ' . (int) $pruned . ' old queue row(s).');
        }
    } catch (Throwable $pruneError) {
        error_log('sms queue prune failed: ' . $pruneError->getMessage());
    }

    // Only audit runs that actually did something, so the activity log does not
    // fill up with one empty entry every five minutes.
    if ($result['attempted'] > 0) {
        recordAudit('system.sms_queue_worker', 'system', null, $result);
    }

    exit($result['failed'] > 0 && $result['sent'] === 0 ? 1 : 0);
} catch (Throwable $exception) {
    error_log('process-sms-queue failed: ' . $exception->getMessage());
    workerLog('FATAL: ' . $exception->getMessage());
    exit(1);
}
