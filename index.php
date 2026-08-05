<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

try {
    initializeCmsTables();
    $pdo = getDb();
    $statement = $pdo->prepare(
        'SELECT id, title, subtitle, image FROM slides ORDER BY sort_order ASC, created_at DESC'
    );
    $statement->execute();
    $slides = $statement->fetchAll();

    $newsStatement = $pdo->prepare(
        'SELECT id, title, content, image, created_at FROM news ORDER BY created_at DESC LIMIT 3'
    );
    $newsStatement->execute();
    $latestNews = $newsStatement->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $slides = [];
    $latestNews = [];
}

function homeNewsExcerpt(string $content, int $limit = 150): string
{
    $plainText = trim(strip_tags($content));

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plainText, 'UTF-8') > $limit
            ? mb_substr($plainText, 0, $limit, 'UTF-8') . '...'
            : $plainText;
    }

    return strlen($plainText) > $limit ? substr($plainText, 0, $limit) . '...' : $plainText;
}

$pageTitle = siteName();
require_once __DIR__ . '/templates/header.php';
?>

<!-- Hero Slider -->
<section class="hero-slider-wrapper" aria-label="تصاویر برگزیده مهد کودک <?= e(siteName()) ?>">
    <?php if ($slides === []): ?>
        <div class="hero-fallback">
            <div class="container">
                <div class="hero-fallback-content fade-in">
                    <svg class="hero-fallback-icon-svg" width="72" height="72" viewBox="0 0 72 72" fill="none">
                        <circle cx="36" cy="36" r="34" stroke="url(#heroGrad)" stroke-width="3"/>
                        <path d="M36 20c-5 0-9 2.4-9 7 0 3 1.6 5 4 6.4-1 .6-2.4 2-3 3.6-.6 1.6-.4 3 .6 4 1 1 2.4 1 3.6.6 1.2-.4 2-1.6 2.4-2.4h1.4c.4.8 1.2 2 2.4 2.4 1.2.4 2.6.4 3.6-.6 1-1 1.2-2.4.6-4-.6-1.6-2-3-3-3.6 2.4-1.4 4-3.4 4-6.4 0-4.6-4-7-9-7z" fill="url(#heroGrad)"/>
                        <circle cx="31" cy="28" r="2" fill="white"/>
                        <circle cx="41" cy="28" r="2" fill="white"/>
                        <path d="M31 35c0 0 2 3 5 3s5-3 5-3" stroke="white" stroke-width="1.6" stroke-linecap="round" fill="none"/>
                        <defs>
                            <linearGradient id="heroGrad" x1="0" y1="0" x2="72" y2="72">
                                <stop stop-color="#3D8B63"/>
                                <stop offset="1" stop-color="#C4724A"/>
                            </linearGradient>
                        </defs>
                    </svg>
                    <h1>به <?= e(siteName()) ?> خوش آمدید</h1>
                    <p>جایی که کوچولوهای شما با عشق و مهربانی بزرگ میشوند</p>
                    <p class="hero-fallback-sub">محیطی امن، شاد و پر از یادگیری برای فرزندان دلبندتان</p>
                    <div class="hero-fallback-actions">
                        <a href="<?= e(url('page.php?slug=about')) ?>" class="btn btn-primary btn-lg">آشنایی با ما</a>
                        <a href="<?= e(url('login.php')) ?>" class="btn btn-outline btn-lg">ثبت نام والدین</a>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="slider" data-slider>
            <div class="slides">
                <?php foreach ($slides as $index => $slide): ?>
                    <div class="slide<?= $index === 0 ? ' is-active' : '' ?>" data-slide>
                        <img src="<?= e(url($slide['image'])) ?>" alt="<?= e($slide['title']) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>">
                        <div class="slide-overlay">
                            <div class="slide-caption">
                                <h2><?= e($slide['title']) ?></h2>
                                <?php if (!empty($slide['subtitle'])): ?><span class="slide-subtitle"><?= e($slide['subtitle']) ?></span><?php endif; ?>
                                <div style="margin-top: var(--space-md);">
                                    <a href="<?= e(url('page.php?slug=contact')) ?>" class="btn btn-secondary btn-sm" style="padding: 10px 20px;">
                                        ثبت‌نام و بازدید حضوری ←
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($slides) > 1): ?>
                <button class="slider-nav slider-prev" type="button" data-prev aria-label="تصویر قبلی">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <button class="slider-nav slider-next" type="button" data-next aria-label="تصویر بعدی">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="slider-dots">
                    <?php foreach ($slides as $index => $slide): ?>
                        <button class="slider-dot<?= $index === 0 ? ' is-active' : '' ?>" type="button" data-dot="<?= e((string) $index ) ?>" aria-label="رفتن به تصویر <?= e(persianNumber((string) ($index + 1))) ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Trust Strip (4 Quick Trust Markers) -->
