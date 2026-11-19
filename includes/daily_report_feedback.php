<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

const DAILY_REPORT_REACTIONS = ['helpful' => 'مفید بود', 'more_details' => 'توضیح بیشتری می‌خواهم'];

function ensureDailyReportFeedbackTable(PDO $pdo): void
{
    static $ready = false;
    if (!$ready) {
        ensureSqlMigrationTables($pdo, '016_daily_report_feedback.sql', ['daily_report_feedback']);
        $ready = true;
    }
}

/** The caller has already authenticated the parent. A report must still belong to their child. */
function saveDailyReportFeedback(PDO $pdo, int $parentId, int $childId, int $reportId, ?string $reaction): void
{
    $pdo->beginTransaction();
    try {
        $report = $pdo->prepare(
            'SELECT dr.id FROM daily_reports dr INNER JOIN children c ON c.id = dr.child_id
             WHERE dr.id = ? AND dr.child_id = ? AND c.parent_id = ? AND dr.report_date <= ? FOR UPDATE'
        );
        $report->execute([$reportId, $childId, $parentId, date('Y-m-d')]);
        if ($report->fetchColumn() === false) {
            throw new InvalidArgumentException('این گزارش در دسترس نیست.');
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO daily_report_feedback (report_id, parent_id, seen_at, reaction, reacted_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                reacted_at = IF(VALUES(reaction) IS NULL OR reaction <=> VALUES(reaction), reacted_at, VALUES(reacted_at)),
                reaction = COALESCE(VALUES(reaction), reaction)'
        );
        $stmt->execute([$reportId, $parentId, $now, $reaction, $reaction === null ? null : $now]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
