<?php
/**
 * Shared CTA Section Partial
 * Roma Kindergarten
 */
?>
<section class="cta-section" style="background: var(--ink-night); color: var(--white); padding: var(--space-2xl) 0; position: relative; overflow: hidden;">
    <div class="container">
        <div class="cta-content" style="max-width: 720px; margin: 0 auto; text-align: center; position: relative; z-index: 2;">
            <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--white); margin-bottom: var(--space-sm);">
                همین امروز برای آینده کودکتان اقدام کنید
            </h2>
            <p style="font-size: var(--text-lg); opacity: 0.9; margin-bottom: var(--space-lg); line-height: 1.8;">
                برای رزرو وقت بازدید حضوری و صحبت با مشاوره ثبت‌نام، با ما در ارتباط باشید یا فرم زیر را تکمیل کنید.
            </p>
            <div class="cta-buttons" style="display: flex; gap: var(--space-sm); justify-content: center; flex-wrap: wrap;">
                <a href="<?php echo url('page.php?slug=contact'); ?>" class="btn btn-secondary btn-lg" style="padding: 14px 32px; font-size: var(--text-base);">
                    ثبت‌نام و بازدید حضوری
                </a>
                <a href="tel:<?php echo e(siteContactPhone()); ?>" class="btn btn-outline btn-lg" style="color: var(--white); border-color: rgba(255,255,255,0.4); padding: 14px 28px; font-size: var(--text-base);">
                    تماس تلفنی: <?php echo e(persianNumber(siteContactPhone())); ?>

                </a>
            </div>
        </div>
    </div>
    <!-- Decorative Bargoo Waving in bottom corner -->
    <div style="position: absolute; bottom: -10px; inset-inline-end: 20px; width: 90px; height: 90px; opacity: 0.25; pointer-events: none;">
        <svg viewBox="0 0 100 100" style="width:100%; height:100%;"><use href="<?php echo asset('assets/img/bargoo.svg#bargoo-waving'); ?>"/></svg>
    </div>
</section>