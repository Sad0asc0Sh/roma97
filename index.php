<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$slides       = [];
$latestNews   = [];
$galleryItems = [];

try {
    $pdo = getDb();

    $stmt = $pdo->query('SELECT id, title, subtitle, image FROM slides ORDER BY sort_order ASC, created_at DESC');
    $slides = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT id, title, content, image, category, created_at FROM news ORDER BY created_at DESC LIMIT 3');
    $latestNews = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT id, title, caption, image, category FROM gallery_images WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 12');
    $galleryItems = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
}

/**
 * Plain-text excerpt for the news teasers.
 */
function homeExcerpt(string $content, int $limit = 145): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '');

    if (function_exists('mb_strlen')) {
        return mb_strlen($plain, 'UTF-8') > $limit
            ? rtrim(mb_substr($plain, 0, $limit, 'UTF-8')) . '…'
            : $plain;
    }

    return strlen($plain) > $limit ? rtrim(substr($plain, 0, $limit)) . '…' : $plain;
}

$siteNameV = siteName();

$pageTitle = 'مهدکودک و پیش‌دبستانی ' . $siteNameV . ' | محیطی امن و شاد برای رشد کودک شما';
$pageDescription = 'مهدکودک ' . $siteNameV . ' با مجوز رسمی بهزیستی، مربیان مجرب، گزارش روزانه والدین و محیطی امن و خلاق'
    . ' برای کودکان ۶ ماه تا ۶ سال. همین حالا برای بازدید حضوری هماهنگ کنید.';

require_once __DIR__ . '/templates/header.php';

// The gallery filter set is derived from what actually exists in the DB.
$galleryCategories = [
    'activities' => 'فعالیت‌ها و کارگاه‌ها',
    'space'      => 'فضا و کلاس‌ها',
    'events'     => 'جشن‌ها و رویدادها',
];
$presentCategories = [];
foreach ($galleryItems as $g) {
    $cat = (string) ($g['category'] ?? '');
    if ($cat !== '' && isset($galleryCategories[$cat])) {
        $presentCategories[$cat] = $galleryCategories[$cat];
    }
}
?>

<?php /* ═══════════════ HERO ═══════════════ */ ?>
<section class="hero" aria-label="معرفی <?php echo e($siteNameV); ?>">
    <?php if ($slides === []): ?>
        <div class="hero hero--fallback">
            <div class="container hero__inner">
                <div class="hero__content">
                    <span class="eyebrow eyebrow-light hero__anim">
                        <?php echo icon('shield', 14); ?>
                        دارای مجوز رسمی بهزیستی
                    </span>
                    <h1 class="hero__title hero__anim">
                        به <?php echo e($siteNameV); ?> خوش آمدید
                    </h1>
                    <p class="hero__sub hero__anim">
                        جایی که کوچولوهای شما با عشق، امنیت و بازی بزرگ می‌شوند —
                        محیطی شاد و پر از یادگیری برای کودکان ۶ ماه تا ۶ سال.
                    </p>
                    <div class="hero__actions hero__anim">
                        <a class="btn btn-secondary btn-lg" href="<?php echo e(url('page.php?slug=contact')); ?>">
                            <?php echo icon('calendar', 19); ?>
                            رزرو بازدید حضوری
                        </a>
                        <a class="btn btn-light btn-lg" href="<?php echo e(url('page.php?slug=about')); ?>">
                            آشنایی با روما
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="hero__viewport" data-slider data-slider-interval="7000">
            <h1 class="sr-only">مهدکودک و پیش‌دبستانی <?php echo e($siteNameV); ?></h1>
            <span class="sr-only" role="status" aria-live="polite" data-slider-live></span>

            <?php foreach ($slides as $index => $slide): ?>
                <?php
                $isFirst = $index === 0;
                $title    = (string) $slide['title'];
                $subtitle = (string) ($slide['subtitle'] ?? '');
                ?>
                <div class="hero__slide<?php echo $isFirst ? ' is-active' : ''; ?>"
                     data-slide
                     aria-hidden="<?php echo $isFirst ? 'false' : 'true'; ?>"
                     aria-roledescription="اسلاید"
                     aria-label="<?php echo e(persianNumber((string) ($index + 1))) . ' از ' . e(persianNumber((string) count($slides))); ?>">
                    <div class="hero__media">
                        <img class="hero__img"
                             src="<?php echo e(url((string) $slide['image'])); ?>"
                             alt="<?php echo e($title); ?>"
                             width="1920" height="1072"
                             loading="<?php echo $isFirst ? 'eager' : 'lazy'; ?>"
                             decoding="<?php echo $isFirst ? 'sync' : 'async'; ?>"
                             <?php echo $isFirst ? 'fetchpriority="high"' : ''; ?>>
                        <div class="hero__scrim"></div>
                    </div>

                    <div class="container hero__inner">
                        <div class="hero__content">
                            <span class="eyebrow eyebrow-light hero__anim">
                                <?php echo icon('shield', 14); ?>
                                مجوز رسمی بهزیستی · ۱۰ سال تجربه
                            </span>
                            <p class="hero__title hero__anim"><?php echo e($title); ?></p>
                            <?php if ($subtitle !== ''): ?>
                                <p class="hero__sub hero__anim"><?php echo e($subtitle); ?></p>
                            <?php endif; ?>
                            <div class="hero__actions hero__anim">
                                <a class="btn btn-secondary btn-lg" href="<?php echo e(url('page.php?slug=contact')); ?>">
                                    <?php echo icon('calendar', 19); ?>
                                    رزرو بازدید حضوری
                                </a>
                                <a class="btn btn-light btn-lg" href="<?php echo e(url('page.php?slug=classes')); ?>">
                                    مشاهده کلاس‌ها
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (count($slides) > 1): ?>
                <button type="button" class="hero__nav hero__nav--prev" data-slide-prev aria-label="تصویر قبلی">
                    <?php echo icon('chevron-right', 20); ?>
                </button>
                <button type="button" class="hero__nav hero__nav--next" data-slide-next aria-label="تصویر بعدی">
                    <?php echo icon('chevron-left', 20); ?>
                </button>

                <div class="hero__dots" data-slider-dots role="tablist" aria-label="انتخاب تصویر">
                    <?php foreach ($slides as $index => $slide): ?>
                        <button type="button"
                                class="hero__dot<?php echo $index === 0 ? ' is-active' : ''; ?>"
                                data-slide-dot
                                role="tab"
                                aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                                tabindex="<?php echo $index === 0 ? '0' : '-1'; ?>"
                                aria-label="رفتن به تصویر <?php echo e(persianNumber((string) ($index + 1))); ?>"></button>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="hero__playpause" data-slide-toggle aria-pressed="true" aria-label="توقف پخش خودکار">
                    <span class="i-pause"><?php echo brandIcon('pause', 14); ?></span>
                    <span class="i-play"><?php echo brandIcon('play', 14); ?></span>
                </button>
            <?php endif; ?>

            <div class="hero__cue" aria-hidden="true">
                <span class="hero__cue-track"></span>
                <span>برای دیدن بیشتر اسکرول کنید</span>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php /* ═══════════════ TRUST STRIP ═══════════════ */ ?>