<div class="trust-strip">
    <div class="container">
        <div class="trust-strip-inner">
            <div class="trust-item">
                <svg><use href="<?= asset('assets/img/icons.svg#icon-shield') ?>"/></svg>
                <span>مجوز رسمی بهزیستی</span>
            </div>
            <div class="trust-divider"></div>
            <div class="trust-item">
                <svg><use href="<?= asset('assets/img/icons.svg#icon-award') ?>"/></svg>
                <span>۱۰+ سال فعالیت تخصصی</span>
            </div>
            <div class="trust-divider"></div>
            <div class="trust-item">
                <svg><use href="<?= asset('assets/img/icons.svg#icon-camera') ?>"/></svg>
                <span>دوربین مداربسته تمام کلاس‌ها</span>
            </div>
            <div class="trust-divider"></div>
            <div class="trust-item">
                <svg><use href="<?= asset('assets/img/icons.svg#icon-heart') ?>"/></svg>
                <span>بیمه حوادث کامل کودکان</span>
            </div>
        </div>
    </div>
</div>
<section class="section services-section">
    <div class="container">
        <div class="section-header">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="19" stroke="url(#svcGrad)" stroke-width="2"/>
                <path d="M14 20l2 2 6-6" stroke="url(#svcGrad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M20 8c-1.5 0-2.7.7-2.7 2.1 0 .9.5 1.5 1.2 1.9-.3.2-.7.6-.9 1.1-.2.5-.1.9.2 1.2.3.3.7.3 1.1.2.4-.1.6-.5.7-.7h.4c.1.2.4.6.7.7.4.1.8.1 1.1-.2.3-.3.4-.7.2-1.2-.2-.5-.6-.9-.9-1.1.7-.4 1.2-1 1.2-1.9 0-1.4-1.2-2.1-2.7-2.1z" fill="url(#svcGrad)" transform="translate(0,6) scale(1.3)"/>
                <defs>
                    <linearGradient id="svcGrad" x1="0" y1="0" x2="40" y2="40">
                        <stop stop-color="#3D8B63"/>
                        <stop offset="1" stop-color="#C4724A"/>
                    </linearGradient>
                </defs>
            </svg>
            <h2>خدمات ما در <?= e(siteName()) ?></h2>
            <p class="section-subtitle">ما با عشق و دانش، بهترین خدمات را برای رشد و شکوفایی فرزندان شما فراهم میکنیم</p>
        </div>
        <div class="services-grid">
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc1)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10" stroke="url(#svc1)" stroke-width="2"/><defs><linearGradient id="svc1" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
                </div>
                <h3>محیط امن و مطمئن</h3>
                <p>امنیت فرزند شما اولویت اول ماست. مهد کودک ما به سیستمهای امنیتی مدرن و کارکنان آموزشدیده مجهز است</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc2)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r="0.5" fill="url(#svc2)"/><circle cx="17.5" cy="10.5" r="0.5" fill="url(#svc2)"/><circle cx="8.5" cy="7.5" r="0.5" fill="url(#svc2)"/><circle cx="6.5" cy="12.5" r="0.5" fill="url(#svc2)"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 011.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/><defs><linearGradient id="svc2" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
                </div>
                <h3>یادگیری خلاقانه</h3>
                <p>خلاقیت کودکان را از طریق هنر، موسیقی و فعالیتهای آموزشی مبتنی بر بازی پرورش میدهیم</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc3)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/><defs><linearGradient id="svc3" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
                </div>
                <h3>وعدههای غذایی سالم</h3>
                <p>غذاهای مقوی و خوشمزه به صورت روزانه و تازه تهیه میشود تا رشد و سلامت کودک شما را تضمین کند</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc4)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><line x1="12" y1="7" x2="12" y2="13"/><line x1="9" y1="10" x2="15" y2="10"/><defs><linearGradient id="svc4" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
                </div>
                <h3>برنامه آموزشی استاندارد</h3>
                <p>برنامههای آموزشی ما مطابق با استانداردهای روز دنیا و متناسب با سن کودکان طراحی شده است</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc5)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/><defs><linearGradient id="svc5" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
                </div>
                <h3>مربیان مجرب و دلسوز</h3>
                <p>تیم مربیان ما با تحصیلات مرتبط و تجربه کافی، با عشق و مهربانی از کودکان مراقبت میکنند</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc6)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/><defs><linearGradient id="svc6" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
                </div>
                <h3>فعالیتهای هنری و موسیقی</h3>
                <p>کلاسهای موسیقی، نقاشی، نمایش خلاق و ورزشهای مناسب برای رشد همهجانبه کودکان برگزار میشود</p>
            </div>
        </div>
    </div>
