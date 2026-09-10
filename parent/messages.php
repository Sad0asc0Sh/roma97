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
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

// ── Parent replies to management (two-way communication) ─────────────────────
// Previously the inbox was read-only: a family with a question had to phone
// the office. Replies are stored with sender_type='parent' and surface in the
// admin inbox for the addressed admin (or every admin when unaddressed).
if (isPostRequest() && ($_POST['action'] ?? '') === 'reply') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('parent/messages.php'));
    }

    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body    = trim((string) ($_POST['body'] ?? ''));

    if ($body === '') {
        setFlash('error', 'متن پیام نمی‌تواند خالی باشد.');
        redirect(url('parent/messages.php'));
    }

    try {
        if (mb_strlen($body, 'UTF-8') > 10000) {
            $body = mb_substr($body, 0, 10000, 'UTF-8');
        }
        if (mb_strlen($subject, 'UTF-8') > 255) {
            $subject = mb_substr($subject, 0, 255, 'UTF-8');
        }
        if ($subject === '') {
            $subject = 'پیام والد';
        }

        $recipientAdminId = filter_var($_POST['recipient_admin_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $recipientAdminId = is_int($recipientAdminId) ? $recipientAdminId : null;

        $ins = $pdo->prepare(
            "INSERT INTO messages (sender_type, sender_id, recipient_admin_id, parent_id, subject, body, is_read)
             VALUES ('parent', :pid, :rid, :pid, :sub, :body, 0)"
        );
        $ins->execute([
            ':pid'  => $parentId,
            ':rid'  => $recipientAdminId,
            ':sub'  => $subject,
            ':body' => $body,
        ]);

        setFlash('success', 'پیام شما برای مدیریت ارسال شد. به‌زودی پاسخ می‌دهیم. 🌸');
    } catch (Throwable $e) {
        error_log('parent reply failed: ' . $e->getMessage());
        setFlash('error', 'ارسال پیام ناموفق بود. لطفاً دوباره تلاش کنید.');
    }

    redirect(url('parent/messages.php'));
}

// ── Mark as read if viewing a specific message ───────────────────────────────
$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];

    // Only ever flip the read flag on a row that belongs to THIS parent.
    // Legacy broadcast rows (parent_id IS NULL) are shared by every family, so
    // marking those read would wipe the "unread" badge for all of them at once.
    $markReadStmt = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND parent_id = ?');
    $markReadStmt->execute([$viewId, $parentId]);

    // Fetch message details
    $msgStmt = $pdo->prepare("
        SELECT m.*,
               CASE
                 WHEN m.sender_type = 'admin' THEN 'مدیر سیستم'
                 WHEN m.sender_type = 'teacher' THEN (SELECT CONCAT('معلم ', first_name, ' ', last_name) FROM teachers WHERE id = m.sender_id)
               END as sender_name
        FROM messages m
        WHERE m.id = ? AND (m.parent_id IS NULL OR m.parent_id = ?)
    ");
    $msgStmt->execute([$viewId, $parentId]);
    $viewMessage = $msgStmt->fetch();
}

// ── Fetch all messages for this parent (paginated) ───────────────────────────
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE parent_id IS NULL OR parent_id = ?');
$countStmt->execute([$parentId]);
$pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);