<div class="trust">
    <div class="container">
        <div class="trust__grid">
            <div class="trust__item">
                <span class="trust__icon"><?php echo icon('shield', 17); ?></span>
                <span>مجوز رسمی بهزیستی</span>
            </div>
            <div class="trust__item">
                <span class="trust__icon"><?php echo icon('award', 17); ?></span>
                <span>۱۰ سال فعالیت تخصصی</span>
            </div>
            <div class="trust__item">
                <span class="trust__icon"><?php echo icon('camera', 17); ?></span>
                <span>دوربین مداربسته کلاس‌ها</span>
            </div>
            <div class="trust__item">
                <span class="trust__icon"><?php echo icon('heart', 17); ?></span>
                <span>بیمه حوادث کامل کودکان</span>
            </div>
        </div>
    </div>
</div>

<?php /* ═══════════════ SERVICES ═══════════════ */ ?>
<section class="section" id="services">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow">
                <?php echo icon('sparkles', 14); ?>
                خدمات روما
            </span>
            <h2 class="section-head__title">
                هر آنچه یک کودک برای <span class="u-mark">رشد کامل</span> نیاز دارد
            </h2>
            <p class="section-head__sub">
                از تغذیه و خواب تا خلاقیت و مهارت‌های اجتماعی — برنامه روما بر پایه علم رشد کودک
                و با نگاه به آرامش خیال شما طراحی شده است.
            </p>
        </div>

        <div class="feature-grid" data-reveal-group data-reveal-step="80">
            <article class="card card-hover card-glow feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('shield', 27); ?></span>
                <h3 class="feature__title">محیط امن و مطمئن</h3>
                <p class="feature__text">
                    ورود و خروج با کد اختصاصی، دوربین مداربسته در تمام کلاس‌ها، کف‌پوش ضربه‌گیر
                    و مهار تمام کمدها به دیوار. ایمنی فرزند شما اتفاقی نیست — طراحی‌شده است.
                </p>
            </article>

            <article class="card card-hover card-glow feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('brain', 27); ?></span>
                <h3 class="feature__title">یادگیری از دل بازی</h3>
                <p class="feature__text">
                    کودکان با حفظ‌کردن رشد نمی‌کنند. در روما مفاهیم ریاضی، زبان و علوم از طریق
                    بازی هدفمند، کارگاه هنر و آزمایش‌های ساده تجربه می‌شوند.
                </p>
            </article>

            <article class="card card-hover card-glow feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('utensils', 27); ?></span>
                <h3 class="feature__title">تغذیه تازه و فصلی</h3>
                <p class="feature__text">
                    منوی هفتگی زیر نظر کارشناس تغذیه، پخت روزانه در آشپزخانه مجموعه و منوی
                    جداگانه برای کودکان دارای آلرژی یا رژیم خاص.
                </p>
            </article>

            <article class="card card-hover card-glow feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('message', 27); ?></span>
                <h3 class="feature__title">شفافیت کامل با والدین</h3>
                <p class="feature__text">
                    هر روز عصر گزارش دقیق تغذیه، ساعت خواب، حال‌وهوا و فعالیت‌های فرزندتان را
                    در پنل اختصاصی والدین می‌بینید — نه یک پیام کلی.
                </p>
            </article>

            <article class="card card-hover card-glow feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('users', 27); ?></span>
                <h3 class="feature__title">مربیان مجرب و دلسوز</h3>
                <p class="feature__text">
                    تمام مربیان دارای مدرک مرتبط، گواهی مربی‌گری بهزیستی و کارت بهداشت‌اند و
                    سالانه در کارگاه‌های روانشناسی کودک شرکت می‌کنند.
                </p>
            </article>

            <article class="card card-hover card-glow feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('palette', 27); ?></span>
                <h3 class="feature__title">هنر، موسیقی و حرکت</h3>
                <p class="feature__text">
                    کارگاه سفالگری، نقاشی، موسیقی کودک، نمایش خلاق و بازی‌های حرکتی — برای
                    پرورش اعتماد به‌نفس و مهارت دست‌ورزی.
                </p>
            </article>
        </div>
    </div>
