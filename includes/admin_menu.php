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
    ?>
    <nav class="admin-menu" aria-label="منوی مدیریت">
        <a href="<?= e(url('admin/index.php')) ?>">داشبورد</a>
        <span>|</span>
        <a href="<?= e(url('admin/settings.php')) ?>">تنظیمات</a>
        <span>|</span>
        <a href="<?= e(url('admin/slides.php')) ?>">اسلایدها</a>
        <span>|</span>
        <a href="<?= e(url('admin/news.php')) ?>">اخبار</a>
        <span>|</span>
        <a href="<?= e(url('admin/pages.php')) ?>">صفحات</a>
        <span>|</span>
        <a href="<?= e(url('admin/children.php')) ?>">کودکان</a>
        <span>|</span>
        <a href="<?= e(url('admin/attendance.php')) ?>">حضور و غیاب</a>
        <span>|</span>
        <a href="<?= e(url('admin/events.php')) ?>">رویدادها</a>
        <span>|</span>
        <a href="<?= e(url('admin/teachers.php')) ?>">معلمان</a>
        <span>|</span>
        <a href="<?= e(url('admin/classrooms.php')) ?>">کلاس‌ها</a>
        <span>|</span>
        <a href="<?= e(url('admin/salary.php')) ?>">حقوق</a>
        <span>|</span>
        <a href="<?= e(url('admin/tuition.php')) ?>">شهریه</a>
        <span>|</span>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <button type="submit" class="btn-reset">خروج</button>
       </form>
   </nav>
    <?php
}
