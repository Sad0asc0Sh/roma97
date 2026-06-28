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

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var sidebar = document.getElementById('adminSidebar');
        var overlay = document.getElementById('adminOverlay');
        var mobileToggle = document.getElementById('mobileSidebarToggle');
        var sidebarToggle = document.getElementById('sidebarToggle');
        if (mobileToggle && sidebar && overlay) {
            mobileToggle.addEventListener('click', function() { sidebar.classList.toggle('active'); overlay.classList.toggle('active'); });
            overlay.addEventListener('click', function() { sidebar.classList.remove('active'); overlay.classList.remove('active'); });
        }
        if (sidebarToggle && sidebar) { sidebarToggle.addEventListener('click', function() { sidebar.classList.toggle('collapsed'); }); }
        document.querySelectorAll('.admin-nav-parent').forEach(function(btn) {
            btn.addEventListener('click', function() { var s = this.nextElementSibling; if(s){ s.classList.toggle('open'); this.setAttribute('aria-expanded', s.classList.contains('open')); } });
            var s = btn.nextElementSibling; if(s && s.querySelector('.active')) { s.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); }
        });
    });
    </script>
</body>
</html>