</section>

<?php /* ═══════════════ STATS ═══════════════ */ ?>
<section class="section stats">
    <div class="container">
        <div class="stats__grid" data-reveal-group data-reveal-step="90">
            <div class="stat" data-reveal="zoom">
                <span class="stat__icon"><?php echo icon('award', 23); ?></span>
                <span class="stat__num" data-count="10" data-suffix="+">۱۰+</span>
                <span class="stat__label">سال تجربه تخصصی</span>
            </div>
            <div class="stat" data-reveal="zoom">
                <span class="stat__icon"><?php echo icon('cap', 23); ?></span>
                <span class="stat__num" data-count="500" data-suffix="+">۵۰۰+</span>
                <span class="stat__label">کودک فارغ‌التحصیل</span>
            </div>
            <div class="stat" data-reveal="zoom">
                <span class="stat__icon"><?php echo icon('users', 23); ?></span>
                <span class="stat__num" data-count="20" data-suffix="+">۲۰+</span>
                <span class="stat__label">مربی و کارشناس</span>
            </div>
            <div class="stat" data-reveal="zoom">
                <span class="stat__icon"><?php echo icon('heart', 23); ?></span>
                <span class="stat__num" data-count="98" data-suffix="٪">۹۸٪</span>
                <span class="stat__label">رضایت والدین</span>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ CLASSES ═══════════════ */ ?>
<section class="section section-paper" id="classes">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow eyebrow-clay">
                <?php echo icon('cap', 14); ?>
                گروه‌های سنی
            </span>
            <h2 class="section-head__title">
                برای هر سن، یک <span class="u-mark">برنامه متفاوت</span>
            </h2>
            <p class="section-head__sub">
                نیاز یک نوپای یک‌ساله با کودک پنج‌ساله زمین تا آسمان تفاوت دارد. سه کلاس روما
                با نسبت مربی، فضا و برنامه روزانه مستقل طراحی شده‌اند.
            </p>
        </div>

        <div class="class-grid" data-reveal-group data-reveal-step="110">
            <article class="card card-hover class-card" data-reveal="rise">
                <div class="class-card__head class-card__head--green">
                    <span class="class-card__icon"><?php echo icon('baby', 46); ?></span>
                </div>
                <div class="class-card__body">
                    <h3 class="class-card__title">کلاس نوباوه</h3>
                    <span class="badge badge-primary">۶ ماه تا ۲ سال · نسبت مربی ۱ به ۴</span>
                    <p class="class-card__text">
                        تمرکز بر حس امنیت، دلبستگی ایمن و رشد حرکتی. برنامه خواب و تغذیه هر کودک
                        جداگانه و مطابق عادت خانه تنظیم می‌شود.
                    </p>
                    <ul class="class-card__list">
                        <li><?php echo icon('check', 17); ?><span>مراقبت فردی و ماساژ کودک</span></li>
                        <li><?php echo icon('check', 17); ?><span>تحریک حسی با اسباب‌بازی ایمن</span></li>
                        <li><?php echo icon('check', 17); ?><span>گزارش ساعتی خواب و تغذیه</span></li>
                    </ul>
                    <div class="meter">
                        <div class="meter__head">
                            <span>ظرفیت تکمیل‌شده</span>
                            <span class="text-secondary">۲ جای خالی از ۱۰</span>
                        </div>
                        <div class="meter__track">
                            <div class="meter__fill" data-meter="80%" style="--w:80%"></div>
                        </div>
                    </div>
                    <div class="class-card__foot">
                        <a class="btn btn-outline btn-sm btn-block" href="<?php echo e(url('page.php?slug=classes')); ?>">
                            جزئیات و برنامه روزانه
                        </a>
                    </div>
                </div>
            </article>

            <article class="card card-hover class-card" data-reveal="rise">
                <div class="class-card__head class-card__head--clay">
                    <span class="class-card__icon"><?php echo icon('puzzle', 46); ?></span>
                </div>
                <div class="class-card__body">
                    <h3 class="class-card__title">کلاس خردسال</h3>
                    <span class="badge badge-secondary">۲ تا ۴ سال · نسبت مربی ۱ به ۶</span>
                    <p class="class-card__text">
                        سن طلایی زبان و مهارت اجتماعی. کودکان یاد می‌گیرند نوبت بگیرند، احساسشان
                        را با کلمه بگویند و در گروه بازی کنند.
                    </p>
                    <ul class="class-card__list">
                        <li><?php echo icon('check', 17); ?><span>غنی‌سازی واژگان و قصه‌گویی</span></li>
                        <li><?php echo icon('check', 17); ?><span>بازی‌های گروهی و نوبت‌گیری</span></li>
                        <li><?php echo icon('check', 17); ?><span>کارگاه هنر، سفال و موسیقی</span></li>
                    </ul>
                    <div class="meter">
                        <div class="meter__head">
                            <span>ظرفیت تکمیل‌شده</span>
                            <span class="text-secondary">۴ جای خالی از ۱۴</span>
                        </div>
                        <div class="meter__track">
                            <div class="meter__fill" data-meter="71%" style="--w:71%"></div>
                        </div>
                    </div>
                    <div class="class-card__foot">
                        <a class="btn btn-outline btn-sm btn-block" href="<?php echo e(url('page.php?slug=classes')); ?>">
                            جزئیات و برنامه روزانه
                        </a>
                    </div>
                </div>
            </article>

            <article class="card card-hover class-card" data-reveal="rise">
                <div class="class-card__head class-card__head--sky">
                    <span class="class-card__icon"><?php echo icon('cap', 46); ?></span>
                </div>
                <div class="class-card__body">
                    <h3 class="class-card__title">پیش‌دبستانی</h3>
                    <span class="badge badge-info">۴ تا ۶ سال · نسبت مربی ۱ به ۷</span>
                    <p class="class-card__text">
                        آماده‌سازی واقعی برای مدرسه: تمرکز، دست‌ورزی، سوادآموزی اولیه و
                        اعتماد به‌نفس — بدون فشار و مشق شب.
                    </p>
                    <ul class="class-card__list">
                        <li><?php echo icon('check', 17); ?><span>خواندن و نوشتن مقدماتی</span></li>
                        <li><?php echo icon('check', 17); ?><span>مفاهیم ریاضی و حل مسئله</span></li>
                        <li><?php echo icon('check', 17); ?><span>مهارت‌های زندگی و استقلال</span></li>
                    </ul>
                    <div class="meter">
                        <div class="meter__head">
                            <span>ظرفیت تکمیل‌شده</span>
                            <span class="text-secondary">۳ جای خالی از ۱۵</span>
                        </div>
                        <div class="meter__track">
                            <div class="meter__fill" data-meter="80%" style="--w:80%"></div>
                        </div>
                    </div>
                    <div class="class-card__foot">
                        <a class="btn btn-outline btn-sm btn-block" href="<?php echo e(url('page.php?slug=classes')); ?>">
                            جزئیات و برنامه روزانه
                        </a>
                    </div>
                </div>
            </article>
        </div>
    </div>
