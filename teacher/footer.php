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

    <script src="<?php echo e(url('assets/js/script.js')); ?>" defer></script>
</body>
</html>
