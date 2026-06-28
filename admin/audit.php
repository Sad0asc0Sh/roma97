<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

/**
 * Human-readable label for an audit action key.
 */
function auditActionLabel(string $action): string
{
    $labels = [
        'auth.login'              => 'ورود به سیستم',
        'auth.login_token'        => 'ورود با توکن یکبار مصرف',
        'auth.logout'             => 'خروج از سیستم',
        'auth.password_change'    => 'تغییر رمز عبور',
        'auth.register'           => 'ثبت‌نام حساب جدید',
        'settings.update'         => 'به‌روزرسانی تنظیمات سایت',
        'news.create'            => 'ایجاد خبر',
        'news.update'            => 'ویرایش خبر',
        'news.delete'            => 'حذف خبر',
        'page.create'            => 'ایجاد برگه',
        'page.update'            => 'ویرایش برگه',
        'page.delete'            => 'حذف برگه',
        'slide.create'           => 'ایجاد اسلاید',
        'slide.update'           => 'ویرایش اسلاید',
        'slide.delete'           => 'حذف اسلاید',
        'slide.reorder'          => 'تغییر ترتیب اسلایدها',
        'event.create'           => 'ایجاد رویداد',
        'event.update'           => 'ویرایش رویداد',
        'event.delete'           => 'حذف رویداد',
        'classroom.create'       => 'ایجاد کلاس',
        'classroom.update'       => 'ویرایش کلاس',
        'classroom.delete'       => 'حذف کلاس',
        'teacher.create'         => 'ایجاد معلم',
        'teacher.update'         => 'ویرایش معلم',
        'teacher.approve'        => 'تأیید معلم',
        'teacher.activate'       => 'فعال‌سازی معلم',
        'teacher.deactivate'     => 'غیرفعال‌سازی معلم',
        'teacher.delete'         => 'حذف معلم',
        'teacher.login_as'       => 'ورود به‌عنوان معلم',
        'salary.payment'         => 'ثبت پرداخت حقوق',
        'tuition.payment'        => 'ثبت پرداخت شهریه',
        'message.send'           => 'ارسال پیام',
        'daily_report.save'      => 'ثبت گزارش روزانه',
        'attendance.save'        => 'ثبت حضور و غیاب',
        'child.assign_classroom' => 'اختصاص کلاس به کودک',
        'child.approve'          => 'تأیید ثبت‌نام کودک',
        'child.activate'         => 'فعال‌سازی کودک',
        'child.reject'           => 'رد ثبت‌نام کودک',
        'child.deactivate'       => 'غیرفعال‌سازی کودک',
    ];

    return $labels[$action] ?? $action;
}

function auditActorLabel(array $row): string
{
    $types = ['admin' => 'مدیر', 'teacher' => 'معلم', 'parent' => 'والد', 'system' => 'سیستم'];
    $type = $types[(string) ($row['actor_type'] ?? 'system')] ?? 'سیستم';
    $name = trim((string) ($row['actor_label'] ?? ''));

    return $name !== '' ? $type . ' · ' . $name : $type;
}

$pagination = paginate(0, 1, 30);
$entries = [];
$actionFilter = (string) ($_GET['action'] ?? '');
$availableActions = [];

try {
    initializeAuditTable();
    $pdo = getDb();

    $availableActions = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')
        ->fetchAll(PDO::FETCH_COLUMN);

    $where = '';
    $params = [];
    if ($actionFilter !== '' && in_array($actionFilter, $availableActions, true)) {
        $where = ' WHERE action = :action';
        $params[':action'] = $actionFilter;
    } else {
        $actionFilter = '';
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log' . $where);
    $countStmt->execute($params);
    $pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 30);

    $listStmt = $pdo->prepare(
        'SELECT actor_type, actor_id, actor_label, action, entity_type, entity_id, details, ip_address, created_at
         FROM audit_log' . $where . '
         ORDER BY created_at DESC, id DESC
         LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset']
    );
    $listStmt->execute($params);
    $entries = $listStmt->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $errorMessage = 'گزارش فعالیت‌ها موقتاً در دسترس نیست.';
}

$pageTitle = 'گزارش فعالیت‌ها | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>گزارش فعالیت‌ها</h1>
    <p class="muted">سابقه‌ی تغییرات و رویدادهای مهم سیستم.</p>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($availableActions !== []): ?>
        <nav class="filter-nav" aria-label="فیلتر بر اساس نوع عملیات">
            <a href="<?= e(url('admin/audit.php')) ?>" class="<?= $actionFilter === '' ? 'is-active' : '' ?>">همه</a>
            <?php foreach ($availableActions as $act): ?>
                <a
                    href="<?= e(url('admin/audit.php?' . http_build_query(['action' => $act]))) ?>"
                    class="<?= $actionFilter === $act ? 'is-active' : '' ?>"
                ><?= e(auditActionLabel((string) $act)) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($entries === []): ?>
        <p class="margin-top-md">هنوز هیچ فعالیتی ثبت نشده است.</p>
    <?php else: ?>
        <div class="admin-table-wrap margin-top-md">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>زمان</th>
                        <th>کاربر</th>
                        <th>عملیات</th>
                        <th>موجودیت</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $row): ?>
                        <tr>
                            <td><?= e(shamsiDate((string) $row['created_at'])) ?> <span class="muted"><?= e(persianNumber(date('H:i', strtotime((string) $row['created_at'])))) ?></span></td>
                            <td><?= e(auditActorLabel($row)) ?></td>
                            <td><?= e(auditActionLabel((string) $row['action'])) ?></td>
                            <td>
                                <?php if (!empty($row['entity_type'])): ?>
                                    <?= e((string) $row['entity_type']) ?><?= $row['entity_id'] ? ' #' . e(persianNumber((string) $row['entity_id'])) : '' ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= e((string) ($row['ip_address'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pagination['total'] > $pagination['perPage']): ?>
            <p class="pagination-summary">
                نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> رکورد
            </p>
            <?= renderPagination($pagination, url('admin/audit.php'), $actionFilter !== '' ? ['action' => $actionFilter] : []) ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