</section>

<?php /* ═══════════════ A DAY AT ROOMA ═══════════════ */ ?>
<section class="section">
    <div class="container">
        <div class="split split--wide-start">
            <div class="split__content" data-reveal="start">
                <span class="eyebrow">
                    <?php echo icon('clock', 14); ?>
                    یک روز در روما
                </span>
                <h2 class="split__title">از لحظه خداحافظی تا آغوش دوباره شما</h2>
                <p class="split__text">
                    روز کودک شما در روما ساختار دارد، اما خشک نیست. ریتم ثابتِ صبحانه، بازی،
                    یادگیری و استراحت به کودک حس امنیت می‌دهد و دقیقاً همین ریتم است که
                    شب‌ها خواب راحت‌تری برای شما می‌سازد.
                </p>

                <div class="timeline mt-md w-full">
                    <div class="timeline__item">
                        <span class="timeline__time">۰۷:۰۰ – ۰۸:۳۰</span>
                        <p class="timeline__title">پذیرش گرم و صبحانه</p>
                        <p class="timeline__desc">استقبال فردی مربی، معاینه سلامت روزانه و صبحانه گروهی.</p>
                    </div>
                    <div class="timeline__item">
                        <span class="timeline__time">۰۸:۳۰ – ۱۰:۳۰</span>
                        <p class="timeline__title">حلقه صبحگاهی و کارگاه یادگیری</p>
                        <p class="timeline__desc">قصه، گفت‌وگو و فعالیت هدفمند متناسب با گروه سنی.</p>
                    </div>
                    <div class="timeline__item">
                        <span class="timeline__time">۱۰:۳۰ – ۱۲:۰۰</span>
                        <p class="timeline__title">میان‌وعده و بازی آزاد در حیاط</p>
                        <p class="timeline__desc">میوه تازه و بازی حرکتی در فضای باز و امن.</p>
                    </div>
                    <div class="timeline__item">
                        <span class="timeline__time">۱۲:۰۰ – ۱۳:۳۰</span>
                        <p class="timeline__title">ناهار و آماده‌سازی استراحت</p>
                        <p class="timeline__desc">غذای تازه پخته‌شده و آموزش خودیاری در غذاخوردن.</p>
                    </div>
                    <div class="timeline__item">
                        <span class="timeline__time">۱۳:۳۰ – ۱۵:۰۰</span>
                        <p class="timeline__title">خواب و آرام‌سازی</p>
                        <p class="timeline__desc">فضای تاریک و ساکت با موسیقی ملایم و پایش مربی.</p>
                    </div>
                    <div class="timeline__item">
                        <span class="timeline__time">۱۵:۰۰ – ۱۶:۳۰</span>
                        <p class="timeline__title">هنر، موسیقی و تحویل به والدین</p>
                        <p class="timeline__desc">کارگاه خلاق و ارائه گزارش روزانه هنگام تحویل.</p>
                    </div>
                </div>
            </div>

            <div class="split__media" data-reveal="end">
                <?php
                $dayImg = is_file(__DIR__ . '/assets/img/hero-classroom.jpg')
                    ? 'assets/img/hero-classroom.jpg'
                    : 'assets/img/about-story.jpg';
                ?>
                <figure class="frame">
                    <img src="<?php echo e(asset($dayImg)); ?>"
                         alt="فضای کلاس مهدکودک <?php echo e($siteNameV); ?>"
                         width="1920" height="1072" loading="lazy" decoding="async">
                    <figcaption class="frame__chip">
                        <span class="frame__chip-icon"><?php echo icon('sun-cloud', 18); ?></span>
                        <span>
                            <span class="frame__chip-num">۲ ساعت</span>
                            <span class="frame__chip-label">بازی روزانه در فضای باز</span>
                        </span>
                    </figcaption>
                </figure>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ PARENT PORTAL ═══════════════ */ ?>
