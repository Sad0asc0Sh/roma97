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

    <script src="<?php echo e(url('assets/js/script.js')); ?>" defer></script>
</body>
</html>
