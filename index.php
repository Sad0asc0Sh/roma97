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
                    <span class="hero-fallback-icon">🌸</span>
                    <h1>به <?= e(siteName()) ?> خوش آمدید</h1>
                    <p>جایی که کوچولوهای شما با عشق و مهربانی بزرگ می‌شوند</p>
                    <p class="hero-fallback-sub">محیطی امن، شاد و پر از یادگیری برای فرزندان دلبندتان</p>
                    <div class="hero-fallback-actions">
                        <a href="<?= e(url('page.php?slug=about')) ?>" class="btn btn-primary btn-lg">آشنایی با ما</a>
                        <a href="<?= e(url('login.php')) ?>" class="btn btn-outline btn-lg">ثبت‌نام والدین</a>
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
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <button class="slider-nav slider-next" type="button" data-next aria-label="تصویر بعدی">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
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
            <span class="section-icon">🌷</span>
            <h2>خدمات ما در <?= e(siteName()) ?></h2>
            <p class="section-subtitle">ما با عشق و دانش، بهترین خدمات را برای رشد و شکوفایی فرزندان شما فراهم می‌کنیم</p>
        </div>
        <div class="services-grid">
            <div class="service-card fade-in">
                <div class="service-icon">
                    <span>🛡️</span>
                </div>
                <h3>محیط امن و مطمئن</h3>
                <p>امنیت فرزند شما اولویت اول ماست. مهد کودک ما به سیستم‌های امنیتی مدرن و کارکنان آموزش‌دیده مجهز است</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon">
                    <span>🎨</span>
                </div>
                <h3>یادگیری خلاقانه</h3>
                <p>خلاقیت کودکان را از طریق هنر، موسیقی و فعالیت‌های آموزشی مبتنی بر بازی پرورش می‌دهیم</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon">
                    <span>🍎</span>
                </div>
                <h3>وعده‌های غذایی سالم</h3>
                <p>غذاهای مقوی و خوشمزه به صورت روزانه و تازه تهیه می‌شود تا رشد و سلامت کودک شما را تضمین کند</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon">
                    <span>📚</span>
                </div>
                <h3>برنامه آموزشی استاندارد</h3>
                <p>برنامه‌های آموزشی ما مطابق با استانداردهای روز دنیا و متناسب با سن کودکان طراحی شده است</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon">
                    <span>👩‍🏫</span>
                </div>
                <h3>مربیان مجرب و دلسوز</h3>
                <p>تیم مربیان ما با تحصیلات مرتبط و تجربه کافی، با عشق و مهربانی از کودکان مراقبت می‌کنند</p>
            </div>
            <div class="service-card fade-in">
                <div class="service-icon">
                    <span>🎭</span>
                </div>
                <h3>فعالیت‌های هنری و موسیقی</h3>
                <p>کلاس‌های موسیقی، نقاشی، نمایش خلاق و ورزش‌های مناسب برای رشد همه‌جانبه کودکان برگزار می‌شود</p>
            </div>
        </div>
    </div>
</section>

