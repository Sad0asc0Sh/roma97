<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
?>
       </main>

        <footer class="admin-footer">
            <div class="admin-footer-content">
                <p>© <?= e(persianNumber(date('Y'))) ?> <?= e(siteName()) ?> — تمامی حقوق محفوظ است</p>
                <p class="admin-footer-version">نسخه ۱.۰</p>
            </div>
      </footer>
  </div>

    <script src="<?= e(url('assets/js/script.js')) ?>" defer></script>
</body>
</html>
