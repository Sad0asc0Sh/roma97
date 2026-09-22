<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/ui.php';

$siteNameValue   = siteName();
$siteDescription = siteDescription();
$contactPhone    = siteContactPhone();
$contactEmail    = siteContactEmail();
$siteAddressV    = siteAddress();
$workingHoursV   = siteWorkingHours();
$instagramV      = siteInstagram();
$telegramV       = siteTelegram();
$whatsappV       = siteWhatsApp();

$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$currentSlug = $currentSlug ?? (string) ($_GET['slug'] ?? '');
$footerNav   = roomaPublicNav($currentPage, $currentSlug);
?>
</main>

<footer class="site-footer">
    <div class="container">
        <div class="site-footer__grid">

            <div>
                <div class="site-footer__brand">
                    <?php echo roomaLogoMark(36, 'footerLogoGrad'); ?>
                    <span class="site-footer__brand-name"><?php echo e($siteNameValue); ?></span>
                </div>
                <p class="site-footer__about"><?php echo e($siteDescription); ?></p>
                <span class="site-footer__tagline">
                    <?php echo icon('sparkles', 14); ?>
                    جایی که ستاره‌های کوچک می‌درخشند
                </span>

                <?php if ($instagramV !== '' || $telegramV !== '' || $whatsappV !== ''): ?>
                <div class="site-footer__socials">
                    <?php if ($instagramV !== ''): ?>
                        <a class="site-footer__social" href="<?php echo e($instagramV); ?>" target="_blank" rel="noopener noreferrer" aria-label="اینستاگرام روما"><?php echo brandIcon('instagram', 17); ?></a>
                    <?php endif; ?>
                    <?php if ($telegramV !== ''): ?>
                        <a class="site-footer__social" href="<?php echo e($telegramV); ?>" target="_blank" rel="noopener noreferrer" aria-label="تلگرام روما"><?php echo brandIcon('telegram', 17); ?></a>
                    <?php endif; ?>
                    <?php if ($whatsappV !== ''): ?>
                        <a class="site-footer__social" href="https://wa.me/<?php echo e(whatsappHref($whatsappV)); ?>" target="_blank" rel="noopener noreferrer" aria-label="واتس‌اپ روما"><?php echo brandIcon('whatsapp', 17); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="site-footer__title">دسترسی سریع</h2>
                <nav class="site-footer__nav" aria-label="پیوندهای فوتر">
                    <?php foreach ($footerNav as $item): ?>
                        <a href="<?php echo e(url($item['href'])); ?>"><?php echo e($item['label']); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div>
                <h2 class="site-footer__title">خدمات روما</h2>
                <nav class="site-footer__nav" aria-label="خدمات">
                    <a href="<?php echo e(url('page.php?slug=classes')); ?>">کلاس نوباوه</a>
                    <a href="<?php echo e(url('page.php?slug=classes')); ?>">کلاس خردسال</a>
                    <a href="<?php echo e(url('page.php?slug=classes')); ?>">پیش‌دبستانی</a>
                    <a href="<?php echo e(url('page.php?slug=about')); ?>">تیم مربیان</a>
                    <?php if (!isParentLoggedIn() && !isLoggedIn()): ?>
                        <a href="<?php echo e(url('login.php')); ?>">پنل والدین</a>
                    <?php endif; ?>
                </nav>
            </div>

            <div>
                <h2 class="site-footer__title">ارتباط با ما</h2>
                <div class="site-footer__contacts">
                    <?php if ($contactPhone !== ''): ?>
                        <a class="site-footer__contact" href="tel:<?php echo e(telHref($contactPhone)); ?>">
                            <?php echo icon('phone', 17); ?>
                            <span><?php echo e(persianNumber($contactPhone)); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($contactEmail !== ''): ?>
                        <a class="site-footer__contact" href="mailto:<?php echo e($contactEmail); ?>">
                            <?php echo icon('mail', 17); ?>
                            <span><?php echo e($contactEmail); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($siteAddressV !== ''): ?>
                        <span class="site-footer__contact">
                            <?php echo icon('pin', 17); ?>
                            <span><?php echo e($siteAddressV); ?></span>
                        </span>
                    <?php endif; ?>
                    <?php if ($workingHoursV !== ''): ?>
                        <span class="site-footer__contact">
                            <?php echo icon('clock', 17); ?>
                            <span><?php echo e(persianNumber($workingHoursV)); ?></span>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="site-footer__bottom">
            <p>&copy; <?php echo e(shamsiDate(time(), 'Y')); ?> <?php echo e($siteNameValue); ?> — تمامی حقوق محفوظ است</p>
            <p>طراحی و توسعه با <span class="site-footer__credit-name">SadraSh</span></p>
        </div>
    </div>
</footer>

<?php /* ── Back to top ─────────────────────────────────────────────────── */ ?>
<button type="button" class="to-top" data-to-top aria-label="بازگشت به بالای صفحه">
    <svg class="to-top__ring" data-to-top-ring-wrap viewBox="0 0 44 44" aria-hidden="true" focusable="false">
        <circle data-to-top-ring cx="22" cy="22" r="21"></circle>
    </svg>
    <?php echo icon('chevron-up', 20); ?>
</button>

    <script src="<?php echo e(asset('assets/js/rooma.js')); ?>" defer></script>
    <script src="<?php echo e(asset('assets/js/pwa-install.js')); ?>" defer></script>
</body>
</html>