</section>

<!-- Stats Section -->
<section class="section stats-section">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card fade-in">
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/><defs><linearGradient id="statGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
                <div class="stat-number" data-count="10" data-suffix="+">۱۰+</div>
                <div class="stat-label">سال تجربه</div>
            </div>
            <div class="stat-card fade-in">
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
                <div class="stat-number" data-count="500" data-suffix="+">۵۰۰+</div>
                <div class="stat-label">کودک فارغ‌التحصیل</div>
            </div>
            <div class="stat-card fade-in">
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                <div class="stat-number" data-count="20" data-suffix="+">۲۰+</div>
                <div class="stat-label">مربی متخصص</div>
            </div>
            <div class="stat-card fade-in">
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                <div class="stat-number" data-count="98" data-suffix="٪">۹۸٪</div>
                <div class="stat-label">رضایت والدین</div>
            </div>
        </div>
    </div>
</section>

<!-- Classes Section -->
<section class="section classes-section">
    <div class="container">
        <div class="section-header">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#clsGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/><defs><linearGradient id="clsGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
            <h2>کلاسهای ما</h2>
            <p class="section-subtitle">هر کلاس متناسب با سن و نیازهای رشدی کودکان طراحی شده است</p>
        </div>
        <div class="classes-grid">
            <div class="class-card fade-in">
                <div class="class-card-header class-gradient-pink">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#C4724A" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                </div>
                <div class="class-card-body">
                    <h3>کلاس نوباوه</h3>
                    <p class="class-age">۰ تا ۲ سال</p>
                    <p>مراقبت ویژه و آموزشهای حسی برای نوزادان و نوپایان دلبند شما</p>
                    <ul class="class-features">
                        <li>مراقبت فردی</li>
                        <li>تغذیه مناسب سن</li>
                        <li>برنامه خواب منظم</li>
                    </ul>
                </div>
            </div>
            <div class="class-card fade-in">
                <div class="class-card-header class-gradient-lavender">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#E8A838" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <div class="class-card-body">
                    <h3>کلاس خردسال</h3>
                    <p class="class-age">۲ تا ۴ سال</p>
                    <p>یادگیری مهارتهای اجتماعی و زبانی در محیطی شاد و پویا</p>
                    <ul class="class-features">
                        <li>آموزش زبان فارسی</li>
                        <li>بازیهای گروهی</li>
                        <li>فعالیتهای هنری</li>
                    </ul>
                </div>
            </div>
            <div class="class-card fade-in">
                <div class="class-card-header class-gradient-sky">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#5B8DB8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
                </div>
                <div class="class-card-body">
                    <h3>کلاس پیشدبستانی</h3>
                    <p class="class-age">۴ تا ۶ سال</p>
                    <p>آمادگی کامل برای ورود به مدرسه با برنامههای درسی و مهارتی</p>
                    <ul class="class-features">
                        <li>خواندن و نوشتن مقدماتی</li>
                        <li>ریاضیات پایه</li>
                        <li>مهارتهای زندگی</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Parent Testimonials Section -->
