<?php
declare(strict_types=1);

/**
 * ROMA — Overdue Tuition Reminder Automation
 * ------------------------------------------------------------------
 * Sends one friendly tuition reminder per unpaid child, per tuition month.
 *
 * Installation (cPanel → Cron Jobs), once a day:
 *   php /home/USER/public_html/bin/send-overdue-reminders.php
 *
 * Design notes
 * ------------
 *  • Anti-spam is now keyed on the *tuition month*, not on a rolling 7-day
 *    window. The old window let a family receive four reminders for the same
 *    unpaid month, and — worse — it silently suppressed the reminder for a
 *    *new* month if one had been sent for the previous month days earlier.
 *    The dedup key `tuition_reminder:{child}:{month}` is exact.
 *  • Balances come from a single bulk query (`activeChildrenTuitionBreakdown`)
 *    instead of three queries per child, so a 300-child daycare costs one
 *    round-trip rather than ~900.
 *  • Delivery goes through `notifyParent()`, so the portal message, the parent's
 *    opt-out preference, the gateway readiness check and the SMS queue all
 *    behave exactly as they do in the admin panel.
 *
 * Usage:
 *   php bin/send-overdue-reminders.php [--month=1403-08] [--dry-run] [--min=1000]
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

/** Parse `--key=value` / `--flag` CLI arguments. */
function reminderOption(string $name, ?string $default = null): ?string
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

function cliLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
}

try {
    initializeFinancialTables();
    initializeNotificationTables();
    $pdo = getDb();

    // ── Target month (defaults to the current Jalali month) ──────────────
    $monthYear = trim((string) reminderOption('month', ''));

    if ($monthYear === '' || preg_match('/^\d{4}-\d{2}$/', $monthYear) !== 1) {
        [$jy, $jm] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
        $monthYear = sprintf('%04d-%02d', $jy, $jm);
    }

    $monthLabel = formatShamsiMonthYear($monthYear);
    $dryRun = reminderOption('dry-run') !== null;

    // Ignore rounding dust so a 500-Toman remainder never triggers an SMS.
    $minBalance = max(0.0, (float) reminderOption('min', '1000'));

    cliLog("Tuition reminders for {$monthLabel} ({$monthYear})" . ($dryRun ? ' [DRY RUN]' : ''));

    $gateway = smsGatewayStatus($pdo);
    if (!$gateway['enabled']) {
        cliLog('Note: SMS is disabled in settings — portal messages will still be created.');
    }

    // ── Single bulk query for every active child's financial position ────
    $breakdowns = activeChildrenTuitionBreakdown($pdo, $monthYear);

    if ($breakdowns === []) {
        cliLog('No active children found. Nothing to do.');
        exit(0);
    }

    cliLog('Evaluating ' . count($breakdowns) . ' active child record(s)...');

    $reminderLogStmt = $pdo->prepare(
        'INSERT INTO tuition_reminder_log (child_id, parent_id, month_year) VALUES (:cid, :pid, :myear)'
    );

    $sent = 0;
    $skippedPaid = 0;
    $skippedDuplicate = 0;
    $smsQueued = 0;
    $siteNameValue = siteName();

    foreach ($breakdowns as $childId => $row) {
        $balance = (float) $row['balance'];

        if ($balance < $minBalance) {
            $skippedPaid++;
            continue;
        }

        $parentId   = (int) $row['parent_id'];
        $childName  = (string) $row['child_name'];
        $parentName = (string) $row['parent_name'];
        $amountFa   = persianNumber(number_format($balance));

        $subject = "یادآوری دوستانه شهریه ماه {$monthLabel} — {$childName} 🌸";

        $body = "مامان و بابای مهربون «{$childName}» عزیز؛\n\n"
            . "سلام و درود پر از مهر! 🌟\n"
            . "ممنونیم که همراه فرشته کوچولومون در {$siteNameValue} هستید.\n\n"
            . "— وضعیت شهریه ماه {$monthLabel} —\n"
            . '• مبلغ ماه: ' . persianNumber(number_format((float) $row['expected'])) . " تومان\n";

        if ((float) $row['sms_fee'] > 0) {
            $body .= '  (شهریه ' . persianNumber(number_format((float) $row['base']))
                . ' + بسته پیامک تفصیلی ' . persianNumber(number_format((float) $row['sms_fee'])) . " تومان)\n";
        }

        $body .= '• پرداخت‌شده تا این لحظه: ' . persianNumber(number_format((float) $row['paid'])) . " تومان\n"
            . "• مانده: {$amountFa} تومان\n\n"
            . "خواهش می‌کنیم در فرصت مناسب نسبت به تسویه اقدام فرمایید. در صورت هرگونه پرسش یا هماهنگی، ما در خدمت شما هستیم.\n\n"
            . "با آرزوی روزهای پرخنده،\nمدیریت و کادر {$siteNameValue} 💖";

        if ($dryRun) {
            cliLog("[dry-run] would remind {$parentName} about {$childName} — balance {$amountFa}");
            $sent++;
            continue;
        }

        // One reminder per child per tuition month, enforced in the database.
        $result = notifyParent($parentId, 'tuition_reminder', [
            'args'           => [$parentName, $childName, $monthLabel, $amountFa],
            'portal_subject' => $subject,
            'portal_body'    => $body,
            'dedup_key'      => "tuition_reminder:{$childId}:{$monthYear}",
            'sender_type'    => 'admin',
            'sender_id'      => notificationSystemAdminId($pdo),
            'priority'       => 6, // Lower priority than OTP / same-day care alerts.
        ]);

        if ($result['sms'] === 'duplicate') {
            $skippedDuplicate++;
            continue;
        }

        if (!$result['portal'] && $result['sms'] !== 'queued' && $result['sms'] !== 'sent') {
            cliLog("Skipped {$childName}: " . ($result['reason'] !== '' ? $result['reason'] : 'no channel available'));
            continue;
        }

        // Historical reporting trail (kept for backwards compatibility with the
        // existing reminder history views).
        try {
            $reminderLogStmt->execute([
                ':cid'   => $childId,
                ':pid'   => $parentId,
                ':myear' => $monthYear,
            ]);
        } catch (Throwable $logError) {
            error_log('tuition_reminder_log insert failed: ' . $logError->getMessage());
        }

        $sent++;
        if ($result['sms'] === 'queued' || $result['sms'] === 'sent') {
            $smsQueued++;
        }

        cliLog("Reminded {$parentName} about {$childName} — balance {$amountFa} (sms: {$result['sms']})");
    }

    // Deliver what we queued right now instead of waiting for the next worker run.
    if (!$dryRun && $smsQueued > 0) {
        $flush = smsQueueProcess($pdo, 200, 120);
        cliLog(sprintf(
            'SMS queue flushed — sent: %d, failed: %d, remaining: %d',
            $flush['sent'],
            $flush['failed'],
            $flush['remaining']
        ));
    }

    if (!$dryRun) {
        recordAudit('system.tuition_reminders_cron', 'system', null, [
            'month'              => $monthYear,
            'reminders_sent'     => $sent,
            'skipped_settled'    => $skippedPaid,
            'skipped_duplicate'  => $skippedDuplicate,
        ]);
    }

    cliLog(sprintf(
        'Finished — reminders: %d, already settled: %d, already reminded this month: %d',
        $sent,
        $skippedPaid,
        $skippedDuplicate
    ));
} catch (Throwable $e) {
    error_log('send-overdue-reminders failed: ' . $e->getMessage());
    cliLog('FATAL: ' . $e->getMessage());
    exit(1);
}
