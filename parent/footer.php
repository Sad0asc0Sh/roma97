<?php
if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
$siteNameValue = siteName();
?>
      </div>
   </main>

    <footer class="parent-footer">
        <div class="parent-footer-container">
            <p>&copy; <?php echo e(persianNumber(date('Y'))); ?> <?php echo e($siteNameValue); ?> &mdash; تمامی حقوق محفوظ است</p>
        </div>
    </footer>

    
<!-- Mobile Bottom Navigation - Parent Portal -->
<nav class="mobile-bottom-nav parent-bottom-nav" aria-label="منوی ناوبری موبایل والدین">
    <a href="<?php echo e(url('parent/index.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span class="bottom-nav-label">داشبورد</span>
    </a>
    <a href="<?php echo e(url('parent/messages.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span class="bottom-nav-label">پیامها</span>
    </a>
    <a href="<?php echo e(url('parent/attendance.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span class="bottom-nav-label">حضور</span>
    </a>
    <a href="<?php echo e(url('parent/payments.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'payments.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        <span class="bottom-nav-label">پرداخت</span>
    </a>
    <a href="<?php echo e(url('parent/profile.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span class="bottom-nav-label">پروفایل</span>
    </a>
</nav>

    <script src="<?php echo e(url('assets/js/script.js')); ?>" defer></script>
</body>
</html>
