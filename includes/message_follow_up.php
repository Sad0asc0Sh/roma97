<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

const MESSAGE_INBOX_SCOPE = '(m.recipient_admin_id IS NULL OR m.recipient_admin_id = ? OR m.follow_up_admin_id = ?)';
const MESSAGE_FOLLOW_UP_OVERDUE = "m.follow_up_status <> 'resolved' AND m.response_due_at <= ?";
const MESSAGE_FOLLOW_UP_LABELS = [
    'open' => 'باز',
    'in_progress' => 'در حال پیگیری',
    'resolved' => 'بسته‌شده',
];

function canReviewOverdueMessages(string $role): bool
{
    return in_array($role, ['owner', 'manager'], true);
}

function defaultMessageResponseDeadline(): DateTimeImmutable
{
    return (new DateTimeImmutable())->modify('+24 hours');
}

function isMessageFollowUpOverdue(array $message, string $now): bool
{
    return $message['follow_up_status'] !== 'resolved'
        && $message['response_due_at'] !== null && $message['response_due_at'] <= $now;
}

function mayEditMessageFollowUp(array $message, int $adminId, bool $canEscalate, string $now): bool
{
    if ($canEscalate && isMessageFollowUpOverdue($message, $now)) {
        return true;
    }
    $responsible = $message['follow_up_admin_id'] ?? $message['recipient_admin_id'];
    return $responsible === null || (int) $responsible === $adminId;
}