<section class="section testimonials-section" style="background: var(--paper); padding: var(--space-2xl) 0; border-top: 1px solid var(--paper-line); border-bottom: 1px solid var(--paper-line);">
    <div class="container">
        <div class="section-header text-center" style="margin-bottom: var(--space-xl);">
            <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark);">
                تجربه واقعی والدین روما
            </h2>
            <p style="color: var(--muted); font-size: var(--text-base);">نظرات خانواده‌هایی که به ما اعتماد کردند</p>
        </div>

        <div class="testimonials-slider">
            <div class="testimonial-card">
                <div>
                    <div class="testimonial-rating">
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                    </div>
                    <p class="testimonial-quote">
                        «چیزی که ما رو مطمئن کرد، این بود که هر روز عصر یه گزارش کوتاه از وضعیت غذا و خواب آیدا می‌گرفتیم، نه فقط یه پیام کلی که "روز خوبی داشت".»
                    </p>
                </div>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">م‌آ</div>
                    <div class="testimonial-info">
                        <h4>مادر آیدا</h4>
                        <span>کلاس خردسال</span>
                    </div>
                </div>
            </div>

            <div class="testimonial-card">
                <div>
                    <div class="testimonial-rating">
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                    </div>
                    <p class="testimonial-quote">
                        «وقتی برای اولین بار اومدیم بازدید، پارسا از همون در ورودی نمی‌خواست بره. این برای ما از هر تبلیغی مهم‌تر بود.»
                    </p>
                </div>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">پ‌پ</div>
                    <div class="testimonial-info">
                        <h4>پدر پارسا</h4>
                        <span>کلاس نوباوه</span>
                    </div>
                </div>
            </div>

            <div class="testimonial-card">
                <div>
                    <div class="testimonial-rating">
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                        <svg style="width:16px; height:16px;"><use href="<?= asset('assets/img/icons.svg#icon-star') ?>"/></svg>
                    </div>
                    <p class="testimonial-quote">
                        «پیش‌دبستانی روما مهارت‌های دست‌ورزی و اعتماد به‌نفس کیان رو عالی بالا برد. الان برای مدرسه کاملاً آماده و مشتاقه.»
                    </p>
                </div>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">م‌ک</div>
                    <div class="testimonial-info">
                        <h4>مادر کیان</h4>
                        <span>پیش‌دبستانی</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Accordion Section -->