<section class="section section-sunken">
    <div class="container">
        <div class="split split--wide-end">
            <div class="split__media hide-mobile" data-reveal="start">
                <figure class="frame">
                    <?php
                    $portalImg = is_file(__DIR__ . '/assets/uploads/gallery/about-story.jpg')
                        ? 'assets/uploads/gallery/about-story.jpg'
                        : 'assets/img/about-story.jpg';
                    ?>
                    <img src="<?php echo e(asset($portalImg)); ?>"
                         alt="گزارش روزانه والدین در پنل <?php echo e($siteNameV); ?>"
                         width="800" height="533" loading="lazy" decoding="async">
                    <figcaption class="frame__chip">
                        <span class="frame__chip-icon"><?php echo icon('message', 18); ?></span>
                        <span>
                            <span class="frame__chip-num">هر روز</span>
                            <span class="frame__chip-label">گزارش دقیق فرزند شما</span>
                        </span>
                    </figcaption>
                </figure>
            </div>

            <div class="split__content" data-reveal="end">
                <span class="eyebrow eyebrow-clay">
                    <?php echo icon('message', 14); ?>
                    پنل اختصاصی والدین
                </span>
                <h2 class="split__title">نگرانی «الان حالش چطوره؟» را کنار بگذارید</h2>
                <p class="split__text">
                    مهم‌ترین چیزی که والدین از ما می‌خواهند، خبر است. پنل روما دسترسی شما را
                    به جزئیات روز فرزندتان باز می‌کند — هر زمان، از گوشی خودتان.
                </p>

                <ul class="check-list mt-md">
                    <li><?php echo icon('check-circle', 19); ?><span><strong>گزارش روزانه:</strong> چه خورد، چقدر خوابید، حال‌وهوایش چطور بود.</span></li>
                    <li><?php echo icon('check-circle', 19); ?><span><strong>حضور و غیاب:</strong> ساعت دقیق ورود و خروج با تأیید مربی.</span></li>
                    <li><?php echo icon('check-circle', 19); ?><span><strong>پیام مستقیم مربی:</strong> پرسش‌های روزمره را همان‌جا بپرسید.</span></li>
                    <li><?php echo icon('check-circle', 19); ?><span><strong>شهریه و پرداخت‌ها:</strong> صورت‌حساب شفاف و سابقه پرداخت.</span></li>
                    <li><?php echo icon('check-circle', 19); ?><span><strong>منوی هفتگی غذا:</strong> از قبل بدانید فردا چه می‌خورد.</span></li>
                </ul>

                <div class="row mt-md">
                    <?php if (isParentLoggedIn()): ?>
                        <a class="btn btn-primary" href="<?php echo e(url('parent/index.php')); ?>">
                            <?php echo icon('user', 17); ?>
                            ورود به پنل والدین
                        </a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="<?php echo e(url('login.php')); ?>">
                            <?php echo icon('login', 17); ?>
                            ورود به پنل والدین
                        </a>
                        <a class="btn btn-ghost" href="<?php echo e(url('register.php')); ?>">ثبت‌نام</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ TESTIMONIALS ═══════════════ */ ?>
