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
        'SELECT id, title, image FROM slides ORDER BY sort_order ASC, created_at DESC'
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

function homeNewsDate(string $date): string
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    $months = [
        'January' => 'ژانویه',
        'February' => 'فوریه',
        'March' => 'مارس',
        'April' => 'آوریل',
        'May' => 'می',
        'June' => 'ژوئن',
        'July' => 'ژوئیه',
        'August' => 'اوت',
        'September' => 'سپتامبر',
        'October' => 'اکتبر',
        'November' => 'نوامبر',
        'December' => 'دسامبر',
    ];
    $day = (int) date('j', $timestamp);
    $monthEn = date('F', $timestamp);
    $year = (int) date('Y', $timestamp);
    $monthFa = $months[$monthEn] ?? $monthEn;

    $persianDigits = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                      '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

    return strtr($day . ' ' . $monthFa . ' ' . $year, $persianDigits);
}

$pageTitle = siteName();
require_once __DIR__ . '/templates/header.php';
?>

<!-- Hero Slider -->
<section class="hero-slider" aria-label="تصاویر برگزیده مهد کودک <?= e(siteName()) ?>">
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
                                <stop stop-color="#E879B5"/>
                                <stop offset="1" stop-color="#C8A8E9"/>
                            </linearGradient>
                        </defs>
                    </svg>
                    <h1>به <?= e(siteName()) ?> خوش آمدید</h1>
                    <p>جایی که کوچولوهای شما با عشق و مهربانی بزرگ میشوند</p>
                    <p class="hero-fallback-sub">محیطی امن، شاد و پر از یادگیری برای فرزندان دلبندتان</p>
                    <div class="hero-fallback-actions">
                        <a href="<?= e(url('page.php?slug=about')) ?>" class="btn btn-primary btn-lg">آشنایی با ما</a>
                        <a href="<?= e(url('login.php')) ?>" class="btn btn-outline btn-lg">ثبتنام والدین</a>
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

