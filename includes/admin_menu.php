<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Shared admin navigation menu.
 * Expected variables before including: none (uses url() + generateCsrfToken()).
 */
function renderAdminMenu(): void
{
    $role = currentAdminRole();
    ?>
    <nav class="admin-menu" aria-label="منوی مدیریت">
        <?php if (isPageAllowedForRole('admin/index.php', $role)): ?>
            <a href="<?= e(url('admin/index.php')) ?>">داشبورد</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/settings.php', $role)): ?>
            <a href="<?= e(url('admin/settings.php')) ?>">تنظیمات</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/slides.php', $role)): ?>
            <a href="<?= e(url('admin/slides.php')) ?>">اسلایدها</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/news.php', $role)): ?>
            <a href="<?= e(url('admin/news.php')) ?>">اخبار</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/pages.php', $role)): ?>
            <a href="<?= e(url('admin/pages.php')) ?>">صفحات</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/children.php', $role)): ?>
            <a href="<?= e(url('admin/children.php')) ?>">کودکان</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/attendance.php', $role)): ?>
            <a href="<?= e(url('admin/attendance.php')) ?>">حضور و غیاب</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/events.php', $role)): ?>
            <a href="<?= e(url('admin/events.php')) ?>">رویدادها</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/teachers.php', $role)): ?>
            <a href="<?= e(url('admin/teachers.php')) ?>">معلمان</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/classrooms.php', $role)): ?>
            <a href="<?= e(url('admin/classrooms.php')) ?>">کلاس‌ها</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/salary.php', $role)): ?>
            <a href="<?= e(url('admin/salary.php')) ?>">حقوق</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/tuition.php', $role)): ?>
            <a href="<?= e(url('admin/tuition.php')) ?>">شهریه</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/expenses.php', $role)): ?>
            <a href="<?= e(url('admin/expenses.php')) ?>">هزینه‌ها</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/reports.php', $role)): ?>
            <a href="<?= e(url('admin/reports.php')) ?>">گزارش مالی</a>
            <span>|</span>
        <?php endif; ?>
        <?php if (isPageAllowedForRole('admin/backup.php', $role)): ?>
            <a href="<?= e(url('admin/backup.php')) ?>">پشتیبان‌گیری</a>
            <span>|</span>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <button type="submit" class="btn-reset">خروج</button>
       </form>
   </nav>
    <?php
}
