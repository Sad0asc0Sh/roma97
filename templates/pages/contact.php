<?php
declare(strict_types=1);

/**
 * Rooma — Contact page template (page.php?slug=contact)
 *
 * The POST handler lives in page.php and exposes $contactFormStatus.
 */

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../../includes/ui.php';

$siteNameV     = siteName();
$contactPhone  = siteContactPhone();
$contactEmail  = siteContactEmail();
$siteAddressV  = siteAddress();
$mapEmbedUrl   = siteMapEmbedUrl();
$mapLink       = siteMapLink();
$whatsappV     = siteWhatsApp();

$contactFormStatus = $contactFormStatus ?? null;

// Preserve what the visitor typed when validation fails, so nothing is lost.
$oldName    = $contactFormStatus !== null && !$contactFormStatus['ok'] ? (string) ($_POST['name'] ?? '') : '';
$oldPhone   = $contactFormStatus !== null && !$contactFormStatus['ok'] ? (string) ($_POST['phone'] ?? '') : '';
$oldEmail   = $contactFormStatus !== null && !$contactFormStatus['ok'] ? (string) ($_POST['email'] ?? '') : '';
$oldSubject = $contactFormStatus !== null && !$contactFormStatus['ok'] ? (string) ($_POST['subject'] ?? 'visit') : 'visit';
$oldMessage = $contactFormStatus !== null && !$contactFormStatus['ok'] ? (string) ($_POST['message'] ?? '') : '';

$heroImg = is_file(__DIR__ . '/../../assets/img/hero-classroom.jpg')
    ? 'assets/img/hero-classroom.jpg'
    : 'assets/img/about-story.jpg';

$subjectOptions = [
    'visit'    => 'درخواست بازدید حضوری از مهدکودک',
    'register' => 'اطلاعات شهریه و مدارک ثبت‌نام',
    'consult'  => 'مشاوره با روانشناس مجموعه',
    'other'    => 'سایر موارد',
];
?>

<?php /* ═══════════════ PAGE HERO ═══════════════ */ ?>
<section class="page-hero">
    <div class="page-hero__media">
        <img src="<?php echo e(asset($heroImg)); ?>" alt="" width="1920" height="1072" loading="eager" fetchpriority="high">
    </div>
    <div class="page-hero__scrim"></div>

    <div class="container page-hero__inner">
        <nav class="crumbs" aria-label="مسیر صفحه">
            <a href="<?php echo e(url('index.php')); ?>">خانه</a>
            <span class="crumbs__sep" aria-hidden="true">‹</span>
            <span class="crumbs__current" aria-current="page">تماس با ما</span>
        </nav>
        <h1 class="page-hero__title">ارتباط با <?php echo e($siteNameV); ?> و رزرو بازدید</h1>
        <p class="page-hero__sub">
            خوشحال می‌شویم پاسخ سوال‌هایتان را بدهیم و زمانی برای دیدن مجموعه از نزدیک
            هماهنگ کنیم.
        </p>
    </div>
</section>

