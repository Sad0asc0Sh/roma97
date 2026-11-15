<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';

// Force database initialization for messaging table
initializeMessagingTable();
initializeNotificationTables();

requireLogin();

if (!isset($_SESSION['admin_id'])) {
    redirect(url('login.php'));
}
$adminId = (int) $_SESSION['admin_id'];
$pdo = getDb();
$error = '';
$success = '';

// Handle Send Message
if (isPostRequest() && isset($_POST['send_message'])) {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'توکن CSRF نامعتبر است.';
    } else {
        $recipientRaw = (string) ($_POST['recipient_id'] ?? '');
        $isBroadcast = ($recipientRaw === 'all');
        $recipientId = $isBroadcast ? null : (int) $recipientRaw;
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            $error = 'موضوع و متن پیام الزامی است.';
        } else {
            try {
                // Resolve the recipient list up-front, then let the notification
                // engine write the portal message AND queue the SMS for each one.
                // Doing both in a single place is what keeps the parent inbox and
                // the SMS log consistent with each other.
                $recipientIds = [];

                if ($isBroadcast) {
                    $parentsListStmt = $pdo->query("SELECT id FROM parents WHERE status = 'active' ORDER BY id");
                    while (($pid = $parentsListStmt->fetchColumn()) !== false) {
                        $recipientIds[] = (int) $pid;
                    }

                    if ($recipientIds === []) {
                        $error = 'هیچ والد فعالی برای دریافت این پیام وجود ندارد.';
                    }
                } else {
                    // Validate that the chosen recipient is a real active parent so an
                    // admin can't address a suspended/deleted account.
                    $verifyStmt = $pdo->prepare(
                        'SELECT id FROM parents WHERE id = :id AND status = :active LIMIT 1'
                    );
                    $verifyStmt->execute([':id' => $recipientId, ':active' => 'active']);

                    if ($verifyStmt->fetchColumn() === false) {
                        $error = 'گیرنده انتخاب‌شده معتبر یا فعال نیست.';
                    } else {
                        $recipientIds[] = (int) $recipientId;
                    }
                }

                if ($error === '') {
                    // Shared token so all rows of one broadcast are traceable
                    // together while each recipient still de-duplicates independently.
                    $batchToken = bin2hex(random_bytes(8));
                    $notifyResults = [];

                    foreach ($recipientIds as $targetParentId) {
                        $notifyResults[] = notifyParent($targetParentId, 'announcement', [
                            'args'           => [parentDisplayNameForMessage($pdo, $targetParentId), $subject],
                            'portal_subject' => $subject,
                            'portal_body'    => $body,
                            'dedup_key'      => "announcement:{$batchToken}:{$targetParentId}",
                            'sender_type'    => 'admin',
                            'sender_id'      => $adminId,
                        ]);
                    }

                    $sentCount = count($recipientIds);

                    recordAudit('message.send', 'message', null, [
                        'broadcast'  => $isBroadcast,
                        'recipients' => $sentCount,
                    ]);

                    smsQueueFlushInline($pdo);

                    $success = $isBroadcast
                        ? 'پیام با موفقیت به ' . persianNumber((string) $sentCount) . ' والد ارسال شد.'
                        : 'پیام با موفقیت ارسال شد.';

                    $summary = summarizeNotificationResults($notifyResults);
                    if ($summary !== '') {
                        $success .= ' ' . $summary;
                    }
                }
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $error = 'ارسال پیام ناموفق بود.';
            }
        }
    }
}

// Fetch Parents for dropdown (only active ones can receive messages)
$parentsStmt = $pdo->query("SELECT id, first_name, last_name, email FROM parents WHERE status = 'active' ORDER BY last_name, first_name");
$parents = $parentsStmt->fetchAll();

