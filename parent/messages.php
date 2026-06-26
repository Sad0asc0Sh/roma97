<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

// Force database initialization for messaging table
initializeMessagingTable();

requireParentLogin();

$parentId = (int) ($_SESSION['parent_id'] ?? 0);
$pdo = getDb();

// Mark as read if viewing a specific message
$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];

    // Mark as read first
    $markReadStmt = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND (parent_id IS NULL OR parent_id = ?)');
    $markReadStmt->execute([$viewId, $parentId]);

    // Fetch message details
    $msgStmt = $pdo->prepare('
        SELECT m.*,
               CASE
                 WHEN m.sender_type = "admin" THEN "مدیر سیستم"
                 WHEN m.sender_type = "teacher" THEN (SELECT CONCAT("معلم ", first_name, " ", last_name) FROM teachers WHERE id = m.sender_id)
               END as sender_name
        FROM messages m
        WHERE m.id = ? AND (m.parent_id IS NULL OR m.parent_id = ?)
    ');
    $msgStmt->execute([$viewId, $parentId]);
    $viewMessage = $msgStmt->fetch();
}

// Fetch all messages for this parent (paginated)
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE parent_id IS NULL OR parent_id = ?');
$countStmt->execute([$parentId]);
$pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);

$messagesStmt = $pdo->prepare('
    SELECT m.*,
           CASE
             WHEN m.sender_type = "admin" THEN "مدیر سیستم"
             WHEN m.sender_type = "teacher" THEN (SELECT CONCAT("معلم ", first_name, " ", last_name) FROM teachers WHERE id = m.sender_id)
           END as sender_name
    FROM messages m
    WHERE m.parent_id IS NULL OR m.parent_id = ?
    ORDER BY m.created_at DESC
    LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'] . '
');
$messagesStmt->execute([$parentId]);
$messages = $messagesStmt->fetchAll();

$pageTitle = 'پیام‌های من';
require_once __DIR__ . '/header.php';
?>

<div class="parent-card">
    <div class="parent-card-header d-flex justify-content-between align-items-center">
        <h2><span class="header-icon">📧</span> صندوق ورودی</h2>
        <span class="badge badge-info"><?= e(persianNumber((string) $pagination['total'])) ?> پیام</span>
  </div>

    <div class="parent-card-body">
        <?php if (empty($messages)): ?>
            <div class="empty-state">
                <div class="empty-icon">📂</div>
                <p>هنوز پیامی ندارید. بعداً دوباره بررسی کنید</p>
        </div>
        <?php else: ?>
            <div class="message-list">
                <?php foreach ($messages as $msg): ?>
                    <div class="message-item <?= !$msg['is_read'] ? 'unread' : '' ?>">
                        <div class="message-info">
                            <div class="message-header">
                                <span class="message-sender">
                                    <span class="sender-badge <?= e($msg['sender_type']) ?>"><?= e($msg['sender_name'] ?? 'کارمند') ?></span>
                            </span>
                                <span class="message-date"><?= e(formatPersianDate($msg['created_at']))?></span>
                        </div>
                            <h3 class="message-subject"><?= e($msg['subject'])?></h3>
                            <p class="message-preview">
                                <?= e(mb_substr($msg['body'], 0, 100)) ?><?= mb_strlen($msg['body']) > 100 ? '...' : '' ?>
                        </p>
                     </div>
                        <div class="message-actions">
                            <a href="?view=<?= e((string) $msg['id']) ?>" class="btn btn-primary btn-sm">
                                <?= !$msg['is_read'] ? 'خواندن پیام جدید' : 'مشاهده' ?>
                          </a>
                            <?php if (!$msg['is_read']): ?>
                                <span class="unread-dot" title="خوانده نشده"></span>
                            <?php endif; ?>
                     </div>
                 </div>
                <?php endforeach; ?>
        </div>
            <?php if ($pagination['total'] > $pagination['perPage']): ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> پیام
                </p>
                <?= renderPagination($pagination, url('parent/messages.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
 </div>
</div>

<?php if ($viewMessage): ?>
    <div class="modal-overlay">
        <div class="modal-content card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3>جزئیات پیام</h3>
                <a href="<?= e(url('parent/messages.php')) ?>" class="close-btn">&times</a>
        </div>
            <div class="card-body">
                <div class="msg-meta">
                    <p><strong>از</strong> <?= e($viewMessage['sender_name'])?></p>
                    <p><strong>تاریخ</strong> <?= e(formatPersianDate($viewMessage['created_at']))?></p>
                    <p><strong>موضوع</strong> <?= e($viewMessage['subject'])?></p>
            </div>
                <hr>
                <div class="message-full-body">
                    <?= nl2br(e($viewMessage['body'])) ?>
            </div>
        </div>
            <div class="card-footer">
                <a href="<?= e(url('parent/messages.php')) ?>" class="btn btn-secondary">بازگشت به صندوق ورودی</a>
        </div>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
