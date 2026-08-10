<?php
declare(strict_types=1);

/**
 * ROMA — Overdue Tuition Automatic Reminder Script
 * Executable via CLI: php bin/send-overdue-reminders.php
 * Executable via cPanel Cron Job daily.
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

function cliLog(string $msg): void
{
    global $isCli;
    $time = date('Y-m-d H:i:s');
    if ($isCli) {
        echo "[{$time}] {$msg}\n";
    } else {
        echo htmlspecialchars("[{$time}] {$msg}", ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
}

try {
    initializeFinancialTables();
    $pdo = getDb();

    // 1. Determine current Shamsi Month-Year
    [$currentJy, $currentJm] = gregorianToJalali(
        (int) date('Y'),
        (int) date('n'),
        (int) date('j')
    );
    $currentMonthYear = sprintf('%04d-%02d', $currentJy, $currentJm);
    $monthLabel = formatShamsiMonthYear($currentMonthYear);

    cliLog("Starting overdue tuition reminder check for month {$monthLabel} ({$currentMonthYear})...");

    // 2. Resolve admin sender_id (Owner admin or first admin)
    $adminStmt = $pdo->query("SELECT id FROM admins ORDER BY id ASC LIMIT 1");
    $adminId = (int) ($adminStmt ? $adminStmt->fetchColumn() : 1);
    if ($adminId <= 0) {
        $adminId = 1;
    }

    // 3. Find active children with overdue tuition (> 0)
    $childrenStmt = $pdo->query("
        SELECT c.id, c.parent_id, c.first_name, c.last_name, p.first_name AS p_first, p.last_name AS p_last
        FROM children c
        INNER JOIN parents p ON p.id = c.parent_id
        WHERE c.status = 'active'
        ORDER BY c.id
    ");
    $activeChildren = $childrenStmt ? $childrenStmt->fetchAll() : [];

    $remindersSent = 0;
    $checkLogStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tuition_reminder_log
         WHERE child_id = :cid AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    $insMsgStmt = $pdo->prepare(
        "INSERT INTO messages (sender_type, sender_id, parent_id, subject, body, is_read)
         VALUES ('admin', :sid, :pid, :sub, :body, 0)"
    );

    $insLogStmt = $pdo->prepare(
        "INSERT INTO tuition_reminder_log (child_id, parent_id, month_year)
         VALUES (:cid, :pid, :myear)"
    );

    foreach ($activeChildren as $child) {
        $cid = (int) $child['id'];
        $pid = (int) $child['parent_id'];
        $childName = trim($child['first_name'] . ' ' . $child['last_name']);
        $parentName = trim($child['p_first'] . ' ' . $child['p_last']);

        $balance = childOutstandingBalance($pdo, $cid, $currentMonthYear);

        if ($balance <= 0) {
            continue; // Fully paid or overpaid
        }

        // Check if reminder sent in last 7 days
        $checkLogStmt->execute([':cid' => $cid]);
        $recentlySent = (int) $checkLogStmt->fetchColumn() > 0;

        if ($recentlySent) {
            cliLog("Skipping {$childName} (reminder sent in last 7 days).");
            continue;
        }

        $formattedBalance = persianNumber(number_format($balance));
        $subject = "یادآوری پرداخت شهریه ماه {$monthLabel} - {$childName}";
        $body = "ولایت‌محترم جناب آقای/سرکار خانم {$parentName}؛\n\n"
            . "با سلام و احترام،\n"
            . "به استحضار می‌رساند شهریه مربوط به فرزند عزیزتان «{$childName}» برای ماه {$monthLabel} به مبلغ {$formattedBalance} تومان هنوز تسویه نگردیده است.\n"
            . "خواشمند است در اسرع وقت نسبت به تسویه یا ثبت پرداخت اقدام فرمایید.\n\n"
            . "با تشکر،\nمدیریت مهدکودک " . siteName();

        $insMsgStmt->execute([
            ':sid' => $adminId,
            ':pid' => $pid,
            ':sub' => $subject,
            ':body' => $body,
        ]);

        $insLogStmt->execute([
            ':cid' => $cid,
            ':pid' => $pid,
            ':myear' => $currentMonthYear,
        ]);

        $remindersSent++;
        cliLog("Reminder sent to {$parentName} for {$childName} (Balance: {$formattedBalance} Toman).");
    }

    recordAudit('system.tuition_reminders_cron', 'system', null, [
        'month' => $currentMonthYear,
        'reminders_sent' => $remindersSent,
    ]);

    cliLog("Finished processing. Total reminders sent: {$remindersSent}");

} catch (Throwable $e) {
    error_log($e->getMessage());
    cliLog("Error executing reminder script: " . $e->getMessage());
    exit(1);
}