<section class="section faq-section" style="padding: var(--space-2xl) 0;">
    <div class="container" style="max-width: 800px;">
        <div class="section-header text-center" style="margin-bottom: var(--space-xl);">
            <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark);">
                پرسش‌های متداول والدین
            </h2>
            <p style="color: var(--muted); font-size: var(--text-base);">پاسخ به سوالاتی که اکثر والدین قبل از ثبت‌نام می‌پرسند</p>
        </div>

        <div class="faq-accordion">
            <details class="faq-item">
                <summary class="faq-summary">
                    <span>ساعات نگهداری و فعالیت مهدکودک روما چگونه است؟</span>
                    <svg><use href="<?= asset('assets/img/icons.svg#icon-chevron-down') ?>"/></svg>
                </summary>
                <div class="faq-content">
                    مهدکودک روما روزهای شنبه تا چهارشنبه از ساعت ۰۷:۰۰ الی ۱۶:۳۰ و پنج‌شنبه‌ها از ساعت ۰۷:۰۰ الی ۱۳:۰۰ فعال است.
                </div>
            </details>

            <details class="faq-item">
                <summary class="faq-summary">
                    <span>آیا امکان ثبت‌نام نیم‌روزه وجود دارد؟</span>
                    <svg><use href="<?= asset('assets/img/icons.svg#icon-chevron-down') ?>"/></svg>
                </summary>
                <div class="faq-content">
                    بله، والدین عزیز می‌توانند با توجه به شرایط کاری خود، ثبت‌نام نیم‌روزه (۰۷:۰۰ الی ۱۲:۳۰) یا تمام‌روزه را انتخاب نمایند.
                </div>
            </details>

            <details class="faq-item">
                <summary class="faq-summary">
                    <span>غذای کودکانی که آلرژی یا رژیم خاص دارند چطور مدیریت می‌شود؟</span>
                    <svg><use href="<?= asset('assets/img/icons.svg#icon-chevron-down') ?>"/></svg>
                </summary>
                <div class="faq-content">
                    در زمان ثبت‌نام، فرم حساسیت‌های غذایی دریافت می‌شود. آشپزخانه مهد منوی جداگانه و مشخصی طبق نظر مشاور تغذیه برای کودکان دارای آلرژی آماده می‌کند.
                </div>
            </details>

            <details class="faq-item">
                <summary class="faq-summary">
                    <span>چگونه می‌توانم گزارش روزانه فرزندم را مشاهده کنم؟</span>
                    <svg><use href="<?= asset('assets/img/icons.svg#icon-chevron-down') ?>"/></svg>
                </summary>
                <div class="faq-content">
                    از طریق <a href="<?= url('login.php') ?>" style="color: var(--primary); font-weight: 700;">پنل والدین روما</a>، وضعیت تغذیه، ساعت خواب، فعالیت‌های کلاسی و گزارش مربی به‌صورت روزانه قابل مشاهده است.
                </div>
            </details>

            <details class="faq-item">
                <summary class="faq-summary">
                    <span>مدارک لازم برای ثبت‌نام اولیه چیست؟</span>
                    <svg><use href="<?= asset('assets/img/icons.svg#icon-chevron-down') ?>"/></svg>
                </summary>
                <div class="faq-content">
                    کپی شناسنامه کودک و والدین، ۴ قطعه عکس ۳x۴، کارت واکسیناسیون کامل و فرم معاینه بهداشت اولیه‌الزامی است.
                </div>
            </details>
        </div>
    </div>
</section>

<!-- News Section -->
<?php if ($latestNews !== []): ?>
<section class="section news-section">
    <div class="container">
        <div class="section-header">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#newsGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/><defs><linearGradient id="newsGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
            <h2>آخرین اخبار و رویدادها</h2>
        </div>
        <div class="news-grid">
            <?php foreach ($latestNews as $newsItem): ?>
                <article class="news-card fade-in">
                    <?php if (!empty($newsItem['image'])): ?>
                        <div class="news-card-image">
                            <img src="<?= e(url($newsItem['image'])) ?>" alt="<?= e($newsItem['title']) ?>" loading="lazy">
                        </div>
                    <?php endif; ?>
                    <div class="news-card-body">
                        <time class="news-date" datetime="<?= e($newsItem['created_at']) ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?= e(shamsiDate($newsItem['created_at'])) ?>
                        </time>
                        <h3>
                            <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>"><?= e($newsItem['title']) ?></a>
                        </h3>
                        <p><?= e(homeNewsExcerpt($newsItem['content'])) ?></p>
                        <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>" class="news-read-more">ادامه مطلب
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>


