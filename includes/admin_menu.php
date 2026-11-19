<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Shared admin navigation menu.
 * Modern pill-based navigation bar gated by the central permissions matrix.
 */
function renderAdminMenu(): void
{
    $role = currentAdminRole();
    $current = basename($_SERVER['PHP_SELF']);
    $items = [
        ['admin/index.php', 'داشبورد'],
        ['admin/attendance.php', 'حضور و غیاب'],
        ['admin/children.php', 'کودکان'],
        ['admin/parents.php', 'والدین'],
        ['admin/classrooms.php', 'کلاس‌ها'],
        ['admin/teachers.php', 'معلمان'],
        ['admin/events.php', 'رویدادها'],
        ['admin/tuition.php', 'شهریه'],
        ['admin/salary.php', 'حقوق'],
        ['admin/expenses.php', 'هزینه‌ها'],
        ['admin/reports.php', 'گزارش مالی'],
        ['admin/messages.php', 'پیام‌ها'],
        ['admin/sms-center.php', 'مرکز پیامک'],
        ['admin/contact-messages.php', 'پیام‌های تماس'],
        ['admin/slides.php', 'اسلایدها'],
        ['admin/gallery.php', 'گالری'],
        ['admin/news.php', 'اخبار'],
        ['admin/pages.php', 'صفحات'],
        ['admin/backup.php', 'پشتیبان‌گیری'],
        ['admin/settings.php', 'تنظیمات'],
    ];
    ?>
    <nav class="admin-menu adm-segmented-nav" aria-label="منوی مدیریت">
        <?php foreach ($items as [$path, $label]): ?>
            <?php if (isPageAllowedForRole($path, $role)): ?>
                <a href="<?= e(url($path)) ?>" class="<?= basename($path) === $current ? 'is-active' : '' ?>"><?= e($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <button type="submit" class="btn btn-sm btn-outline">خروج</button>
        </form>
    </nav>
    <?php
}
