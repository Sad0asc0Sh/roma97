<?php
if (!defined('ROOMA_APP')) {
    exit;
}

$feedbackReportId = (int) $reportForFeedback['report_id'];
$reportSeen = $reportForFeedback['seen_at'] !== null;
$currentReaction = $reportForFeedback['reaction'];
?>
<form method="post" class="report-feedback" id="report-feedback-<?= $feedbackReportId ?>"
      action="<?= e(url('parent/child-detail.php?id=' . $childId . '#report-feedback-' . $feedbackReportId)) ?>"
      aria-label="مشاهده و بازخورد گزارش روزانه">
    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
    <input type="hidden" name="action" value="report_feedback">
    <input type="hidden" name="report_id" value="<?= $feedbackReportId ?>">
    <?php if ($reportSeen): ?>
        <span class="report-feedback-status">✓ مشاهده تأیید شد</span>
    <?php else: ?>
        <button type="submit" name="reaction" value="seen" class="pp-btn pp-btn-outline pp-btn-sm">تأیید مشاهده</button>
    <?php endif; ?>
    <?php foreach (DAILY_REPORT_REACTIONS as $value => $label): ?>
        <button type="submit" name="reaction" value="<?= e($value) ?>"
                class="pp-btn pp-btn-outline pp-btn-sm" aria-pressed="<?= $currentReaction === $value ? 'true' : 'false' ?>"><?= e($label) ?></button>
    <?php endforeach; ?>
</form>
