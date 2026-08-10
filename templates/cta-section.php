<?php
/**
 * Shared CTA Section Partial
 * Roma Kindergarten
 */
?>
<section class="cta-section cta-banner">
    <div class="container">
        <div class="cta-content cta-banner-content">
            <h2 class="cta-banner-title">
                همین امروز برای آینده کودکتان اقدام کنید
            </h2>
            <p class="cta-banner-text">
                برای رزرو وقت بازدید حضوری و صحبت با مشاوره ثبت‌نام، با ما در ارتباط باشید یا فرم زیر را تکمیل کنید.
            </p>
            <div class="cta-buttons">
                <a href="<?php echo url('page.php?slug=contact'); ?>" class="btn btn-secondary btn-lg">
                    ثبت‌نام و بازدید حضوری
                </a>
                <a href="tel:<?php echo e(siteContactPhone()); ?>" class="btn btn-outline btn-lg cta-btn-phone">
                    تماس تلفنی: <?php echo e(persianNumber(siteContactPhone())); ?>

                </a>
            </div>
        </div>
    </div>
    <!-- Decorative Bargoo Waving in bottom corner -->
    <div class="cta-mascot-corner">
        <svg viewBox="0 0 100 100" class="cta-mascot-svg"><use href="<?php echo asset('assets/img/bargoo.svg#bargoo-waving'); ?>"/></svg>
    </div>
</section>
