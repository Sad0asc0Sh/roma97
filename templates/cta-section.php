<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/ui.php';

$ctaPhone = siteContactPhone();
?>
<section class="cta" data-reveal-group>
    <div class="container cta__inner">
        <span class="eyebrow eyebrow-light" data-reveal="up">
            <?php echo icon('calendar', 14); ?>
            ظرفیت دوره جدید محدود است
        </span>

        <h2 class="cta__title" data-reveal="up">
            بهترین راه شناختن روما، دیدن آن از نزدیک است
        </h2>

        <p class="cta__text" data-reveal="up">
            یک بازدید حضوری نیم‌ساعته رزرو کنید: کلاس‌ها، حیاط بازی و آشپزخانه را ببینید،
            با مربی فرزندتان صحبت کنید و پاسخ همه سوال‌هایتان را بگیرید — بدون هیچ تعهدی.
        </p>

        <div class="cta__actions" data-reveal="up">
            <a class="btn btn-secondary btn-lg" href="<?php echo e(url('page.php?slug=contact')); ?>">
                <?php echo icon('send', 19); ?>
                رزرو بازدید حضوری
            </a>
            <?php if ($ctaPhone !== ''): ?>
                <a class="btn btn-light btn-lg" href="tel:<?php echo e(telHref($ctaPhone)); ?>">
                    <?php echo icon('phone', 19); ?>
                    <?php echo e(persianNumber($ctaPhone)); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php echo bargooSvg('waving', ['class' => 'cta__mascot']); ?>
</section>