// ── Parent replies inbox ──────────────────────────────────────────────────────
// Families can now answer from the portal (sender_type='parent'). Their
// messages appear here, newest first, with a distinct badge and a read flag
// independent from the outgoing messages above.
$parentReplies = [];
try {
    $parentRepliesStmt = $pdo->query("
        SELECT m.id, m.subject, m.body, m.created_at, m.is_read,
               m.parent_id, p.first_name, p.last_name, p.phone
        FROM messages m
        INNER JOIN parents p ON p.id = m.parent_id
        WHERE m.sender_type = 'parent' AND (m.recipient_admin_id IS NULL OR m.recipient_admin_id = {$adminId})
        ORDER BY m.is_read ASC, m.created_at DESC
        LIMIT 50
    ");
    $parentReplies = $parentRepliesStmt ? $parentRepliesStmt->fetchAll() : [];
} catch (Throwable $e) {
    error_log('parent replies query failed: ' . $e->getMessage());
}

$unreadReplyCount = 0;
foreach ($parentReplies as $r) {
    if ((int) $r['is_read'] === 0) {
        $unreadReplyCount++;
    }
}

// Marking a parent reply as read (POST — no accidental GET state change).
if (isPostRequest() && ($_POST['action'] ?? '') === 'mark_reply_read') {
    if (validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $replyId = filter_var($_POST['reply_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (is_int($replyId)) {
            $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = :id AND sender_type = 'parent' AND (recipient_admin_id IS NULL OR recipient_admin_id = :admin_id)")
                ->execute([':id' => $replyId, ':admin_id' => $adminId]);
            setFlash('success', 'پیام والد خوانده‌شده علامت‌گذاری شد.');
        }
    }
    redirect(url('admin/messages.php'));
}

// Fetch sent messages, grouped so one broadcast appears as ONE row with a
// recipient count and a read-progress figure — instead of N identical rows,
// which made the outbox unusable after a few announcements.
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM (
        SELECT 1 FROM messages
        WHERE sender_type = 'admin' AND sender_id = ?
        GROUP BY subject, body, created_at
     ) grouped"
);
$countStmt->execute([$adminId]);
$pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);

$messagesStmt = $pdo->prepare(
    "SELECT
        MIN(m.id) AS id,
        m.subject,
        m.body,
        m.created_at,
        COUNT(*) AS recipient_count,
        SUM(m.is_read) AS read_count,
        MIN(m.parent_id) AS sample_parent_id,
        MAX(CASE WHEN m.parent_id IS NULL THEN 1 ELSE 0 END) AS has_null_recipient
     FROM messages m
     WHERE m.sender_type = 'admin' AND m.sender_id = ?
     GROUP BY m.subject, m.body, m.created_at
     ORDER BY m.created_at DESC
     LIMIT " . $pagination['perPage'] . ' OFFSET ' . $pagination['offset']
);
$messagesStmt->execute([$adminId]);
$sentMessages = $messagesStmt->fetchAll();

// Resolve the display name for single-recipient rows in one extra query.
$singleParentIds = [];
foreach ($sentMessages as $row) {
    if ((int) $row['recipient_count'] === 1 && $row['sample_parent_id'] !== null) {
        $singleParentIds[(int) $row['sample_parent_id']] = true;
    }
}

$parentNamesById = [];
if ($singleParentIds !== []) {
    $idList = implode(',', array_map('intval', array_keys($singleParentIds)));
    $nameStmt = $pdo->query("SELECT id, first_name, last_name FROM parents WHERE id IN ({$idList})");
    foreach (($nameStmt ? $nameStmt->fetchAll() : []) as $pRow) {
        $parentNamesById[(int) $pRow['id']] = trim((string) $pRow['first_name'] . ' ' . (string) $pRow['last_name']);
    }
}

// View Message Details — looked up directly (not from the paginated list above)
// so the link still works regardless of which page it was sent from.
$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $viewStmt = $pdo->prepare(
        'SELECT m.*, p.first_name, p.last_name FROM messages m LEFT JOIN parents p ON m.parent_id = p.id'
        . " WHERE m.id = ? AND m.sender_type = 'admin' AND m.sender_id = ? LIMIT 1"
    );
    $viewStmt->execute([$viewId, $adminId]);
    $viewMessage = $viewStmt->fetch() ?: null;
}

