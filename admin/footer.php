<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

$dockRole = currentAdminRole();
$dockCan = static fn(string $pagePath): bool => isPageAllowedForRole($pagePath, $dockRole);
$dockCurrent = $currentPage ?? basename($_SERVER['PHP_SELF']);
?>
       </main>

        <footer class="admin-footer">
            <div class="admin-footer-content">
                <p>© <?= e(shamsiDate(date('Y-m-d'), 'Y')) ?> <?= e(siteName()) ?> — تمامی حقوق محفوظ است</p>
                <p class="admin-footer-version">نسخه ۱.۰</p>
            </div>
        </footer>
    </div>

    <nav class="adm-mobile-dock" aria-label="دسترسی سریع موبایل">
        <?php if ($dockCan('admin/index.php')): ?>
            <a href="<?= e(url('admin/index.php')) ?>" class="adm-dock-item <?= $dockCurrent === 'index.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg>
                <span>داشبورد</span>
            </a>
        <?php endif; ?>

        <?php if ($dockCan('admin/attendance.php')): ?>
            <a href="<?= e(url('admin/attendance.php')) ?>" class="adm-dock-item <?= $dockCurrent === 'attendance.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                <span>حضور</span>
            </a>
        <?php elseif ($dockCan('admin/tuition.php')): ?>
            <a href="<?= e(url('admin/tuition.php')) ?>" class="adm-dock-item <?= $dockCurrent === 'tuition.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <span>شهریه</span>
            </a>
        <?php endif; ?>

        <?php if ($dockCan('admin/children.php')): ?>
            <a href="<?= e(url('admin/children.php')) ?>" class="adm-dock-item <?= in_array($dockCurrent, ['children.php', 'child-detail.php', 'add-child.php', 'edit-child.php'], true) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/></svg>
                <span>کودکان</span>
            </a>
        <?php elseif ($dockCan('admin/reports.php')): ?>
            <a href="<?= e(url('admin/reports.php')) ?>" class="adm-dock-item <?= $dockCurrent === 'reports.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                <span>گزارش مالی</span>
            </a>
        <?php endif; ?>

        <?php if ($dockCan('admin/messages.php')): ?>
            <a href="<?= e(url('admin/messages.php')) ?>" class="adm-dock-item <?= $dockCurrent === 'messages.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <span>پیام‌ها</span>
            </a>
        <?php elseif ($dockCan('admin/expenses.php')): ?>
            <a href="<?= e(url('admin/expenses.php')) ?>" class="adm-dock-item <?= $dockCurrent === 'expenses.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span>هزینه‌ها</span>
            </a>
        <?php endif; ?>

        <button type="button" class="adm-dock-item" data-dock-menu-trigger onclick="window.toggleMobileSidebar(true)" aria-controls="adminSidebar" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            <span>منو</span>
        </button>
    </nav>

    <?php require_once __DIR__ . '/../templates/app/dialogs.php'; ?>
    <script src="<?php echo e(asset('assets/js/script.js')); ?>" defer></script>
</body>
</html>
