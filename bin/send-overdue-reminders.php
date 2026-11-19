<?php
declare(strict_types=1);

/**
 * ROMA — Overdue Tuition Reminder Automation
 * ------------------------------------------------------------------
 * Sends one reminder per overdue, undisputed invoice.
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
 *  • The default run includes earlier billing months. An explicit due date
 *    must have passed, and the family can pay through the due date itself.
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
require_once __DIR__ . '/../includes/portal_rules.php';
require_once __DIR__ . '/../includes/tuition_invoices.php';

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

    $monthOption = reminderOption('month');
    $targetMonth = $monthOption === null ? null : portalMonthYear($monthOption);
    if ($monthOption !== null && $targetMonth === null) {
        throw new InvalidArgumentException('Use a valid Persian billing month, for example --month=1405-08.');
    }
    $dryRun = reminderOption('dry-run') !== null;
    $today = date('Y-m-d');

    // Ignore rounding dust so a 500-Toman remainder never triggers an SMS.
    // Defaults to the shared settle tolerance used across every finance screen.
    $minBalance = max(tuitionSettleTolerance(), (float) (reminderOption('min') ?? tuitionSettleTolerance()));

    cliLog('Overdue tuition reminders for ' . ($targetMonth ?? 'all billing months') . ($dryRun ? ' [DRY RUN]' : ''));

    $gateway = smsGatewayStatus($pdo);
    if (!$gateway['enabled']) {
        cliLog('Note: SMS is disabled in settings — portal messages will still be created.');
    }

    $invoices = tuitionInvoiceRows($pdo, $targetMonth, $today);
    $legacyReminder = $pdo->prepare('SELECT 1 FROM tuition_reminder_log WHERE child_id = ? AND month_year = ? LIMIT 1');

    $reminderLogStmt = $pdo->prepare(
        'INSERT INTO tuition_reminder_log (child_id, parent_id, month_year) VALUES (:cid, :pid, :myear)'
    );

    $sent = 0;
    $skippedCleared = 0;
    $skippedDuplicate = 0;
    $smsQueued = 0;
    $siteNameValue = siteName();

    $lockInvoice = $pdo->prepare(
        "SELECT tp.due_date, tp.discrepancy_note, tp.expected_amount,
                CONCAT_WS(' ', c.first_name, c.last_name) AS child_name, p.id AS parent_id,
                CONCAT_WS(' ', p.first_name, p.last_name) AS parent_name
         FROM tuition_plans tp INNER JOIN children c ON c.id = tp.child_id AND c.status = 'active'
         INNER JOIN parents p ON p.id = c.parent_id AND p.status = 'active'
         WHERE tp.child_id = ? AND tp.month_year = ? FOR UPDATE"
    );
    $lockPayments = $pdo->prepare('SELECT amount FROM tuition_payments WHERE child_id = ? AND month_year = ? FOR UPDATE');

    foreach ($invoices as $row) {
        if (tuitionInvoiceState($row, $today) !== 'overdue' || $row['balance'] < $minBalance) {
            $skippedCleared++;
            continue;
        }

        $childId = (int) $row['child_id'];
        $monthYear = $row['month_year'];
        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        try {
            if (!$dryRun) {
                // Refresh the invoice and payments under lock before committing an outbox entry.
                $lockInvoice->execute([$childId, $monthYear]);
                $current = $lockInvoice->fetch();
                if ($current === false) {
                    $skippedCleared++;
                    continue;
                }
                $lockPayments->execute([$childId, $monthYear]);
                $row = array_replace($row, $current);
                $row['expected'] = (float) $current['expected_amount'];
                $row['paid'] = array_sum($lockPayments->fetchAll(PDO::FETCH_COLUMN));
                $row['balance'] = round($row['expected'] - $row['paid'], 2);
                if (tuitionInvoiceState($row, $today) !== 'overdue' || $row['balance'] < $minBalance) {
                    $skippedCleared++;
                    continue;
                }
            }

            $balance = $row['balance'];
            $monthLabel = formatShamsiMonthYear($monthYear);
            $legacyReminder->execute([$childId, $monthYear]);
            if ($legacyReminder->fetchColumn() !== false) {
                $skippedDuplicate++;
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

            $body .= '• پرداخت‌شده تا این لحظه: ' . persianNumber(number_format((float) $row['paid'])) . " تومان\n"
                . '• سررسید پرداخت: ' . shamsiDate($row['due_date']) . "\n"
                . "• مانده: {$amountFa} تومان\n\n"
                . "خواهش می‌کنیم در فرصت مناسب نسبت به تسویه اقدام فرمایید. در صورت هرگونه پرسش یا هماهنگی، ما در خدمت شما هستیم.\n\n"
                . "با آرزوی روزهای پرخنده،\nمدیریت و کادر {$siteNameValue} 💖";

            if ($dryRun) {
                cliLog("[dry-run] {$monthYear} — would remind {$parentName} about {$childName} — balance {$amountFa}");
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
                'skip_sms'       => !$gateway['enabled'],
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

            $reminderLogStmt->execute([
                ':cid'   => $childId,
                ':pid'   => $parentId,
                ':myear' => $monthYear,
            ]);
            $pdo->commit();

            $sent++;
            if ($result['sms'] === 'queued' || $result['sms'] === 'sent') {
                $smsQueued++;
            }

            cliLog("Reminded {$parentName} about {$childName} — balance {$amountFa} (sms: {$result['sms']})");
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
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
            'month'              => $targetMonth,
            'reminders_sent'     => $sent,
            'skipped_cleared'    => $skippedCleared,
            'skipped_duplicate'  => $skippedDuplicate,
        ]);
    }

    cliLog(sprintf(
        'Finished — reminders: %d, below threshold or requiring review: %d, already reminded for the invoice: %d',
        $sent,
        $skippedCleared,
        $skippedDuplicate
    ));
} catch (Throwable $e) {
    error_log('send-overdue-reminders failed: ' . $e->getMessage());
    cliLog('FATAL: ' . $e->getMessage());
    exit(1);
}