<?php /* ═══════════════ QUICK CONTACT CARDS ═══════════════ */ ?>
<section class="section section-sm">
    <div class="container">
        <div class="feature-grid" data-reveal-group data-reveal-step="80">
            <?php if ($contactPhone !== ''): ?>
            <a class="card card-hover feature" href="tel:<?php echo e(telHref($contactPhone)); ?>" data-reveal="up">
                <span class="feature__icon"><?php echo icon('phone', 27); ?></span>
                <h2 class="feature__title">تماس تلفنی</h2>
                <p class="feature__text tnum"><?php echo e(persianNumber($contactPhone)); ?></p>
                <p class="feature__text">سریع‌ترین راه گرفتن پاسخ در ساعات کاری.</p>
            </a>
            <?php endif; ?>

            <?php if ($whatsappV !== ''): ?>
            <a class="card card-hover feature" href="https://wa.me/<?php echo e(whatsappHref($whatsappV)); ?>" target="_blank" rel="noopener noreferrer" data-reveal="up">
                <span class="feature__icon"><?php echo brandIcon('whatsapp', 26); ?></span>
                <h2 class="feature__title">واتس‌اپ روما</h2>
                <p class="feature__text">اگر ترجیح می‌دهید بنویسید، همین‌جا پیام بدهید.</p>
            </a>
            <?php endif; ?>

            <?php if ($contactEmail !== ''): ?>
            <a class="card card-hover feature" href="mailto:<?php echo e($contactEmail); ?>" data-reveal="up">
                <span class="feature__icon"><?php echo icon('mail', 27); ?></span>
                <h2 class="feature__title">ایمیل</h2>
                <p class="feature__text"><?php echo e($contactEmail); ?></p>
                <p class="feature__text">برای ارسال مدارک یا سوال‌های تفصیلی.</p>
            </a>
            <?php endif; ?>

            <div class="card card-hover feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('clock', 27); ?></span>
                <h2 class="feature__title">ساعات پاسخگویی</h2>
                <p class="feature__text">شنبه تا چهارشنبه ۰۷:۰۰–۱۶:۳۰</p>
                <p class="feature__text">پنج‌شنبه ۰۷:۰۰–۱۳:۰۰</p>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ FORM + INFO ═══════════════ */ ?>
