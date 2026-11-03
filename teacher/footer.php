<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
$siteNameValue = siteName();
[$footerJy] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
$footerJyFa = persianNumber((string) $footerJy);
$navChildrenActive = in_array($currentPage ?? '', ['children.php', 'child-detail.php'], true);
?>
        </div>
    </main>

    <footer class="teacher-footer">
        <div class="teacher-footer-content">
            <p>&copy; <?php echo e($footerJyFa); ?> <?php echo e($siteNameValue); ?> &mdash; پنل تخصصی مربیان</p>
        </div>
    </footer>

    <?php require_once __DIR__ . '/../templates/app/dialogs.php'; ?>

    <!-- Mobile Bottom Navigation - Teacher Portal (Ergonomic Thumb Bar with Live Pending Counters) -->
    <nav class="mobile-bottom-nav teacher-bottom-nav" aria-label="منوی ناوبری موبایل مربی">
        <a href="<?php echo e(url('teacher/index.php')); ?>" class="bottom-nav-item <?php echo ($currentPage ?? '') === 'index.php' ? 'active' : ''; ?>">
            <span class="bottom-nav-indicator"></span>
            <svg class="bottom-nav-icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span class="bottom-nav-label">داشبورد</span>
        </a>
        <a href="<?php echo e(url('teacher/children.php')); ?>" class="bottom-nav-item <?php echo $navChildrenActive ? 'active' : ''; ?>">
            <span class="bottom-nav-indicator"></span>
            <svg class="bottom-nav-icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg>
            <span class="bottom-nav-label">کودکان</span>
        </a>
        <a href="<?php echo e(url('teacher/attendance.php')); ?>" class="bottom-nav-item <?php echo ($currentPage ?? '') === 'attendance.php' ? 'active' : ''; ?>">
            <span class="bottom-nav-indicator"></span>
            <svg class="bottom-nav-icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            <span class="bottom-nav-label">حضور</span>
            <?php if (!empty($headerAttPending) && $headerAttPending > 0): ?>
                <span class="bottom-nav-badge"><?php echo e(persianNumber((string) $headerAttPending)); ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo e(url('teacher/report.php')); ?>" class="bottom-nav-item <?php echo ($currentPage ?? '') === 'report.php' ? 'active' : ''; ?>">
            <span class="bottom-nav-indicator"></span>
            <svg class="bottom-nav-icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
            <span class="bottom-nav-label">گزارش</span>
            <?php if (!empty($headerRepPending) && $headerRepPending > 0): ?>
                <span class="bottom-nav-badge"><?php echo e(persianNumber((string) $headerRepPending)); ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo e(url('teacher/messages.php')); ?>" class="bottom-nav-item <?php echo ($currentPage ?? '') === 'messages.php' ? 'active' : ''; ?>">
            <span class="bottom-nav-indicator"></span>
            <svg class="bottom-nav-icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <span class="bottom-nav-label">پیام‌ها</span>
        </a>
        <a href="<?php echo e(url('teacher/profile.php')); ?>" class="bottom-nav-item <?php echo ($currentPage ?? '') === 'profile.php' ? 'active' : ''; ?>">
            <span class="bottom-nav-indicator"></span>
            <svg class="bottom-nav-icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span class="bottom-nav-label">پروفایل</span>
        </a>
    </nav>

    <script src="<?php echo e(asset('assets/js/script.js')); ?>" defer></script>
</body>
</html>
