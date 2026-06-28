<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
?>
<!-- Mobile Bottom Navigation - Public Site -->
<nav class="mobile-bottom-nav public-bottom-nav" aria-label="منوی ناوبری موبایل">
    <a href="<?php echo e(url('index.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span class="bottom-nav-label">خانه</span>
    </a>
    <a href="<?php echo e(url('page.php?slug=classes')); ?>" class="bottom-nav-item <?php echo ($currentPage === 'page.php' && $currentSlug === 'classes') ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
        <span class="bottom-nav-label">کلاسها</span>
    </a>
    <a href="<?php echo e(url('news.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'news.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
        <span class="bottom-nav-label">اخبار</span>
    </a>
    <a href="<?php echo e(url('page.php?slug=about')); ?>" class="bottom-nav-item <?php echo ($currentPage === 'page.php' && $currentSlug === 'about') ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span class="bottom-nav-label">درباره ما</span>
    </a>
    <a href="<?php echo e(url('page.php?slug=contact')); ?>" class="bottom-nav-item <?php echo ($currentPage === 'page.php' && $currentSlug === 'contact') ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
        <span class="bottom-nav-label">تماس</span>
    </a>
    <?php if (isParentLoggedIn()): ?>
    <a href="<?php echo e(url('parent/index.php')); ?>" class="bottom-nav-item bottom-nav-auth">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span class="bottom-nav-label">پنل</span>
    </a>
    <?php elseif (isLoggedIn()): ?>
    <a href="<?php echo e(url('admin/index.php')); ?>" class="bottom-nav-item bottom-nav-auth">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span class="bottom-nav-label">پنل</span>
    </a>
    <?php else: ?>
    <a href="<?php echo e(url('login.php')); ?>" class="bottom-nav-item bottom-nav-auth <?php echo $currentPage === 'login.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        <span class="bottom-nav-label">ورود</span>
    </a>
    <?php endif; ?>
</nav>