<section class="section">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow">
                <?php echo icon('star', 14); ?>
                تجربه خانواده‌ها
            </span>
            <h2 class="section-head__title">
                والدین روما چه <span class="u-mark">می‌گویند</span>
            </h2>
            <p class="section-head__sub">
                بهترین معرفی ما، حرف خانواده‌هایی است که هر روز فرزندشان را به ما می‌سپارند.
            </p>
        </div>

        <div class="testimonials" data-reveal-group data-reveal-step="100">
            <article class="card quote" data-reveal="up">
                <div class="quote__stars" role="img" aria-label="امتیاز ۵ از ۵">
                    <?php for ($i = 0; $i < 5; $i++) { echo brandIcon('star', 16); } ?>
                </div>
                <p class="quote__text">
                    «چیزی که ما را مطمئن کرد، این بود که هر روز عصر یک گزارش واقعی از وضعیت غذا
                    و خواب آیدا می‌گرفتیم — نه یک پیام کلی که ”روز خوبی داشت“.»
                </p>
                <div class="quote__author">
                    <span class="quote__avatar">م‌آ</span>
                    <span>
                        <span class="quote__name">مادر آیدا</span><br>
                        <span class="quote__role">کلاس خردسال</span>
                    </span>
                </div>
            </article>

            <article class="card quote" data-reveal="up">
                <div class="quote__stars" role="img" aria-label="امتیاز ۵ از ۵">
                    <?php for ($i = 0; $i < 5; $i++) { echo brandIcon('star', 16); } ?>
                </div>
                <p class="quote__text">
                    «بار اول که برای بازدید آمدیم، پارسا از همان در ورودی نمی‌خواست برگردد.
                    این برای ما از هر تبلیغی مهم‌تر بود.»
                </p>
                <div class="quote__author">
                    <span class="quote__avatar">پ‌پ</span>
                    <span>
                        <span class="quote__name">پدر پارسا</span><br>
                        <span class="quote__role">کلاس نوباوه</span>
                    </span>
                </div>
            </article>

            <article class="card quote" data-reveal="up">
                <div class="quote__stars" role="img" aria-label="امتیاز ۵ از ۵">
                    <?php for ($i = 0; $i < 5; $i++) { echo brandIcon('star', 16); } ?>
                </div>
                <p class="quote__text">
                    «پیش‌دبستانی روما مهارت دست‌ورزی و اعتماد به‌نفس کیان را عالی بالا برد.
                    الان برای مدرسه کاملاً آماده و مشتاق است.»
                </p>
                <div class="quote__author">
                    <span class="quote__avatar">م‌ک</span>
                    <span>
                        <span class="quote__name">مادر کیان</span><br>
                        <span class="quote__role">پیش‌دبستانی</span>
                    </span>
                </div>
            </article>
        </div>
    </div>
</section>

<?php /* ═══════════════ GALLERY ═══════════════ */ ?>
<?php if ($galleryItems !== []): ?>
<section class="section section-paper" id="gallery">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow eyebrow-clay">
                <?php echo icon('camera', 14); ?>
                گالری تصاویر
            </span>
            <h2 class="section-head__title">
                نگاهی به <span class="u-mark">روزهای روما</span>
            </h2>
            <p class="section-head__sub">
                فضای کلاس‌ها، کارگاه‌ها و لحظه‌های شادی که هر روز اینجا ساخته می‌شود.
            </p>
        </div>

        <?php if ($presentCategories !== []): ?>
        <div class="gallery-filters" role="group" aria-label="فیلتر گالری" data-reveal="up">
            <button type="button" class="chip is-active" data-gallery-filter="all" aria-pressed="true">همه تصاویر</button>
            <?php foreach ($presentCategories as $slug => $label): ?>
                <button type="button" class="chip" data-gallery-filter="<?php echo e($slug); ?>" aria-pressed="false"><?php echo e($label); ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="gallery" data-gallery data-reveal="up">
            <?php foreach ($galleryItems as $item): ?>
                <?php
                $gTitle   = (string) ($item['title'] ?? '');
                $gCaption = (string) ($item['caption'] ?? '');
                $gSrc     = url((string) $item['image']);
                $alt      = $gTitle !== '' ? $gTitle : 'تصویری از مهدکودک ' . $siteNameV;
                ?>
                <button type="button"
                        class="gallery__item"
                        data-gallery-item
                        data-category="<?php echo e((string) ($item['category'] ?? '')); ?>"
                        data-lightbox-src="<?php echo e($gSrc); ?>"
                        data-lightbox-alt="<?php echo e($alt); ?>"
                        data-lightbox-cap="<?php echo e($gCaption !== '' ? $gCaption : $gTitle); ?>"
                        aria-label="بزرگ‌نمایی تصویر: <?php echo e($alt); ?>">
                    <img src="<?php echo e($gSrc); ?>" alt="<?php echo e($alt); ?>" loading="lazy" decoding="async">
                    <?php if ($gTitle !== '' || $gCaption !== ''): ?>
                        <span class="gallery__cap"><?php echo e($gCaption !== '' ? $gCaption : $gTitle); ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* Lightbox */ ?>
<div class="lightbox" data-lightbox aria-hidden="true" role="dialog" aria-modal="true" aria-label="نمایش تصویر">
    <button type="button" class="lightbox__btn lightbox__close" data-lightbox-close aria-label="بستن">
        <?php echo icon('close', 22); ?>
    </button>
    <button type="button" class="lightbox__btn lightbox__prev" data-lightbox-prev aria-label="تصویر قبلی">
        <?php echo icon('chevron-right', 22); ?>
    </button>
    <button type="button" class="lightbox__btn lightbox__next" data-lightbox-next aria-label="تصویر بعدی">
        <?php echo icon('chevron-left', 22); ?>
    </button>
    <figure class="lightbox__figure">
        <img class="lightbox__img" data-lightbox-img src="" alt="">
        <figcaption class="lightbox__cap" data-lightbox-cap></figcaption>
    </figure>
</div>
<?php endif; ?>

