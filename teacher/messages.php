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

requireTeacherLogin();

$teacherId = (int) ($_SESSION['teacher_id'] ?? 0);
$pdo = getDb();
$error = '';
$success = '';

// Handle Send Message
if (isPostRequest() && isset($_POST['send_message'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'توکن CSRF نامعتبر است.';
    } else {
        $recipientRaw = (string) ($_POST['recipient_id'] ?? '');
        $isBroadcast = ($recipientRaw === 'classroom');
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
                    // Fan-out: insert one row per parent in this teacher's classroom
                    $classParentsStmt = $pdo->prepare('
                        SELECT DISTINCT p.id
                        FROM parents p
                        JOIN children c ON p.id = c.parent_id
                        JOIN child_classroom cc ON c.id = cc.child_id
                        JOIN classrooms cl ON cc.classroom_id = cl.id
                        WHERE cl.teacher_id = ?
                        ORDER BY p.id
                    ');
                    $classParentsStmt->execute([$teacherId]);

                    while (($pid = $classParentsStmt->fetchColumn()) !== false) {
                        $insertStmt->execute(['teacher', $teacherId, (int) $pid, $subject, $body]);
                        $sentCount++;
                    }

                    if ($sentCount === 0) {
                        // No parents in classroom — insert a single NULL row so teacher still sees it in sent list
                        $insertStmt->execute(['teacher', $teacherId, null, $subject, $body]);
                        $sentCount = 1;
                    }

                    recordAudit('message.send', 'message', null, ['broadcast' => true, 'recipients' => $sentCount]);
                } else {
                    // Verify the specific parent belongs to this teacher's classroom
                    $verifyStmt = $pdo->prepare('
                        SELECT COUNT(*)
                        FROM child_classroom cc
                        JOIN children c ON cc.child_id = c.id
                        JOIN classrooms cl ON cc.classroom_id = cl.id
                        WHERE cl.teacher_id = ? AND c.parent_id = ?
                    ');
                    $verifyStmt->execute([$teacherId, $recipientId]);
                    if ((int) $verifyStmt->fetchColumn() === 0) {
                        throw new Exception('دریافت‌کننده نامعتبر است.');
                    }

                    $insertStmt->execute(['teacher', $teacherId, $recipientId, $subject, $body]);
                    $sentCount = 1;
                    recordAudit('message.send', 'message', (int) $pdo->lastInsertId(), ['recipient_id' => $recipientId]);
                }

                $success = $isBroadcast
                    ? 'پیام با موفقیت به ' . persianNumber((string) $sentCount) . ' والد ارسال شد.'
                    : 'پیام با موفقیت ارسال شد.';
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $error = 'ارسال پیام ناموفق بود: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Parents of children in this teacher's classroom
$parentsStmt = $pdo->prepare('
    SELECT DISTINCT p.id, p.first_name, p.last_name, p.email 
    FROM parents p
    JOIN children c ON p.id = c.parent_id
    JOIN child_classroom cc ON c.id = cc.child_id
    JOIN classrooms cl ON cc.classroom_id = cl.id
    WHERE cl.teacher_id = ?
    ORDER BY p.last_name, p.first_name
');
$parentsStmt->execute([$teacherId]);
$parents = $parentsStmt->fetchAll();

// Fetch Sent Messages by this teacher (paginated)
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_type = "teacher" AND sender_id = ?');
$countStmt->execute([$teacherId]);
$pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);

$messagesStmt = $pdo->prepare('
    SELECT m.*, p.first_name, p.last_name 
    FROM messages m 
    LEFT JOIN parents p ON m.parent_id = p.id 
    WHERE m.sender_type = "teacher" AND m.sender_id = ? 
    ORDER BY m.created_at DESC
    LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'] . '
');
$messagesStmt->execute([$teacherId]);
$sentMessages = $messagesStmt->fetchAll();

// View جزئیات پیام — looked up directly so the link works across pages.
$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $viewStmt = $pdo->prepare('
        SELECT m.*, p.first_name, p.last_name
        FROM messages m
        LEFT JOIN parents p ON m.parent_id = p.id
        WHERE m.id = ? AND m.sender_type = "teacher" AND m.sender_id = ?
        LIMIT 1
    ');
    $viewStmt->execute([$viewId, $teacherId]);
    $viewMessage = $viewStmt->fetch() ?: null;
}

$pageTitle = 'پیام‌رسانی | ' . e(siteName());
require_once __DIR__ . '/header.php';
?>

<div class="teacher-card">
    <div class="teacher-card-header">
        <h2>ارسال پیام جدید</h2>
    </div>
    <div class="teacher-card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('teacher/messages.php')) ?>" class="message-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            
            <div class="form-group">
                <label for="recipient_id">دریافت‌کننده</label>
                <select name="recipient_id" id="recipient_id" class="form-control" required>
                    <option value="classroom">همه والدین کلاس من</option>
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

<div class="teacher-card mt-4">
    <div class="teacher-card-header">
        <h2>پیام‌های ارسالی شما</h2>
    </div>
    <div class="teacher-card-body">
        <?php if (empty($sentMessages)): ?>
            <p>هنوز پیامی ارسال نشده است.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th>دریافت‌کننده</th>
                            <th>موضوع</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sentMessages as $msg): ?>
                            <tr>
                                <td><?= e(formatPersianDate($msg['created_at'])) ?></td>
                                <td><?= $msg['parent_id'] ? e($msg['first_name'] . ' ' . $msg['last_name']) : '<strong>همه والدین کلاس</strong>' ?></td>
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
                <?= renderPagination($pagination, url('teacher/messages.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewMessage): ?>
    <div class="modal-overlay">
        <div class="modal-content card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3>جزئیات پیام</h3>
                <a href="<?= e(url('teacher/messages.php')) ?>" class="close-btn">&times;</a>
            </div>
            <div class="card-body">
                <p><strong>To:</strong> <?= $viewMessage['parent_id'] ? e($viewMessage['first_name'] . ' ' . $viewMessage['last_name']) : 'همه والدین کلاس' ?></p>
                <p><strong>تاریخ:</strong> <?= e(formatPersianDate($viewMessage['created_at'])) ?></p>
                <p><strong>موضوع:</strong> <?= e($viewMessage['subject']) ?></p>
                <hr>
                <div class="message-body-content">
                    <?= nl2br(e($viewMessage['body'])) ?>
                </div>
            </div>
            <div class="card-footer">
                <a href="<?= e(url('teacher/messages.php')) ?>" class="btn btn-secondary">بستن</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