<?php
// Roma Gallery
try {
    $gStmt = $pdo->prepare('SELECT id, title, caption, image FROM gallery_images WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 12');
    $gStmt->execute();
    $galleryItems = $gStmt->fetchAll();
} catch (Throwable $exception) { $galleryItems = []; }
?>
<?php if (!empty($galleryItems)): ?>
<!-- Gallery Section -->
<section class="section gallery-section">
    <div class="container">
        <div class="section-header text-center">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#galGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/><defs><linearGradient id="galGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#3D8B63"/><stop offset="1" stop-color="#C4724A"/></linearGradient></defs></svg>
            <h2>گالری تصاویر <?= e(siteName()) ?></h2>
            <p class="section-subtitle">لحظات شاد و به‌یادماندنی کودکان ما در فعالیت‌های روزانه</p>
        </div>

        <!-- Gallery Filter Buttons -->
        <div class="gallery-filters" id="galleryFilters" style="display: flex; gap: var(--space-xs); justify-content: center; flex-wrap: wrap; margin-bottom: var(--space-xl);">
            <button class="btn btn-primary btn-sm gallery-filter-btn is-active" data-filter="all">همه تصاویر</button>
            <button class="btn btn-outline btn-sm gallery-filter-btn" data-filter="classes">کلاس‌ها</button>
            <button class="btn btn-outline btn-sm gallery-filter-btn" data-filter="events">جشن‌ها و رویدادها</button>
            <button class="btn btn-outline btn-sm gallery-filter-btn" data-filter="play">فضای بازی</button>
        </div>

        <div class="gallery-grid" style="columns: 3 260px; column-gap: var(--space-md);">
            <?php foreach ($galleryItems as $gi): ?>
                <div class="gallery-item fade-in" data-gallery-item data-category="<?= e($gi['category'] ?? 'all') ?>" style="break-inside: avoid; margin-bottom: var(--space-md); position: relative; border-radius: var(--radius-lg); overflow: hidden; cursor: pointer;">
                    <img src="<?= e(url($gi['image'])) ?>" alt="<?= e($gi['title'] ?? '') ?>" loading="lazy" class="gallery-img" style="width: 100%; display: block; border-radius: var(--radius-lg);">
                    <div class="gallery-overlay">
                        <div class="gallery-overlay-content">
                            <svg class="gallery-zoom-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                            <?php if (!empty($gi['title'])): ?><span class="gallery-title"><?= e($gi['title']) ?></span><?php endif; ?>
                            <?php if (!empty($gi['caption'])): ?><span class="gallery-caption"><?= e($gi['caption']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Gallery Lightbox -->
<div class="gallery-lightbox" id="galleryLightbox" role="dialog" aria-label="گالری تصاویر">
    <button class="gallery-lightbox-close" aria-label="بستن">&times;</button>
    <button class="gallery-lightbox-prev" aria-label="قبلی">&#8249;</button>
    <button class="gallery-lightbox-next" aria-label="بعدی">&#8250;</button>
    <div class="gallery-lightbox-content">
        <img src="" alt="" class="gallery-lightbox-img">
        <div class="gallery-lightbox-info"><h3 class="gallery-lightbox-title"></h3><p class="gallery-lightbox-caption"></p></div>
    </div>
</div>
<script>
(function(){
    var items=document.querySelectorAll("[data-gallery-item]"),lb=document.getElementById("galleryLightbox");
    if(!lb||!items.length)return;
    var img=lb.querySelector(".gallery-lightbox-img"),ttl=lb.querySelector(".gallery-lightbox-title"),cap=lb.querySelector(".gallery-lightbox-caption");
    var close=lb.querySelector(".gallery-lightbox-close"),prev=lb.querySelector(".gallery-lightbox-prev"),next=lb.querySelector(".gallery-lightbox-next");
    var idx=0;
    function show(i){idx=i;var el=items[i];img.src=el.querySelector("img").src;ttl.textContent=el.querySelector(".gallery-title")?el.querySelector(".gallery-title").textContent:"";cap.textContent=el.querySelector(".gallery-caption")?el.querySelector(".gallery-caption").textContent:"";lb.classList.add("active");document.body.style.overflow="hidden";}
    function hide(){lb.classList.remove("active");document.body.style.overflow="";}
    items.forEach(function(el,i){el.addEventListener("click",function(){show(i);});});
    close.addEventListener("click",hide);
    lb.addEventListener("click",function(e){if(e.target===lb)hide();});
    prev.addEventListener("click",function(e){e.stopPropagation();show((idx-1+items.length)%items.length);});
    next.addEventListener("click",function(e){e.stopPropagation();show((idx+1)%items.length);});
    document.addEventListener("keydown",function(e){if(!lb.classList.contains("active"))return;if(e.key==="Escape")hide();if(e.key==="ArrowLeft")show((idx+1)%items.length);if(e.key==="ArrowRight")show((idx-1+items.length)%items.length);});

    // Gallery Filter Handler
    var filterBtns = document.querySelectorAll(".gallery-filter-btn");
    filterBtns.forEach(function(btn){
        btn.addEventListener("click", function(){
            var cat = btn.getAttribute("data-filter");
            filterBtns.forEach(function(b){ b.classList.remove("btn-primary", "is-active"); b.classList.add("btn-outline"); });
            btn.classList.remove("btn-outline");
            btn.classList.add("btn-primary", "is-active");

            items.forEach(function(item){
                var itemCat = item.getAttribute("data-category");
                if (cat === "all" || itemCat === cat || !itemCat) {
                    item.style.display = "block";
                } else {
                    item.style.display = "none";
                }
            });
        });
    });
})();
</script>
<?php endif; ?>

<!-- About Us Teaser Section -->
<section class="section about-story-section">
    <div class="container">
        <div class="about-story-card fade-in" style="background: var(--white); border-radius: var(--radius-xl); padding: var(--space-xl); box-shadow: var(--shadow-md);">
            <div class="about-story-grid">
                <div class="about-story-content">
                    <span class="about-story-badge">📖 درباره روما</span>
                    <h2>روایتی از عشق، امنیت و رشد شکوفه‌های فردا</h2>
                    <p>مهد کودک <?= e(siteName()) ?> از سال ۱۳۹۵ با هدف ایجاد فضایی گرم، شاداب و امن برای پرورش استعدادها و توانمندی‌های کودکان شروع به کار کرد. ما باور داریم که سال‌های ابتدایی زندگی، شالوده‌ساز اصلی شخصیت و آینده فرزندان شماست.</p>
                    <div style="margin-top: var(--space-lg);">
                        <a href="<?= e(url('page.php?slug=about')) ?>" class="btn btn-primary btn-lg">
                            داستان کامل ما و آشنایی با روما ←
                        </a>
                    </div>
                </div>
                <div class="about-image-wrap">
                    <img src="<?= e(url('assets/uploads/gallery/about-story.jpg')) ?>?v=<?= filemtime(__DIR__ . '/assets/uploads/gallery/about-story.jpg') ?>" alt="داستان برند <?= e(siteName()) ?>" loading="lazy" decoding="async" width="800" height="533" onerror="this.onerror=null; this.src='<?= e(url('assets/img/about-story.jpg')) ?>?v=<?= filemtime(__DIR__ . '/assets/img/about-story.jpg') ?>';">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Newsletter Section -->
<section class="section newsletter-section">
    <div class="container">
        <div class="newsletter-card fade-in">
            <div class="newsletter-content">
                <div class="newsletter-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <h2>عضویت در خبرنامه <?= e(siteName()) ?></h2>
                <p>از آخرین اطلاعیه‌ها، برنامه‌های آموزشی، مقالات تربیتی و رویدادهای مهد باخبر شوید.</p>
                <form class="newsletter-form" id="newsletterForm" onsubmit="handleNewsletterSubmit(event)">
                    <input type="text" name="name" class="form-control" placeholder="نام و نام خانوادگی" required aria-label="نام و نام خانوادگی">
                    <input type="email" name="email" class="form-control" placeholder="آدرس ایمیل شما" required aria-label="آدرس ایمیل">
                    <button type="submit" class="btn btn-primary">عضویت در خبرنامه</button>
                </form>
                <div class="newsletter-toast" id="newsletterToast"></div>
            </div>
        </div>
    </div>
</section>
<script>
function handleNewsletterSubmit(e) {
    e.preventDefault();
    var form = e.target;
    var name = form.name.value.trim();
    var email = form.email.value.trim();
    var toast = document.getElementById('newsletterToast');

    if (!name || !email) {
        toast.className = 'newsletter-toast error';
        toast.textContent = 'لطفاً تمامی فیلدها را وارد کنید.';
        return;
    }

    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'در حال ثبت...';

    setTimeout(function() {
        toast.className = 'newsletter-toast success';
        toast.textContent = 'با تشکر از شما ' + name + ' عزیز! ثبت‌نام شما در خبرنامه با موفقیت انجام شد.';
        form.reset();
        btn.disabled = false;
        btn.textContent = 'عضویت در خبرنامه';
        setTimeout(function() { toast.style.display = 'none'; }, 6000);
    }, 800);
}
</script>

<!-- Shared Footer CTA -->
<?php require __DIR__ . '/templates/cta-section.php'; ?>


<?php require_once __DIR__ . '/templates/footer.php'; ?>
