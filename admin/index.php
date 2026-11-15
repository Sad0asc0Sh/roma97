<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';
require_once __DIR__ . '/../includes/portal_ui.php';
requireLogin();

$can = static fn(string $page): bool => isPageAllowedForRole('admin/' . $page . '.php', currentAdminRole());
$metrics = [];
$tasks = [];
$recentRegistrations = [];
$upcomingEvents = [];
$errorMessage = getFlash('error');
$successMessage = getFlash('success');
$today = date('Y-m-d');
try {
    $pdo = getDb();
    if ($can('children')) {
        $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM children WHERE status = 'active'")->fetchColumn();
        $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM children WHERE status = 'pending'")->fetchColumn();
        $metrics[] = ['کودکان فعال', persianNumber((string) $activeCount), 'مشاهده پرونده‌ها', 'children.php?status=active'];
        $tasks[] = [$pendingCount, 'ثبت‌نام‌های منتظر تأیید', 'بررسی اطلاعات و وضعیت پذیرش کودکان', 'children.php?status=pending'];
        $recentRegistrations = $pdo->query("SELECT id, first_name, last_name, status, created_at FROM children ORDER BY created_at DESC, id DESC LIMIT 5")->fetchAll();
    }
    if ($can('attendance')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a INNER JOIN children c ON c.id = a.child_id WHERE c.status = 'active' AND a.attendance_date = ?");
        $stmt->execute([$today]);
        $recorded = (int) $stmt->fetchColumn();
        $missing = max(0, $activeCount - $recorded);
        $metrics[] = ['حضور ثبت‌شده امروز', persianNumber((string) $recorded) . ' / ' . persianNumber((string) $activeCount), 'تکمیل دفتر حضور', 'attendance.php'];
        $tasks[] = [$missing, 'حضورهای ثبت‌نشده امروز', 'وضعیت هر کودک را پس از مشاهده ثبت کنید', 'attendance.php'];
    }
    if ($can('tuition')) {
        [$jy, $jm] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
        $month = sprintf('%04d-%02d', $jy, $jm);
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM tuition_payments WHERE month_year = ?');
        $stmt->execute([$month]);
        $metrics[] = ['دریافتی شهریه این ماه', persianNumber(number_format((float) $stmt->fetchColumn())), 'تومان · ' . formatShamsiMonthYear($month), 'tuition.php'];
    }
    if ($can('parents')) {
        $pendingParents = (int) $pdo->query("SELECT COUNT(*) FROM parents WHERE status = 'pending'")->fetchColumn();
        $tasks[] = [$pendingParents, 'حساب‌های والدین منتظر تأیید', 'بررسی حساب و اطلاعات تماس خانواده‌ها', 'parents.php?status=pending'];
    }
    if ($can('messages')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_type = 'parent' AND is_read = 0 AND (recipient_admin_id IS NULL OR recipient_admin_id = ?)");
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $replyCount = (int) $stmt->fetchColumn();
        $metrics[] = ['پیام تازه از خانواده‌ها', persianNumber((string) $replyCount), 'مشاهده صندوق پیام‌ها', 'messages.php'];
        $tasks[] = [$replyCount, 'پیام‌های خوانده‌نشده خانواده‌ها', 'پاسخ‌گویی و پیگیری درخواست والدین', 'messages.php'];
    }
    if ($can('events')) {
        $stmt = $pdo->prepare("SELECT title, event_date FROM events WHERE status = 'scheduled' AND event_date >= ? ORDER BY event_date, id LIMIT 4");
        $stmt->execute([$today]);
        $upcomingEvents = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('admin dashboard: ' . $e->getMessage());
    $errorMessage = 'بخشی از اطلاعات بارگذاری نشد. برای دریافت وضعیت به‌روز، صفحه را دوباره باز کنید.';
}
$tasks = array_values(array_filter($tasks, static fn(array $task): bool => $task[0] > 0));
$quickLinks = [
    ['attendance', 'ثبت حضور امروز'], ['add-child', 'ثبت‌نام کودک'],
    ['tuition', 'ثبت پرداخت شهریه'], ['messages', 'پیام به خانواده‌ها'],
    ['classrooms', 'مدیریت کلاس‌ها'], ['teachers', 'مربیان مهد'],
    ['expenses', 'ثبت هزینه'], ['reports', 'گزارش مالی'],
    ['salary', 'حقوق مربیان'], ['events', 'تقویم مهد'],
];
$quickLinks = array_values(array_filter($quickLinks, static fn(array $link): bool => $can($link[0])));
$pageTitle = 'امروز در مهد | ' . siteName();
require __DIR__ . '/header.php';
?>
<?php if ($errorMessage): ?><div class="alert" role="alert"><?= e($errorMessage) ?></div><?php endif; ?>
<?php if ($successMessage): ?><div class="notice" role="status"><?= e($successMessage) ?></div><?php endif; ?>
<?php portalWelcome(
    'دفتر مدیریت · ' . siteName(),
    'یک روز خوب، از اینجا شروع می‌شود.',
    'وضعیت امروز مهد و کارهایی که به توجه شما نیاز دارند، در یک نگاه.',
    $can('attendance') ? [['ثبت حضور امروز', 'admin/attendance.php'], ['ثبت‌نام کودک', 'admin/add-child.php']] : [['ثبت پرداخت شهریه', 'admin/tuition.php'], ['گزارش مالی', 'admin/reports.php']]
); ?>
<section class="portal-metrics" aria-label="وضعیت مهد">
    <?php foreach ($metrics as [$label, $value, $hint, $path]): ?>
        <a class="portal-metric" href="<?= e(url('admin/' . $path)) ?>">
            <span><?= e($label) ?></span><strong><?= e($value) ?></strong><small><?= e($hint) ?> ←</small>
        </a>
    <?php endforeach; ?>
</section>
<div class="portal-columns">
    <section class="portal-panel">
        <div class="portal-section-heading"><h2>نیازمند پیگیری</h2><span class="badge badge-warning"><?= e(persianNumber((string) count($tasks))) ?> مورد</span></div>
        <?php if ($tasks === []): ?>
            <div class="portal-empty"><?= $errorMessage ? 'اطلاعات پیگیری فعلاً در دسترس نیست.' : ($can('children') ? 'در بخش‌های قابل دسترسی شما، موردی برای پیگیری باقی نمانده است.' : 'برای ثبت دریافت‌ها، هزینه‌ها و بررسی حساب‌ها از دسترسی‌های سریع استفاده کنید.') ?></div>
        <?php endif; ?>
        <?php foreach ($tasks as [$count, $title, $hint, $path]): ?>
            <a class="portal-task" href="<?= e(url('admin/' . $path)) ?>"><span class="portal-task-count"><?= e(persianNumber((string) $count)) ?></span><span class="portal-task-copy"><strong><?= e($title) ?></strong><span><?= e($hint) ?></span></span><span class="portal-task-arrow" aria-hidden="true">←</span></a>
        <?php endforeach; ?>
    </section>
    <section class="portal-panel">
        <div class="portal-section-heading"><h2>دسترسی‌های سریع</h2></div>
        <div class="portal-shortcuts">
            <?php foreach ($quickLinks as [$page, $label]): ?><a href="<?= e(url('admin/' . $page . '.php')) ?>"><?= e($label) ?> <span aria-hidden="true">←</span></a><?php endforeach; ?>
        </div>
    </section>
</div>
<div class="portal-columns">
    <?php if ($can('children')): ?>
    <section class="portal-panel">
        <div class="portal-section-heading"><h2>تازه به جمع ما پیوسته‌اند</h2><a href="<?= e(url('admin/children.php')) ?>">همه کودکان ←</a></div>
        <?php if (!$recentRegistrations): ?><p class="portal-empty">هنوز کودکی ثبت‌نام نشده است. از «ثبت‌نام کودک» شروع کنید.</p><?php endif; ?>
        <?php foreach ($recentRegistrations as $child): ?>
            <div class="portal-row"><span class="portal-row-avatar"><?= e(userInitial($child['first_name'])) ?></span><div><a href="<?= e(url('admin/child-detail.php?id=' . (int) $child['id'])) ?>"><?= e($child['first_name'] . ' ' . $child['last_name']) ?></a><small><?= e(shamsiDate($child['created_at'])) ?></small></div><span class="badge <?= $child['status'] === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= e(parentChildEnrollmentLabel($child['status'])) ?></span></div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php if ($can('events')): ?>
    <section class="portal-panel">
        <div class="portal-section-heading"><h2>روزهای پیش رو</h2><a href="<?= e(url('admin/events.php')) ?>">تقویم مهد ←</a></div>
        <?php if (!$upcomingEvents): ?><p class="portal-empty">رویدادی برنامه‌ریزی نشده است. جشن یا برنامه بعدی مهد را به تقویم اضافه کنید.</p><?php endif; ?>
        <?php foreach ($upcomingEvents as $event): ?>
            <div class="portal-row"><span class="portal-row-avatar" aria-hidden="true">☀</span><div><a href="<?= e(url('admin/events.php')) ?>"><?= e($event['title']) ?></a><small><?= e(shamsiDate($event['event_date'], 'with_day')) ?></small></div></div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