<?php /* ═══════════════ NEWS ═══════════════ */ ?>
<?php if ($latestNews !== []): ?>
<section class="section" id="news">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow">
                <?php echo icon('news', 14); ?>
                تازه‌های روما
            </span>
            <h2 class="section-head__title">
                آخرین <span class="u-mark">اخبار و اطلاعیه‌ها</span>
            </h2>
            <p class="section-head__sub">
                از جشن‌ها و اردوها تا کارگاه‌های تربیتی والدین و تغییرات منوی غذایی.
            </p>
        </div>

        <div class="news-grid" data-reveal-group data-reveal-step="100">
            <?php foreach ($latestNews as $news): ?>
                <?php
                $newsUrl = url('news.php?id=' . (int) $news['id']);
                $cat     = (string) ($news['category'] ?? '');
                ?>
                <article class="card card-hover news-card" data-reveal="up">
                    <a class="news-card__media" href="<?php echo e($newsUrl); ?>" aria-hidden="true" tabindex="-1">
                        <?php if (!empty($news['image'])): ?>
                            <img src="<?php echo e(url((string) $news['image'])); ?>"
                                 alt="<?php echo e((string) $news['title']); ?>"
                                 loading="lazy" decoding="async">
                        <?php else: ?>
                            <img src="<?php echo e(asset('assets/img/about-story.jpg')); ?>"
                                 alt="<?php echo e((string) $news['title']); ?>"
                                 loading="lazy" decoding="async">
                        <?php endif; ?>
                        <span class="news-card__cat">
                            <span class="badge <?php echo e(newsCategoryBadge($cat)); ?>"><?php echo e(newsCategoryLabel($cat)); ?></span>
                        </span>
                    </a>
                    <div class="news-card__body">
                        <span class="news-card__date">
                            <?php echo icon('calendar', 14); ?>
                            <?php echo e(shamsiDate((string) $news['created_at'])); ?>
                        </span>
                        <h3 class="news-card__title">
                            <a href="<?php echo e($newsUrl); ?>"><?php echo e((string) $news['title']); ?></a>
                        </h3>
                        <p class="news-card__excerpt"><?php echo e(homeExcerpt((string) $news['content'])); ?></p>
                        <span class="news-card__more">
                            ادامه مطلب
                            <?php echo icon('arrow-left', 16); ?>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-xl" data-reveal="up">
            <a class="btn btn-ghost btn-lg" href="<?php echo e(url('news.php')); ?>">
                مشاهده همه اخبار
                <?php echo icon('arrow-left', 19); ?>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php /* ═══════════════ FAQ ═══════════════ */ ?>
<section class="section section-sunken" id="faq">
    <div class="container container-narrow">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow">
                <?php echo icon('info', 14); ?>
                پرسش‌های متداول
            </span>
            <h2 class="section-head__title">
                هر چه پیش از ثبت‌نام <span class="u-mark">باید بدانید</span>
            </h2>
            <p class="section-head__sub">
                پاسخ صریح به سوال‌هایی که بیشتر والدین در اولین تماس می‌پرسند.
            </p>
        </div>

        <div class="faq" data-reveal-group data-reveal-step="60">
            <details class="faq__item" data-reveal="up">
                <summary class="faq__q">
                    <span>ساعات کاری مهدکودک روما چگونه است؟</span>
                    <?php echo icon('chevron-down', 20); ?>
                </summary>
                <div class="faq__a">
                    شنبه تا چهارشنبه از ۰۷:۰۰ تا ۱۶:۳۰ و پنج‌شنبه‌ها از ۰۷:۰۰ تا ۱۳:۰۰.
                    جمعه‌ها و تعطیلات رسمی مجموعه تعطیل است. امکان تحویل زودتر یا دیرتر
                    (تا ۱۷:۰۰) با هماهنگی قبلی وجود دارد.
                </div>
            </details>

            <details class="faq__item" data-reveal="up">
                <summary class="faq__q">
                    <span>آیا امکان ثبت‌نام نیم‌روزه وجود دارد؟</span>
                    <?php echo icon('chevron-down', 20); ?>
                </summary>
                <div class="faq__a">
                    بله. دو گزینه وجود دارد: نیم‌روزه (۰۷:۰۰ تا ۱۲:۳۰، شامل صبحانه و
                    میان‌وعده) و تمام‌روزه (۰۷:۰۰ تا ۱۶:۳۰، شامل ناهار و خواب).
                    انتخاب حالت در طول سال قابل تغییر است.
                </div>
            </details>

            <details class="faq__item" data-reveal="up">
                <summary class="faq__q">
                    <span>کودک من آلرژی غذایی دارد؛ چطور مدیریت می‌شود؟</span>
                    <?php echo icon('chevron-down', 20); ?>
                </summary>
                <div class="faq__a">
                    در زمان ثبت‌نام فرم حساسیت‌های غذایی و دارویی تکمیل می‌شود. آشپزخانه
                    مجموعه برای هر کودک دارای آلرژی، منوی جداگانه و ظرف مخصوص علامت‌گذاری‌شده
                    تهیه می‌کند و این اطلاعات روی کارت کلاس مربی نیز درج می‌شود.
                </div>
            </details>

            <details class="faq__item" data-reveal="up">
                <summary class="faq__q">
                    <span>دوره سازگاری کودک تازه‌وارد چگونه است؟</span>
                    <?php echo icon('chevron-down', 20); ?>
                </summary>
                <div class="faq__a">
                    یک هفته اول به‌صورت پله‌ای پیش می‌رود: روز اول یک ساعت همراه والد،
                    و به‌تدریج تا یک روز کامل. هدف این است که جدایی برای کودک تجربه‌ای
                    ایمن باشد، نه شوک. مربی هر روز بازخورد سازگاری را با شما مرور می‌کند.
                </div>
            </details>

            <details class="faq__item" data-reveal="up">
                <summary class="faq__q">
                    <span>چگونه گزارش روزانه فرزندم را می‌بینم؟</span>
                    <?php echo icon('chevron-down', 20); ?>
                </summary>
                <div class="faq__a">
                    از طریق
                    <a href="<?php echo e(url('login.php')); ?>">پنل اختصاصی والدین روما</a>.
                    وضعیت تغذیه، ساعت خواب، حال‌وهوا، فعالیت‌های کلاسی و یادداشت مربی
                    هر روز ثبت می‌شود و از گوشی خودتان قابل مشاهده است.
                </div>
            </details>

            <details class="faq__item" data-reveal="up">
                <summary class="faq__q">
                    <span>برای بازدید حضوری باید چه کار کنم؟</span>
                    <?php echo icon('chevron-down', 20); ?>
                </summary>
                <div class="faq__a">
                    فقط
                    <a href="<?php echo e(url('page.php?slug=contact')); ?>">فرم تماس</a>
                    را پر کنید یا تلفنی هماهنگ کنید. بازدیدها در ساعات فعالیت مهد انجام
                    می‌شود تا فضا را در حال زندگی واقعی ببینید — نه یک ساختمان خالی.
                </div>
            </details>
        </div>
    </div>
