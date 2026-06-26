<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

// Force database initialization for messaging table
initializeMessagingTable();

requireLogin();

if (!isset($_SESSION['admin_id'])) {
    redirect(url('admin/login.php'));
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
                $insertStmt = $pdo->prepare(
                    'INSERT INTO messages (sender_type, sender_id, parent_id, subject, body) VALUES (?, ?, ?, ?, ?)'
                );

                $sentCount = 0;

                if ($isBroadcast) {
                    // Fan-out: insert one row per parent so each has independent read state
                    $parentsListStmt = $pdo->query('SELECT id FROM parents ORDER BY id');
                    while (($pid = $parentsListStmt->fetchColumn()) !== false) {
                        $insertStmt->execute(['admin', $adminId, (int) $pid, $subject, $body]);
                        $sentCount++;
                    }

                    if ($sentCount === 0) {
                        // No parents exist — insert a single NULL row so the admin still sees it in sent list
                        $insertStmt->execute(['admin', $adminId, null, $subject, $body]);
                        $sentCount = 1;
                    }

                    recordAudit('message.send', 'message', null, ['broadcast' => true, 'recipients' => $sentCount]);
                } else {
                    $insertStmt->execute(['admin', $adminId, $recipientId, $subject, $body]);
                    $sentCount = 1;
                    recordAudit('message.send', 'message', (int) $pdo->lastInsertId(), ['recipient_id' => $recipientId]);
                }

                $success = $isBroadcast
                    ? 'پیام با موفقیت به ' . persianNumber((string) $sentCount) . ' والد ارسال شد.'
                    : 'پیام با موفقیت ارسال شد.';
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $error = 'ارسال پیام ناموفق بود.';
            }
        }
    }
}

// Fetch Parents for dropdown
$parentsStmt = $pdo->query('SELECT id, first_name, last_name, email FROM parents ORDER BY last_name, first_name');
$parents = $parentsStmt->fetchAll();

// Fetch Sent Messages (paginated)
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_type = "admin" AND sender_id = ?');
$countStmt->execute([$adminId]);
$pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);

$messagesStmt = $pdo->prepare(
    'SELECT m.*, p.first_name, p.last_name FROM messages m LEFT JOIN parents p ON m.parent_id = p.id'
    . ' WHERE m.sender_type = "admin" AND m.sender_id = ? ORDER BY m.created_at DESC'
    . ' LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset']
);
$messagesStmt->execute([$adminId]);
$sentMessages = $messagesStmt->fetchAll();

// View Message Details — looked up directly (not from the paginated list above)
// so the link still works regardless of which page it was sent from.
$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $viewStmt = $pdo->prepare(
        'SELECT m.*, p.first_name, p.last_name FROM messages m LEFT JOIN parents p ON m.parent_id = p.id'
        . ' WHERE m.id = ? AND m.sender_type = "admin" AND m.sender_id = ? LIMIT 1'
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
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sentMessages as $msg): ?>
                            <tr>
                                <td><?= e(formatPersianDate($msg['created_at'])) ?></td>
                                <td><?= $msg['parent_id'] ? e($msg['first_name'] . ' ' . $msg['last_name']) : '<strong>همه والدین</strong>' ?></td>
                                <td><?= e($msg['subject']) ?></td>
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

<?php if ($viewMessage): ?>
    <div class="modal-overlay">
        <div class="modal-content card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3>جزئیات پیام</h3>
                <a href="<?= e(url('admin/messages.php')) ?>" class="close-btn">&times;</a>
            </div>
            <div class="card-body">
                <p><strong>To:</strong> <?= $viewMessage['parent_id'] ? e($viewMessage['first_name'] . ' ' . $viewMessage['last_name']) : 'All Parents' ?></p>
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
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
