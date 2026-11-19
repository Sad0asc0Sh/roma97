<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

requireLogin();

$pageTitleValue = 'پیام‌های تماس | ' . e(siteName());
$topbarTitle = 'پیام‌های فرم تماس';
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

try {
    $pdo = getDb();
    ensureContactMessagesTable($pdo);

    // Mark-as-read / delete actions
    if (isPostRequest()) {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        $msgId = filter_var((string) ($_POST['id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!validateCsrfToken($csrfToken) || !is_int($msgId)) {
            setFlash('error', 'درخواست نامعتبر است.');
            redirect(url('admin/contact-messages.php'));
        }

        if ($action === 'toggle_read') {
            $stmt = $pdo->prepare('UPDATE contact_messages SET is_read = 1 - is_read WHERE id = :id');
            $stmt->execute([':id' => $msgId]);
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM contact_messages WHERE id = :id');
            $stmt->execute([':id' => $msgId]);
            recordAudit('contact.message.delete', 'contact_message', $msgId);
        } else {
            setFlash('error', 'عملیات نامعتبر است.');
            redirect(url('admin/contact-messages.php'));
        }

        redirect(url('admin/contact-messages.php'));
    }

    $rows = $pdo->query(
        'SELECT id, name, phone, email, subject, message, is_read, ip_address, created_at
         FROM contact_messages ORDER BY created_at DESC, id DESC LIMIT 200'
    )->fetchAll();

    $unreadCount = (int) $pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $rows = [];
    $unreadCount = 0;
    $errorMessage = $errorMessage ?: 'بارگذاری پیام‌ها با مشکل مواجه شد.';
}

$subjectLabels = [
    'visit' => 'درخواست بازدید حضوری',
    'register' => 'اطلاعات شهریه و ثبت‌نام',
    'consult' => 'مشاوره با روانشناس',
    'other' => 'سایر موارد',
];

require_once __DIR__ . '/header.php';
?>

<section class="admin-dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <div class="adm-page-title-row">
                <h1>پیام‌های فرم تماس</h1>
                <?php if ($unreadCount > 0): ?>
                    <span class="adm-count-pill"><?= e(persianNumber((string) $unreadCount)) ?> خوانده‌نشده</span>
                <?php endif; ?>
            </div>
            <p class="adm-page-subtitle">پیام‌های ارسالی از فرم «تماس با ما» سایت عمومی — <?= e(persianNumber((string) count($rows))) ?> پیام اخیر</p>
        </div>
    </div>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="empty-state" style="text-align:center;padding:48px 0;">
            <h2>هیچ پیامی ثبت نشده است</h2>
            <p class="muted">پیام‌های ارسالی از طریق فرم تماس سایت در اینجا نمایش داده می‌شوند.</p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>وضعیت</th>
                        <th>نام</th>
                        <th>تماس</th>
                        <th>موضوع</th>
                        <th>پیام</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $isRead = ((int) $row['is_read']) === 1;
                    $subjectLabel = $subjectLabels[$row['subject']] ?? (string) $row['subject'];
                    ?>
                    <tr<?= $isRead ? '' : ' class="adm-row-unread"' ?>>
                        <td><?= $isRead ? '<span class="badge badge-secondary">خوانده</span>' : '<span class="badge badge-success">جدید</span>' ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td style="white-space:nowrap;">
                            <a href="tel:<?= e($row['phone']) ?>" dir="ltr"><?= e(persianNumber((string) $row['phone'])) ?></a>
                            <?php if (!empty($row['email'])): ?><br><a href="mailto:<?= e($row['email']) ?>" dir="ltr" style="font-size:.8rem;"><?= e($row['email']) ?></a><?php endif; ?>
                        </td>
                        <td><?= e($subjectLabel) ?></td>
                        <td style="max-width:340px;white-space:normal;line-height:1.7;"><?= nl2br(e($row['message'])) ?></td>
                        <td style="white-space:nowrap;"><?= e(shamsiDate((string) $row['created_at'])) ?></td>
                        <td style="white-space:nowrap;">
                            <div class="adm-row-actions">
                                <form method="post" action="<?= e(url('admin/contact-messages.php')) ?>" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_read">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline"><?= $isRead ? 'علامت جدید' : 'خوانده شد' ?></button>
                                </form>
                                <form method="post" action="<?= e(url('admin/contact-messages.php')) ?>" class="inline-form" data-confirm="آیا این پیام حذف شود؟">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
