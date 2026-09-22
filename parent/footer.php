<?php
if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
$siteNameValue = siteName();
[$footerJy] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
$footerJyFa = persianNumber((string) $footerJy);
?>
      </div>
   </main>

    <footer class="parent-footer">
        <div class="parent-footer-container">
            <p>&copy; <?php echo e($footerJyFa); ?> <?php echo e($siteNameValue); ?> &mdash; تمامی حقوق محفوظ است</p>
        </div>
    </footer>

    <?php require_once __DIR__ . '/../templates/app/dialogs.php'; ?>

    <?php /* ── PWA install banner (shown once; closing it hides it forever) ── */ ?>
    <div class="pwa-banner" id="pwaInstallBanner" role="dialog" aria-live="polite" aria-label="نصب اپلیکیشن والدین">
        <span class="pwa-banner-icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
        </span>
        <div class="pwa-banner-text">
            <strong>اپ والدین <?php echo e($siteNameValue); ?> را نصب کنید</strong>
            <span>دسترسی سریع‌تر، بدون نیاز به مرورگر — گزارش روزانه همیشه یک لمس فاصله دارد.</span>
        </div>
        <div class="pwa-banner-actions">
            <button type="button" class="pwa-banner-install" data-pwa-install>نصب</button>
            <button type="button" class="pwa-banner-guide" data-pwa-guide>راهنما</button>
            <button type="button" class="pwa-banner-dismiss" data-pwa-dismiss aria-label="بستن">✕</button>
        </div>
    </div>

    <?php /* ── PWA install guide modal (Android / iOS / Desktop) ── */ ?>
    <div class="pwa-modal" id="pwaGuideModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="pwaGuideTitle">
        <div class="pwa-modal-backdrop" data-pwa-close></div>
        <div class="pwa-modal-card">
            <div class="pwa-modal-head">
                <div>
                    <h3 id="pwaGuideTitle">نصب اپ والدین <?php echo e($siteNameValue); ?></h3>
                    <p>روش نصب متناسب با دستگاه شما:</p>
                </div>
                <button type="button" class="pwa-modal-close" data-pwa-close aria-label="بستن راهنما">✕</button>
            </div>
            <div class="pwa-tabs" role="tablist" aria-label="انتخاب دستگاه">
                <button type="button" class="pwa-tab" data-pwa-tab="android" role="tab">اندروید</button>
                <button type="button" class="pwa-tab" data-pwa-tab="ios" role="tab">آیفون</button>
                <button type="button" class="pwa-tab" data-pwa-tab="desktop" role="tab">کامپیوتر</button>
            </div>
            <div class="pwa-panes">
                <ol class="pwa-steps" data-pwa-pane="android">
                    <li>دکمه <strong>«نصب»</strong> بالای همین صفحه را بزنید و <strong>Install</strong> را تأیید کنید.</li>
                    <li>اگر دکمه نصب ندیدید: منوی <strong>⋮</strong> مرورگر کروم ← <strong>Install app / نصب برنامه</strong>.</li>
                    <li>آیکون «روما» به صفحه اصلی گوشی اضافه می‌شود و مثل یک اپ مستقل باز می‌شود.</li>
                </ol>
                <ol class="pwa-steps" data-pwa-pane="ios" hidden>
                    <li>این صفحه را در مرورگر <strong>Safari</strong> باز کنید.</li>
                    <li>دکمه <strong>اشتراک‌گذاری</strong> (مربع با فلش رو به بالا) را بزنید.</li>
                    <li>گزینه <strong>Add to Home Screen / افزودن به صفحه اصلی</strong> را انتخاب و <strong>Add</strong> را بزنید.</li>
                </ol>
                <ol class="pwa-steps" data-pwa-pane="desktop" hidden>
                    <li>در نوار آدرس مرورگر (کروم/Edge) روی آیکون <strong>نصب (Install)</strong> کلیک کنید.</li>
                    <li>یا منوی مرورگر ← <strong>Install / نصب برنامه</strong>.</li>
                    <li>اپ در منوی استارت/داک شما قرار می‌گیرد و در پنجره مستقل اجرا می‌شود.</li>
                </ol>
            </div>
            <div class="pwa-modal-foot">
                <button type="button" class="btn btn-primary btn-sm" data-pwa-install>نصب خودکار</button>
                <button type="button" class="btn btn-outline btn-sm" data-pwa-close>باشه، فهمیدم</button>
            </div>
        </div>
    </div>

<!-- Mobile Bottom Navigation - Parent Portal -->
<nav class="mobile-bottom-nav parent-bottom-nav" aria-label="منوی ناوبری موبایل والدین">
    <a href="<?php echo e(url('parent/index.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span class="bottom-nav-label">داشبورد</span>
    </a>
    <a href="<?php echo e(url('parent/children.php')); ?>" class="bottom-nav-item <?php echo in_array($currentPage, ['children.php', 'child-detail.php', 'add-child.php'], true) ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg>
        <span class="bottom-nav-label">فرزندان</span>
    </a>
    <a href="<?php echo e(url('parent/messages.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span class="bottom-nav-label">پیام‌ها</span>
        <?php if (isset($unreadCount) && $unreadCount > 0): ?>
        <span class="bottom-nav-badge"><?php echo e(persianNumber((string)$unreadCount)); ?></span>
        <?php endif; ?>
    </a>
    <a href="<?php echo e(url('parent/attendance.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span class="bottom-nav-label">حضور</span>
    </a>
    <a href="<?php echo e(url('parent/payments.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'payments.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        <span class="bottom-nav-label">پرداخت</span>
    </a>
    <a href="<?php echo e(url('parent/profile.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span class="bottom-nav-label">پروفایل</span>
    </a>
</nav>

    <script src="<?php echo e(asset('assets/js/script.js')); ?>" defer></script>
</body>
</html>