<section class="section section-sm">
    <div class="container">
        <div class="split split--wide-start">

            <?php /* ── Form ── */ ?>
            <div data-reveal="start">
                <div class="card card-pad">
                    <div class="info-card__head">
                        <span class="info-card__icon"><?php echo icon('send', 21); ?></span>
                        <h2 class="info-card__title">ارسال پیام یا درخواست بازدید</h2>
                    </div>
                    <p class="info-card__text mb-lg">
                        فرم زیر را پر کنید؛ کارشناسان ما در نخستین فرصت کاری با شما تماس می‌گیرند.
                    </p>

                    <?php if ($contactFormStatus !== null): ?>
                        <div class="alert <?php echo $contactFormStatus['ok'] ? 'alert-success' : 'alert-danger'; ?> mb-lg"
                             role="<?php echo $contactFormStatus['ok'] ? 'status' : 'alert'; ?>">
                            <?php echo icon($contactFormStatus['ok'] ? 'check-circle' : 'alert-triangle', 18); ?>
                            <span><?php echo e($contactFormStatus['text']); ?></span>
                        </div>
                    <?php endif; ?>

                    <form class="form-grid" method="post" action="<?php echo e(url('page.php?slug=contact')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">

                        <div class="field">
                            <label class="field__label" for="contact_name">
                                نام و نام خانوادگی <span class="field__req">*</span>
                            </label>
                            <input type="text" id="contact_name" name="name" class="form-control"
                                   value="<?php echo e($oldName); ?>"
                                   placeholder="مثال: مریم محمدی" autocomplete="name"
                                   maxlength="100" required>
                        </div>

                        <div class="form-row">
                            <div class="field">
                                <label class="field__label" for="contact_phone">
                                    شماره تماس <span class="field__req">*</span>
                                </label>
                                <input type="tel" id="contact_phone" name="phone" class="form-control"
                                       value="<?php echo e($oldPhone); ?>"
                                       placeholder="۰۹۱۲۱۲۳۴۵۶۷" inputmode="tel"
                                       autocomplete="tel" dir="ltr" required>
                            </div>
                            <div class="field">
                                <label class="field__label" for="contact_email">ایمیل (اختیاری)</label>
                                <input type="email" id="contact_email" name="email" class="form-control"
                                       value="<?php echo e($oldEmail); ?>"
                                       placeholder="example@mail.com" inputmode="email"
                                       autocomplete="email" dir="ltr" maxlength="150">
                            </div>
                        </div>

                        <div class="field">
                            <label class="field__label" for="contact_subject">موضوع پیام</label>
                            <select id="contact_subject" name="subject" class="form-control">
                                <?php foreach ($subjectOptions as $value => $label): ?>
                                    <option value="<?php echo e($value); ?>" <?php echo $oldSubject === $value ? 'selected' : ''; ?>>
                                        <?php echo e($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label class="field__label" for="contact_message">
                                متن پیام یا توضیحات <span class="field__req">*</span>
                            </label>
                            <textarea id="contact_message" name="message" class="form-control" rows="5"
                                      placeholder="سن فرزندتان و زمان مناسب برای تماس را بنویسید تا سریع‌تر راهنمایی‌تان کنیم…"
                                      maxlength="5000" required><?php echo e($oldMessage); ?></textarea>
                            <span class="field__hint">حداکثر ۵۰۰۰ نویسه</span>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg btn-block">
                            <?php echo icon('send', 19); ?>
                            ارسال پیام و هماهنگی
                        </button>
                    </form>
                </div>
            </div>

            <?php /* ── Address / map / hours ── */ ?>
            <div class="stack" data-reveal="end">

                <div class="card card-pad">
                    <div class="info-card__head">
                        <span class="info-card__icon info-card__icon--clay"><?php echo icon('pin', 21); ?></span>
                        <h2 class="info-card__title">نشانی مهدکودک <?php echo e($siteNameV); ?></h2>
                    </div>
                    <p class="info-card__text">
                        <?php if ($siteAddressV !== ''): ?>
                            <?php echo e($siteAddressV); ?>
                        <?php else: ?>
                            نشانی دقیق مجموعه به‌زودی در این بخش اعلام می‌شود. برای اطلاع از
                            موقعیت، با ما تماس بگیرید.
                        <?php endif; ?>
                    </p>

                    <?php if ($mapEmbedUrl !== null): ?>
                        <div class="map-frame">
                            <iframe title="نقشه موقعیت <?php echo e($siteNameV); ?>"
                                    src="<?php echo e($mapEmbedUrl); ?>"
                                    loading="lazy" allowfullscreen
                                    referrerpolicy="no-referrer-when-downgrade"></iframe>
                        </div>
                    <?php elseif ($mapLink !== ''): ?>
                        <a class="btn btn-outline mt-md" href="<?php echo e($mapLink); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo icon('pin', 17); ?>
                            مشاهده موقعیت روی نقشه
                            <?php echo icon('external', 15); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="card card-pad">
                    <div class="info-card__head">
                        <span class="info-card__icon info-card__icon--accent"><?php echo icon('clock', 21); ?></span>
                        <h2 class="info-card__title">ساعات کاری و پذیرش</h2>
                    </div>
                    <table class="hours">
                        <tbody>
                            <tr>
                                <th scope="row">شنبه تا چهارشنبه</th>
                                <td>۰۷:۰۰ – ۱۶:۳۰</td>
                            </tr>
                            <tr>
                                <th scope="row">پنج‌شنبه</th>
                                <td>۰۷:۰۰ – ۱۳:۰۰</td>
                            </tr>
                            <tr>
                                <th scope="row">جمعه و تعطیلات رسمی</th>
                                <td class="is-closed">تعطیل</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="alert alert-info mt-md">
                        <?php echo icon('info', 18); ?>
                        <span>
                            برای بازدید حضوری بهتر است بین ساعت ۰۹:۰۰ تا ۱۱:۰۰ بیایید؛
                            در این ساعت کلاس‌ها در اوج فعالیت هستند و فضا را واقعی می‌بینید.
                        </span>
                    </div>
                </div>

                <div class="card card-pad">
                    <div class="info-card__head">
                        <span class="info-card__icon"><?php echo icon('file-text', 21); ?></span>
                        <h2 class="info-card__title">برای بازدید چه چیزی بیاورم؟</h2>
                    </div>
                    <ul class="check-list">
                        <li><?php echo icon('check', 19); ?><span>سن دقیق فرزندتان (برای معرفی کلاس مناسب)</span></li>
                        <li><?php echo icon('check', 19); ?><span>سوال‌هایتان درباره تغذیه، خواب و برنامه روزانه</span></li>
                        <li><?php echo icon('check', 19); ?><span>در صورت وجود، سابقه پزشکی یا حساسیت غذایی</span></li>
                    </ul>
                    <p class="info-card__text mt-md">
                        آوردن فرزندتان به بازدید توصیه می‌شود — واکنش خودش به فضا، بهترین
                        راهنمای شماست.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../cta-section.php'; ?>