<!-- Services Section -->
<section class="section services-section">
    <div class="container">
        <div class="section-header">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="19" stroke="url(#svcGrad)" stroke-width="2"/>
                <path d="M14 20l2 2 6-6" stroke="url(#svcGrad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M20 8c-1.5 0-2.7.7-2.7 2.1 0 .9.5 1.5 1.2 1.9-.3.2-.7.6-.9 1.1-.2.5-.1.9.2 1.2.3.3.7.3 1.1.2.4-.1.6-.5.7-.7h.4c.1.2.4.6.7.7.4.1.8.1 1.1-.2.3-.3.4-.7.2-1.2-.2-.5-.6-.9-.9-1.1.7-.4 1.2-1 1.2-1.9 0-1.4-1.2-2.1-2.7-2.1z" fill="url(#svcGrad)" transform="translate(0,6) scale(1.3)"/>
                <defs>
                    <linearGradient id="svcGrad" x1="0" y1="0" x2="40" y2="40">
                        <stop stop-color="#E879B5"/>
                        <stop offset="1" stop-color="#C8A8E9"/>
                    </linearGradient>
                </defs>
            </svg>
            <h2>خدمات ما در <?= e(siteName()) ?></h2>
            <p class="section-subtitle">ما با عشق و دانش، بهترین خدمات را برای رشد و شکوفایی فرزندان شما فراهم میکنیم</p>
        </div>
        <div class="services-grid">
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc1)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10" stroke="url(#svc1)" stroke-width="2"/><defs><linearGradient id="svc1" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
                </div>
                <h3>محیط امن و مطمئن</h3>
                <p>امنیت فرزند شما اولویت اول ماست. مهد کودک ما به سیستمهای امنیتی مدرن و کارکنان آموزشدیده مجهز است</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc2)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r="0.5" fill="url(#svc2)"/><circle cx="17.5" cy="10.5" r="0.5" fill="url(#svc2)"/><circle cx="8.5" cy="7.5" r="0.5" fill="url(#svc2)"/><circle cx="6.5" cy="12.5" r="0.5" fill="url(#svc2)"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 011.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/><defs><linearGradient id="svc2" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
                </div>
                <h3>یادگیری خلاقانه</h3>
                <p>خلاقیت کودکان را از طریق هنر، موسیقی و فعالیتهای آموزشی مبتنی بر بازی پرورش میدهیم</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc3)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/><defs><linearGradient id="svc3" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
                </div>
                <h3>وعدههای غذایی سالم</h3>
                <p>غذاهای مقوی و خوشمزه به صورت روزانه و تازه تهیه میشود تا رشد و سلامت کودک شما را تضمین کند</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc4)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><line x1="12" y1="7" x2="12" y2="13"/><line x1="9" y1="10" x2="15" y2="10"/><defs><linearGradient id="svc4" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
                </div>
                <h3>برنامه آموزشی استاندارد</h3>
                <p>برنامههای آموزشی ما مطابق با استانداردهای روز دنیا و متناسب با سن کودکان طراحی شده است</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc5)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/><defs><linearGradient id="svc5" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
                </div>
                <h3>مربیان مجرب و دلسوز</h3>
                <p>تیم مربیان ما با تحصیلات مرتبط و تجربه کافی، با عشق و مهربانی از کودکان مراقبت میکنند</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon-wrap">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="url(#svc6)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/><defs><linearGradient id="svc6" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
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
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/><defs><linearGradient id="statGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
                <div class="stat-number">۱۰+</div>
                <div class="stat-label">سال تجربه</div>
            </div>
            <div class="stat-card fade-in">
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
                <div class="stat-number">۵۰۰+</div>
                <div class="stat-label">کودک فارغالتحصیل</div>
            </div>
            <div class="stat-card fade-in">
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                <div class="stat-number">۲۰+</div>
                <div class="stat-label">مربی متخصص</div>
            </div>
            <div class="stat-card fade-in">
                <svg class="stat-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#statGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                <div class="stat-number">۹۸٪</div>
                <div class="stat-label">رضایت والدین</div>
            </div>
        </div>
    </div>
</section>

<!-- Classes Section -->
<section class="section classes-section">
    <div class="container">
        <div class="section-header">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#clsGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/><defs><linearGradient id="clsGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
            <h2>کلاسهای ما</h2>
            <p class="section-subtitle">هر کلاس متناسب با سن و نیازهای رشدی کودکان طراحی شده است</p>
        </div>
        <div class="classes-grid">
            <div class="class-card fade-in">
                <div class="class-card-header class-gradient-pink">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#C76299" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
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
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#A88AD1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
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
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#5DADE2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
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

<!-- News Section -->
<?php if ($latestNews !== []): ?>
<section class="section news-section">
    <div class="container">
        <div class="section-header">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#newsGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/><defs><linearGradient id="newsGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
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
                            <?= e(homeNewsDate($newsItem['created_at'])) ?>
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

<!-- Testimonials Section -->
<section class="section testimonials-section">
    <div class="container">
        <div class="section-header">
            <svg class="section-icon-svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="url(#testGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/><defs><linearGradient id="testGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#E879B5"/><stop offset="1" stop-color="#C8A8E9"/></linearGradient></defs></svg>
            <h2>نظر والدین درباره ما</h2>
        </div>
        <div class="testimonials-grid">
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars" aria-label="۵ ستاره">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                <blockquote class="testimonial-text">
                    «<?= e(siteName()) ?> نعمت بزرگی برای خانواده ما بوده است. کارکنان مهربان، حرفهای و واقعاً به رشد هر کودک اهمیت میدهند. پسرم هر روز با اشتیاق به مهد کودک میرود.»
                </blockquote>
                <cite class="testimonial-author">سمیه احمدی</cite>
            </div>
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars" aria-label="۵ ستاره">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                <blockquote class="testimonial-text">
                    «دخترم هر روز از مهد کودک صحبت میکند! فعالیتهای خلاقانه و محیط گرم باعث شده تا او پیشرفت چشمگیری داشته باشد. ممنون از تیم فوقالعاده شما.»
                </blockquote>
                <cite class="testimonial-author">مریم رضایی</cite>
            </div>
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars" aria-label="۵ ستاره">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#FFB800" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                <blockquote class="testimonial-text">
                    «به عنوان پدر دو کودک، آرامش خاطر من با حضور در <?= e(siteName()) ?> تضمین شده است. گزارشهای روزانه و دوربینهای مداربسته باعث شده همیشه در جریان باشم.»
                </blockquote>
                <cite class="testimonial-author">علی کریمی</cite>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="section cta-section">
    <div class="container">
        <div class="cta-box fade-in">
            <div class="cta-content">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                    <circle cx="24" cy="24" r="22" stroke="rgba(255,255,255,0.3)" stroke-width="2"/>
                    <path d="M24 14c-4 0-7 2-7 5.5 0 2.5 1.3 4 3 5.2-.8.5-1.9 1.6-2.3 2.8-.5 1.3-.3 2.4.5 3.2.8.8 1.9.8 2.8.5 1-.3 1.6-1.3 1.9-1.9h1.1c.3.6 1 1.6 1.9 1.9.9.3 2 .3 2.8-.5.8-.8 1-1.9.5-3.2-.4-1.2-1.5-2.3-2.3-2.8 1.7-1.2 3-2.7 3-5.2 0-3.5-3-5.5-7-5.5z" fill="rgba(255,255,255,0.9)"/>
                    <circle cx="21" cy="21.5" r="1.2" fill="white"/>
                    <circle cx="27" cy="21.5" r="1.2" fill="white"/>
                    <path d="M21 25c0 0 1.5 2 3 2s3-2 3-2" stroke="white" stroke-width="1" stroke-linecap="round" fill="none"/>
                </svg>
                <h2>فرزندتان را به خانواده <?= e(siteName()) ?> بسپارید</h2>
                <p>همین امروز برای ثبتنام و بازدید از مهد کودک اقدام کنید</p>
                <div class="cta-actions">
                    <a href="<?= e(url('login.php')) ?>" class="btn btn-white btn-lg">ثبتنام والدین</a>
                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', siteContactPhone())) ?>" class="btn btn-outline-white btn-lg">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                        تماس با ما (<?= e(siteContactPhone()) ?>)
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>


<?php require_once __DIR__ . '/templates/footer.php'; ?>