$pageTitle = 'پیام‌رسانی | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<div class="admin-card">
    <div class="admin-card-header">
        <h2>ارسال پیام جدید</h2>
    </div>
    <div class="admin-card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('admin/messages.php')) ?>" class="message-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            
            <div class="form-group">
                <label for="recipient_id">گیرنده</label>
                <select name="recipient_id" id="recipient_id" class="form-control" required>
                    <option value="all">ارسال به همه والدین</option>
                    <?php foreach ($parents as $parent): ?>
                        <option value="<?= e((string) $parent['id']) ?>">
                            <?= e($parent['first_name'] . ' ' . $parent['last_name']) ?> (<?= e($parent['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="subject">موضوع</label>
                <input type="text" name="subject" id="subject" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="body">متن پیام</label>
                <textarea name="body" id="body" class="form-control" rows="5" required></textarea>
            </div>

            <button type="submit" name="send_message" class="btn btn-primary">ارسال پیام</button>
        </form>
    </div>
</div>

<div class="admin-card mt-4">
    <div class="admin-card-header">
        <h2>پیام‌های ارسال‌شده</h2>
    </div>
    <div class="admin-card-body">
        <?php if (empty($sentMessages)): ?>
            <p>هنوز پیامی ارسال نشده است.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th>گیرنده</th>
                            <th>موضوع</th>
                            <th>وضعیت مطالعه</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sentMessages as $msg): ?>
                            <?php
                            $recipientCount = (int) $msg['recipient_count'];
                            $readCount = (int) $msg['read_count'];
                            $isGroup = $recipientCount > 1;
                            $sampleParentId = $msg['sample_parent_id'] !== null ? (int) $msg['sample_parent_id'] : 0;
                            ?>
                            <tr>
                                <td><?= e(formatPersianDate($msg['created_at'])) ?></td>
                                <td>
                                    <?php if ($isGroup): ?>
                                        <strong>ارسال گروهی</strong>
                                        <br><small class="muted"><?= e(persianNumber((string) $recipientCount)) ?> والد</small>
                                    <?php elseif ((int) $msg['has_null_recipient'] === 1): ?>
                                        <strong>همه والدین</strong>
                                    <?php else: ?>
                                        <?= e($parentNamesById[$sampleParentId] ?? 'والد') ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($msg['subject']) ?></td>
                                <td>
                                    <?php if ($readCount >= $recipientCount): ?>
                                        <span class="badge badge-success">خوانده شد<?= $isGroup ? ' (همه)' : '' ?></span>
                                    <?php elseif ($readCount > 0): ?>
                                        <span class="badge badge-warning">
                                            <?= e(persianNumber((string) $readCount)) ?> از <?= e(persianNumber((string) $recipientCount)) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info">خوانده نشده</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?view=<?= e((string) $msg['id']) ?>" class="btn btn-sm btn-outline">مشاهده</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pagination['total'] > $pagination['perPage']): ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> پیام
                </p>
                <?= renderPagination($pagination, url('admin/messages.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($parentReplies !== []): ?>
<div class="admin-card mt-4">
    <div class="admin-card-header">
        <h2>📨 پیام‌های دریافتی از والدین <?= $unreadReplyCount > 0 ? '<span class="badge badge-warning">' . e(persianNumber((string) $unreadReplyCount)) . ' خوانده‌نشده</span>' : '' ?></h2>
    </div>
    <div class="admin-card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>والد</th>
                        <th>تماس</th>
                        <th>موضوع</th>
                        <th>وضعیت</th>
                        <th>پیام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parentReplies as $reply): ?>
                        <?php
                        $replyPhone = trim((string) ($reply['phone'] ?? ''));
                        $replyBodyPreview = mb_substr(trim((string) $reply['body']), 0, 80, 'UTF-8');
                        ?>
                        <tr style="<?= (int) $reply['is_read'] === 0 ? 'font-weight:600;' : '' ?>">
                            <td><?= e(formatPersianDate($reply['created_at'])) ?></td>
                            <td><?= e(trim($reply['first_name'] . ' ' . $reply['last_name'])) ?></td>
                            <td>
                                <?php if ($replyPhone !== ''): ?>
                                    <a href="tel:<?= e(preg_replace('/\s+/', '', $replyPhone)) ?>"><?= e($replyPhone) ?></a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $reply['subject']) ?></td>
                            <td>
                                <?php if ((int) $reply['is_read'] === 0): ?>
                                    <form method="post" action="<?= e(url('admin/messages.php')) ?>" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="mark_reply_read">
                                        <input type="hidden" name="reply_id" value="<?= (int) $reply['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">علامت‌گذاری خوانده‌شده</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-success">خوانده شد</span>
                                <?php endif; ?>
                            </td>
                            <td title="<?= e((string) $reply['body']) ?>"><?= e($replyBodyPreview) ?><?= mb_strlen((string) $reply['body'], 'UTF-8') > 80 ? '…' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($viewMessage): ?>
    <div class="modal-overlay">
        <div class="modal-content card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3>جزئیات پیام</h3>
                <a href="<?= e(url('admin/messages.php')) ?>" class="close-btn">&times;</a>
            </div>
            <div class="card-body">
                <p><strong>گیرنده:</strong> <?= $viewMessage['parent_id'] ? e($viewMessage['first_name'] . ' ' . $viewMessage['last_name']) : 'همه والدین' ?></p>
                <p><strong>تاریخ:</strong> <?= e(formatPersianDate($viewMessage['created_at'])) ?></p>
                <p><strong>موضوع:</strong> <?= e($viewMessage['subject']) ?></p>
                <hr>
                <div class="message-body-content">
                    <?= nl2br(e($viewMessage['body'])) ?>
                </div>
            </div>
            <div class="card-footer">
                <a href="<?= e(url('admin/messages.php')) ?>" class="btn btn-secondary">بستن</a>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.location.href = <?= json_encode(url('admin/messages.php')) ?>;
        }
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
