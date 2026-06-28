<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
$siteNameValue = siteName();
?>
        </div>
    </main>

    <footer class="teacher-footer">
        <div class="teacher-footer-content">
            <p>&copy; <?php echo e(persianNumber(date('Y'))); ?> <?php echo e($siteNameValue); ?> &mdash; پنل معلم</p>
        </div>
    </footer>

    
<!-- Mobile Bottom Navigation - Teacher Portal -->
<nav class="mobile-bottom-nav teacher-bottom-nav" aria-label="منوی ناوبری موبایل معلم">
    <a href="<?php echo e(url('teacher/index.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span class="bottom-nav-label">داشبورد</span>
    </a>
    <a href="<?php echo e(url('teacher/report.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'report.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
        <span class="bottom-nav-label">گزارش</span>
    </a>
    <a href="<?php echo e(url('teacher/messages.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        <span class="bottom-nav-label">پیامها</span>
    </a>
</nav>

    <script src="<?php echo e(url('assets/js/script.js')); ?>" defer></script>
</body>
</html>