<!-- Stats Section -->
<section class="section stats-section">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card fade-in">
                <div class="stat-icon">🌟</div>
                <div class="stat-number">۱۰+</div>
                <div class="stat-label">سال تجربه</div>
            </div>
            <div class="stat-card fade-in">
                <div class="stat-icon">🎓</div>
                <div class="stat-number">۵۰۰+</div>
                <div class="stat-label">کودک فارغ‌التحصیل</div>
            </div>
            <div class="stat-card fade-in">
                <div class="stat-icon">💝</div>
                <div class="stat-number">۲۰+</div>
                <div class="stat-label">مربی متخصص</div>
            </div>
            <div class="stat-card fade-in">
                <div class="stat-icon">😊</div>
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
            <span class="section-icon">🌈</span>
            <h2>کلاس‌های ما</h2>
            <p class="section-subtitle">هر کلاس متناسب با سن و نیازهای رشدی کودکان طراحی شده است</p>
        </div>
        <div class="classes-grid">
            <div class="class-card fade-in">
                <div class="class-card-header class-gradient-pink">
                    <div class="class-emoji">👶</div>
                </div>
                <div class="class-card-body">
                    <h3>کلاس نوباوه</h3>
                    <p class="class-age">۰ تا ۲ سال</p>
                    <p>مراقبت ویژه و آموزش‌های حسی برای نوزادان و نوپایان دلبند شما</p>
                    <ul class="class-features">
                        <li>مراقبت فردی</li>
                        <li>تغذیه مناسب سن</li>
                        <li>برنامه خواب منظم</li>
                    </ul>
                </div>
            </div>
            <div class="class-card fade-in">
                <div class="class-card-header class-gradient-lavender">
                    <div class="class-emoji">🧒</div>
                </div>
                <div class="class-card-body">
                    <h3>کلاس خردسال</h3>
                    <p class="class-age">۲ تا ۴ سال</p>
                    <p>یادگیری مهارت‌های اجتماعی و زبانی در محیطی شاد و پویا</p>
                    <ul class="class-features">
                        <li>آموزش زبان فارسی</li>
                        <li>بازی‌های گروهی</li>
                        <li>فعالیت‌های هنری</li>
                    </ul>
                </div>
            </div>
            <div class="class-card fade-in">
                <div class="class-card-header class-gradient-sky">
                    <div class="class-emoji">👦</div>
                </div>
                <div class="class-card-body">
                    <h3>کلاس پیش‌دبستانی</h3>
                    <p class="class-age">۴ تا ۶ سال</p>
                    <p>آمادگی کامل برای ورود به مدرسه با برنامه‌های درسی و مهارتی</p>
                    <ul class="class-features">
                        <li>خواندن و نوشتن مقدماتی</li>
                        <li>ریاضیات پایه</li>
                        <li>مهارت‌های زندگی</li>
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
            <span class="section-icon">📰</span>
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
                        <time class="news-date" datetime="<?= e($newsItem['created_at']) ?>"><?= e(homeNewsDate($newsItem['created_at'])) ?></time>
                        <h3>
                            <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>"><?= e($newsItem['title']) ?></a>
                        </h3>
                        <p><?= e(homeNewsExcerpt($newsItem['content'])) ?></p>
                        <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>" class="news-read-more">ادامه مطلب <span>←</span></a>
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
            <span class="section-icon">💕</span>
            <h2>نظر والدین درباره ما</h2>
        </div>
        <div class="testimonials-grid">
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars" aria-label="۵ ستاره">⭐⭐⭐⭐⭐</div>
                <blockquote class="testimonial-text">
                    «<?= e(siteName()) ?> نعمت بزرگی برای خانواده ما بوده است. کارکنان مهربان، حرفه‌ای و واقعاً به رشد هر کودک اهمیت می‌دهند. پسرم هر روز با اشتیاق به مهد کودک می‌رود.»
                </blockquote>
                <cite class="testimonial-author">سمیه احمدی</cite>
            </div>
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars" aria-label="۵ ستاره">⭐⭐⭐⭐⭐</div>
                <blockquote class="testimonial-text">
                    «دخترم هر روز از مهد کودک صحبت می‌کند! فعالیت‌های خلاقانه و محیط گرم باعث شده تا او پیشرفت چشمگیری داشته باشد. ممنون از تیم فوق‌العاده شما.»
                </blockquote>
                <cite class="testimonial-author">مریم رضایی</cite>
            </div>
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars" aria-label="۵ ستاره">⭐⭐⭐⭐⭐</div>
                <blockquote class="testimonial-text">
                    «به عنوان پدر دو کودک، آرامش خاطر من با حضور در <?= e(siteName()) ?> تضمین شده است. گزارش‌های روزانه و دوربین‌های مداربسته باعث شده همیشه در جریان باشم.»
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
                <span class="cta-icon">🌸</span>
                <h2>فرزندتان را به خانواده <?= e(siteName()) ?> بسپارید</h2>
                <p>همین امروز برای ثبت‌نام و بازدید از مهد کودک اقدام کنید</p>
                <div class="cta-actions">
                    <a href="<?= e(url('login.php')) ?>" class="btn btn-white btn-lg">ثبت‌نام والدین</a>
                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', siteContactPhone())) ?>" class="btn btn-outline-white btn-lg">📞 تماس با ما (<?= e(siteContactPhone()) ?>)</a>
                </div>
            </div>
        </div>
    </div>
</section>


<?php require_once __DIR__ . '/templates/footer.php'; ?>