$messagesStmt = $pdo->prepare("
    SELECT m.*,
           CASE
             WHEN m.sender_type = 'admin' THEN 'مدیر سیستم'
             WHEN m.sender_type = 'teacher' THEN (SELECT CONCAT('معلم ', first_name, ' ', last_name) FROM teachers WHERE id = m.sender_id)
             WHEN m.sender_type = 'parent' THEN 'شما'
           END as sender_name
    FROM messages m
    WHERE m.parent_id IS NULL OR m.parent_id = ?
    ORDER BY m.created_at DESC
    LIMIT " . $pagination['perPage'] . " OFFSET " . $pagination['offset'] . "
");
$messagesStmt->execute([$parentId]);
$messages = $messagesStmt->fetchAll();

// ── Composer target list: admins a parent may address ────────────────────────
$replyAdminOptions = [];
try {
    $adminOptionsStmt = $pdo->query(
        "SELECT id, username FROM admins
         ORDER BY (role = 'owner') DESC, id ASC
         LIMIT 25"
    );
    $replyAdminOptions = $adminOptionsStmt ? $adminOptionsStmt->fetchAll() : [];
} catch (Throwable $e) {
    error_log('parent reply admin options failed: ' . $e->getMessage());
}

$pageTitle = 'پیام‌های من';
require_once __DIR__ . '/header.php';
?>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<div class="parent-card">
    <div class="parent-card-header d-flex justify-content-between align-items-center">
        <h2><span class="header-icon"><svg width="20" height="20" aria-hidden="true" style="vertical-align:middle;"><use href="<?= e(asset('assets/img/icons.svg#icon-message-square')) ?>"/></svg></span> صندوق ورودی</h2>
        <span class="badge badge-info"><?= e(persianNumber((string) $pagination['total'])) ?> پیام</span>
  </div>

    <div class="parent-card-body">
        <?php if (empty($messages)): ?>
            <div class="empty-state">
                <div class="empty-icon"><svg width="32" height="32" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-file-text')) ?>"/></svg></div>
                <p>هنوز پیامی ندارید. بعداً دوباره بررسی کنید</p>
        </div>
        <?php else: ?>
            <div class="message-list">
                <?php foreach ($messages as $msg): ?>
                    <?php $isOwn = (string) $msg['sender_type'] === 'parent'; ?>
                    <div class="message-item <?= (!$msg['is_read'] && !$isOwn) ? 'unread' : '' ?>">
                        <div class="message-info">
                            <div class="message-header">
                                <span class="message-sender">
                                    <span class="sender-badge <?= e($msg['sender_type']) ?>"><?= e($msg['sender_name'] ?? 'کارمند') ?></span>
                            </span>
                                <span class="message-date"><?= e(formatPersianDate($msg['created_at']))?></span>
                        </div>
                            <h3 class="message-subject"><?= e($msg['subject'])?></h3>
                            <p class="message-preview">
                                <?= e(mb_substr($msg['body'], 0, 100, 'UTF-8')) ?><?= mb_strlen($msg['body'], 'UTF-8') > 100 ? '...' : '' ?>
                        </p>
                     </div>
                        <div class="message-actions">
                            <?php if (!$isOwn): ?>
                                <a href="?view=<?= e((string) $msg['id']) ?>" class="btn btn-primary btn-sm">
                                    <?= !$msg['is_read'] ? 'خواندن پیام جدید' : 'مشاهده' ?>
                              </a>
                                <?php if (!$msg['is_read']): ?>
                                    <span class="unread-dot" title="خوانده نشده"></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-success">ارسال شد</span>
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

<!-- Write to management -->
<div class="parent-card" style="margin-top:20px;">
    <div class="parent-card-header">
        <h2><span class="header-icon"><svg width="20" height="20" aria-hidden="true" style="vertical-align:middle;"><use href="<?= e(asset('assets/img/icons.svg#icon-message-square')) ?>"/></svg></span> ارسال پیام به مدیریت</h2>
  </div>
    <div class="parent-card-body">
        <form method="post" action="<?= e(url('parent/messages.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="reply">

            <?php if ($replyAdminOptions !== []): ?>
                <div class="form-group">
                    <label for="recipient_admin_id">گیرنده</label>
                    <select name="recipient_admin_id" id="recipient_admin_id" style="width:100%;padding:10px 12px;border:1px solid var(--border,#ddd);border-radius:10px;font-family:inherit;">
                        <option value="">همه مدیران</option>
                        <?php foreach ($replyAdminOptions as $adminOpt): ?>
                            <option value="<?= e((string) $adminOpt['id']) ?>"><?= e((string) $adminOpt['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="reply_subject">موضوع</label>
                <input type="text" name="subject" id="reply_subject" maxlength="255" placeholder="مثلاً: هماهنگی تولد، سؤال درباره شهریه ...">
            </div>

            <div class="form-group">
                <label for="reply_body">متن پیام</label>
                <textarea name="body" id="reply_body" rows="5" required placeholder="پیام شما برای مدیریت مهدکودک..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary">ارسال پیام</button>
        </form>
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