</section>

<?php /* ═══════════════ ABOUT TEASER ═══════════════ */ ?>
<section class="section">
    <div class="container">
        <div class="split split--wide-start">
            <div class="split__content" data-reveal="start">
                <span class="eyebrow">
                    <?php echo icon('leaf', 14); ?>
                    درباره روما
                </span>
                <h2 class="split__title">روایتی از عشق، امنیت و رشد شکوفه‌های فردا</h2>
                <p class="split__text">
                    مهدکودک <?php echo e($siteNameV); ?> از سال ۱۳۹۵ با دو کلاس کوچک و یک باور
                    ساده شروع کرد: سال‌های نخست زندگی، شالودهٔ شخصیت و آیندهٔ یک انسان را
                    می‌سازد. امروز پس از ده سال، همان باور با تجربه، آموزش تخصصی مربیان و
                    اعتماد بیش از ۵۰۰ خانواده پشتیبانی می‌شود.
                </p>
                <p class="split__text">
                    ما مهد را «جایی برای نگهداری» نمی‌دانیم؛ آن را نخستین جامعهٔ کودک
                    می‌دانیم — جایی که یاد می‌گیرد دوست بشود، اشتباه کند، دوباره تلاش کند
                    و به خودش اعتماد داشته باشد.
                </p>
                <a class="btn btn-primary btn-lg mt-md" href="<?php echo e(url('page.php?slug=about')); ?>">
                    داستان کامل روما
                    <?php echo icon('arrow-left', 19); ?>
                </a>
            </div>

            <div class="split__media" data-reveal="end">
                <?php
                $aboutImg = is_file(__DIR__ . '/assets/uploads/gallery/about-story.jpg')
                    ? 'assets/uploads/gallery/about-story.jpg'
                    : 'assets/img/about-story.jpg';
                ?>
                <figure class="frame">
                    <img src="<?php echo e(asset($aboutImg)); ?>"
                         alt="داستان مهدکودک <?php echo e($siteNameV); ?>"
                         width="800" height="533" loading="lazy" decoding="async">
                    <figcaption class="frame__chip">
                        <span class="frame__chip-icon"><?php echo icon('award', 18); ?></span>
                        <span>
                            <span class="frame__chip-num">از ۱۳۹۵</span>
                            <span class="frame__chip-label">همراه خانواده‌های ایرانی</span>
                        </span>
                    </figcaption>
                </figure>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ NEWSLETTER ═══════════════ */ ?>
<section class="section section-tight">
    <div class="container">
        <div class="newsletter" data-reveal="zoom">
            <span class="newsletter__icon"><?php echo icon('mail', 27); ?></span>
            <h2 class="newsletter__title">خبرنامه تربیتی روما</h2>
            <p class="newsletter__text">
                ماهی یک ایمیل کوتاه: نکته‌های کاربردی تربیت کودک از روانشناس مجموعه،
                برنامه کارگاه‌های والدین و اطلاعیه‌های مهم. بدون تبلیغات.
            </p>

            <form class="newsletter__form" data-newsletter novalidate>
                <input type="text" name="name" class="form-control" placeholder="نام و نام خانوادگی" aria-label="نام و نام خانوادگی" autocomplete="name" required>
                <input type="email" name="email" class="form-control" placeholder="آدرس ایمیل" aria-label="آدرس ایمیل" autocomplete="email" required>
                <button type="submit" class="btn btn-primary">
                    <?php echo icon('send', 17); ?>
                    عضویت
                </button>
            </form>
            <div class="newsletter__toast" data-newsletter-toast role="status" aria-live="polite"></div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/templates/cta-section.php'; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
