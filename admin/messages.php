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
require_once __DIR__ . '/../includes/message_follow_up.php';

requireLogin();
initializeMessageFollowUp();
initializeNotificationTables();
$adminId = (int) $_SESSION['admin_id'];
$pdo = getDb();
$error = '';
$success = '';
$recipientSelection = isPostRequest()
    ? ($_POST['recipient_id'] ?? '')
    : filter_var($_GET['parent_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$recipientSelection = is_string($recipientSelection) || is_int($recipientSelection) ? (string) $recipientSelection : '';

$canEscalate = canReviewOverdueMessages(currentAdminRole());
$replyFilters = ['active' => 'در انتظار پیگیری', 'resolved' => 'بسته‌شده'];
if ($canEscalate) {
    $replyFilters = ['active' => $replyFilters['active'], 'overdue' => 'گذشته از مهلت', 'resolved' => $replyFilters['resolved']];
}
$replyFilter = $_GET['filter'] ?? 'active';
$replyFilter = is_string($replyFilter) && isset($replyFilters[$replyFilter]) ? $replyFilter : 'active';
$inboxUrl = static fn (string $filter): string => url('admin/messages.php?filter=' . $filter . '#family-inbox');
$now = date('Y-m-d H:i:s');
$replyScope = $replyFilter === 'overdue' ? MESSAGE_FOLLOW_UP_OVERDUE : MESSAGE_INBOX_SCOPE;
$replyScopeParams = $replyFilter === 'overdue' ? [$now] : [$adminId, $adminId];
$followUpStaff = $pdo->query("SELECT id, username FROM admins WHERE role IN ('owner', 'manager', 'receptionist') ORDER BY username")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$replyAction = $_POST['action'] ?? null;
if (isPostRequest() && in_array($replyAction, ['mark_reply_read', 'save_follow_up'], true)) {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('درخواست نامعتبر است. دوباره تلاش کنید.');
        }
        $replyId = filter_var($_POST['reply_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT m.recipient_admin_id, m.follow_up_status, m.follow_up_admin_id, m.response_due_at
             FROM messages m WHERE m.id = ? AND m.sender_type = 'parent' AND " . $replyScope . ' FOR UPDATE'
        );
        $stmt->execute(array_merge([$replyId], $replyScopeParams));
        $reply = $stmt->fetch();
        if (!$reply) {
            throw new InvalidArgumentException('این پیام در فهرست قابل دسترس شما نیست.');
        }

        if ($replyAction === 'mark_reply_read') {
            $pdo->prepare('UPDATE messages SET is_read = 1 WHERE id = ?')->execute([$replyId]);
            $pdo->commit();
            setFlash('success', 'پیام خوانده‌شده علامت‌گذاری شد.');
        } else {
            if (!mayEditMessageFollowUp($reply, $adminId, $canEscalate, $now)) {
                throw new InvalidArgumentException('مسئول پیگیری می‌تواند وضعیت این پیام را تغییر دهد.');
            }
            $status = $_POST['follow_up_status'] ?? null;
            if (!is_string($status) || !isset(MESSAGE_FOLLOW_UP_LABELS[$status])) {
                throw new InvalidArgumentException('وضعیت پیگیری را انتخاب کنید.');
            }
            $staffInput = $_POST['follow_up_admin_id'] ?? '';
            $assignee = $staffInput === '' ? null : filter_var($staffInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($assignee !== null && (!is_int($assignee) || !isset($followUpStaff[$assignee]))) {
                throw new InvalidArgumentException('مسئول پیگیری معتبر نیست.');
            }
            $dueDateInput = $_POST['response_due_date'] ?? null;
            $dueTimeInput = $_POST['response_due_time'] ?? null;
            $oldDeadline = $_POST['response_due_at'] ?? null;
            if ($dueDateInput === null && $dueTimeInput === null && is_string($oldDeadline)) {
                // A follow-up form opened before the update may still send a single ISO value.
                [$dueDateInput, $dueTimeInput] = array_pad(explode('T', $oldDeadline, 2), 2, null);
            }
            $dueDate = parseIranDate($dueDateInput);
            $dueTime = is_string($dueTimeInput) ? parseIranTime($dueTimeInput) : null;
            if ($dueDate === null || $dueTime === null || iranDateTime($dueDate . ' ' . $dueTime) === null) {
                throw new InvalidArgumentException('برای مهلت پاسخ، تاریخ شمسی و ساعت ۲۴ ساعتهٔ تهران را انتخاب کنید.');
            }
            if ($status !== 'open') {
                $assignee ??= $adminId;
            }
            $dueAt = $dueDate . ' ' . $dueTime;
            $pdo->prepare('UPDATE messages SET follow_up_status = ?, follow_up_admin_id = ?, response_due_at = ? WHERE id = ?')
                ->execute([$status, $assignee, $dueAt, $replyId]);
            $pdo->commit();
            recordAudit('message.follow_up', 'message', (int) $replyId, [
                'previous_status' => $reply['follow_up_status'],
                'status' => $status,
                'assignee' => $assignee,
                'response_due_at' => $dueAt,
            ]);
            setFlash('success', 'پیگیری پیام به‌روزرسانی شد.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$exception instanceof InvalidArgumentException) {
            error_log('Message follow-up: ' . $exception->getMessage());
        }
        setFlash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'ذخیره پیگیری پیام انجام نشد.');
    }
    redirect($inboxUrl($replyFilter));
}

// Handle Send Message
if (isPostRequest() && isset($_POST['send_message'])) {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'توکن CSRF نامعتبر است.';
    } else {
        $recipientRaw = $recipientSelection;
        $isBroadcast = ($recipientRaw === 'all');
        $recipientId = filter_var($recipientRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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

$replyWhere = " FROM messages m INNER JOIN parents p ON p.id = m.parent_id
                WHERE m.sender_type = 'parent' AND " . $replyScope . match ($replyFilter) {
    'active' => " AND m.follow_up_status <> 'resolved'",
    'resolved' => " AND m.follow_up_status = 'resolved'",
    default => '',
};
$replyCountStmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(m.is_read = 0) AS unread' . $replyWhere);
$replyCountStmt->execute($replyScopeParams);
$replyCounts = $replyCountStmt->fetch();
$unreadReplyCount = (int) $replyCounts['unread'];
$replyPagination = paginate((int) $replyCounts['total'], currentPageNumber('reply_page'), 20);
$parentRepliesStmt = $pdo->prepare(
    'SELECT m.id, m.subject, m.body, m.created_at, m.is_read, m.parent_id, m.recipient_admin_id,
            m.follow_up_status, m.follow_up_admin_id, m.response_due_at, p.first_name, p.last_name, p.phone, p.status AS parent_status'
    . $replyWhere . ($replyFilter === 'overdue'
        ? ' ORDER BY m.response_due_at, m.id'
        : ' ORDER BY m.is_read, m.created_at DESC, m.id DESC')
    . ' LIMIT ' . $replyPagination['perPage'] . ' OFFSET ' . $replyPagination['offset']
);
$parentRepliesStmt->execute($replyScopeParams);
$parentReplies = $parentRepliesStmt->fetchAll();
$replySuccess = getFlash('success');
$replyError = getFlash('error');
$defaultDeadlineInput = defaultMessageResponseDeadline()->format('Y-m-d H:i:00');

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

<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <div class="adm-page-title-row">
                <h1>پیام‌رسانی و پیگیری خانواده‌ها</h1>
                <?php if ($unreadReplyCount > 0): ?>
                    <span class="adm-count-pill"><?= e(persianNumber((string) $unreadReplyCount)) ?> پیام خوانده‌نشده</span>
                <?php endif; ?>
            </div>
            <p class="adm-page-subtitle">مدیریت پاسخ‌های والدین، تخصیص مسئول پیگیری، ارسال پیام جدید و گزارش پیام‌های ارسالی</p>
        </div>
        <div class="adm-page-actions">
            <a href="#compose-message" class="btn btn-primary">+ ارسال پیام جدید</a>
        </div>
    </div>

    <nav class="adm-section-nav" aria-label="بخش‌های پیام‌رسانی">
        <a href="#family-inbox" class="adm-section-nav-link is-active">پیگیری پیام خانواده‌ها</a>
        <a href="#compose-message" class="adm-section-nav-link">ارسال پیام جدید</a>
        <a href="#sent-messages" class="adm-section-nav-link">پیام‌های ارسال‌شده</a>
    </nav>

<?php require __DIR__ . '/partials/family-message-inbox.php'; ?>

<div class="admin-card" id="compose-message">
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
                <label for="recipient_id" class="form-label">گیرنده</label>
                <select name="recipient_id" id="recipient_id" class="form-control" required>
                    <option value="">انتخاب گیرنده</option>
                    <option value="all" <?= $recipientSelection === 'all' ? 'selected' : '' ?>>ارسال به همه والدین</option>
                    <?php foreach ($parents as $parent): ?>
                        <option value="<?= e((string) $parent['id']) ?>" <?= $recipientSelection === (string) $parent['id'] ? 'selected' : '' ?>>
                            <?= e($parent['first_name'] . ' ' . $parent['last_name']) ?> (<?= e($parent['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="subject" class="form-label">موضوع</label>
                <input type="text" name="subject" id="subject" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="body" class="form-label">متن پیام</label>
                <textarea name="body" id="body" class="form-control" rows="5" required></textarea>
            </div>

            <button type="submit" name="send_message" class="btn btn-primary">ارسال پیام</button>
        </form>
    </div>
</div>

<div class="admin-card mt-4" id="sent-messages">
    <div class="admin-card-header">
        <h2>پیام‌های ارسال‌شده</h2>
    </div>
    <div class="admin-card-body">
        <?php if (empty($sentMessages)): ?>
            <p class="muted m-0">هنوز پیامی ارسال نشده است.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
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
                                <td><?= e(shamsiDbTimestamp($msg['created_at'])) ?></td>
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
</section>

<?php if ($viewMessage): ?>
    <div class="modal-overlay">
        <div class="modal-content card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3>جزئیات پیام</h3>
                <a href="<?= e(url('admin/messages.php')) ?>" class="close-btn">&times;</a>
            </div>
            <div class="card-body">
                <p><strong>گیرنده:</strong> <?= $viewMessage['parent_id'] ? e($viewMessage['first_name'] . ' ' . $viewMessage['last_name']) : 'همه والدین' ?></p>
                <p><strong>تاریخ:</strong> <?= e(shamsiDbTimestamp($viewMessage['created_at'])) ?></p>
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
