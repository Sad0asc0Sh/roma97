<?php
declare(strict_types=1);

/**
 * Roma — Classes & programmes page template (page.php?slug=classes)
 *
 * The three age groups are described by one data structure and rendered by a
 * single loop, so the tabs, panels and cards can never fall out of sync.
 */

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../../includes/ui.php';

$siteNameV = siteName();

$classGroups = [
    [
        'id'      => 'nobaaveh',
        'tab'     => 'نوباوه · ۶ ماه تا ۲ سال',
        'title'   => 'کلاس نوباوه — مراقبت گرم و دلبستگی ایمن',
        'age'     => '۶ ماه تا ۲ سال',
        'ratio'   => 'نسبت مربی ۱ به ۴',
        'badge'   => 'badge-primary',
        'head'    => 'class-card__head--green',
        'icon'    => 'baby',
        'lead'    => 'در این سن مهم‌ترین کار ما ساختن حس امنیت است. کودک باید یاد بگیرد که '
                   . 'دنیا جای قابل اعتمادی است و کسی هست که به گریه‌اش پاسخ می‌دهد.',
        'capacity' => ['fill' => 80, 'text' => '۲ جای خالی از ۱۰'],
        'skills'  => [
            'تقویت تعادل، نشستن و گام‌برداری اولیه',
            'تحریک حسی با اسباب‌بازی‌های لمسی ایمن',
            'تنظیم ریتم خواب و تغذیه مطابق عادت خانه',
            'شروع بازی موازی و آشنایی با همسالان',
        ],
        'parents' => [
            'گزارش ساعتی خواب، شیر و پوشک',
            'مربی ثابت برای هر کودک (اصل مراقب اصلی)',
            'اتاق خواب مستقل و تاریک با پایش مربی',
        ],
        'schedule' => [
            ['۰۷:۳۰ – ۰۹:۰۰', 'پذیرش، معاینه سلامت و صبحانه'],
            ['۰۹:۰۰ – ۱۰:۳۰', 'بازی حرکتی، ماساژ کودک و آواز'],
            ['۱۰:۳۰ – ۱۲:۰۰', 'میان‌وعده میوه و قصه حسی'],
            ['۱۲:۰۰ – ۱۳:۳۰', 'ناهار و آماده‌سازی خواب'],
            ['۱۳:۳۰ – ۱۵:۳۰', 'خواب آرام با پایش مربی'],
            ['۱۵:۳۰ – ۱۶:۳۰', 'میان‌وعده، بازی آزاد و تحویل'],
        ],
    ],
    [
        'id'      => 'khordsal',
        'tab'     => 'خردسال · ۲ تا ۴ سال',
        'title'   => 'کلاس خردسال — انفجار زبان و مهارت اجتماعی',
        'age'     => '۲ تا ۴ سال',
        'ratio'   => 'نسبت مربی ۱ به ۶',
        'badge'   => 'badge-secondary',
        'head'    => 'class-card__head--clay',
        'icon'    => 'puzzle',
        'lead'    => 'سن طلایی زبان و اولین تجربه زندگی گروهی. کودک یاد می‌گیرد نوبت بگیرد، '
                   . 'احساسش را با کلمه بگوید و به‌جای گرفتن اسباب‌بازی، درخواست کند.',
        'capacity' => ['fill' => 71, 'text' => '۴ جای خالی از ۱۴'],
        'skills'  => [
            'غنی‌سازی واژگان با قصه‌گویی و گفت‌وگو',
            'بازی‌های گروهی، نوبت‌گیری و مشارکت',
            'کارگاه هنر، سفالگری و موسیقی کودک',
            'آموزش استقلال: دستشویی، لباس و غذاخوردن',
        ],
        'parents' => [
            'گزارش روزانه رفتار، تغذیه و خواب',
            'جلسه مشاوره فصلی با روانشناس مجموعه',
            'راهنمای خانه برای هم‌راستا کردن قوانین',
        ],
        'schedule' => [
            ['۰۷:۳۰ – ۰۹:۰۰', 'پذیرش و صبحانه گروهی'],
            ['۰۹:۰۰ – ۱۰:۰۰', 'حلقه صبحگاهی: قصه، تقویم و گفت‌وگو'],
            ['۱۰:۰۰ – ۱۱:۰۰', 'کارگاه هنر یا موسیقی'],
            ['۱۱:۰۰ – ۱۲:۰۰', 'میان‌وعده و بازی آزاد در حیاط'],
            ['۱۲:۰۰ – ۱۳:۳۰', 'ناهار و آماده‌سازی استراحت'],
            ['۱۳:۳۰ – ۱۵:۰۰', 'استراحت یا فعالیت آرام'],
            ['۱۵:۰۰ – ۱۶:۳۰', 'بازی هدفمند و تحویل به والدین'],
        ],
    ],
    [
        'id'      => 'pish',
        'tab'     => 'پیش‌دبستانی · ۴ تا ۶ سال',
        'title'   => 'پیش‌دبستانی — آماده‌سازی واقعی برای مدرسه',
        'age'     => '۴ تا ۶ سال',
        'ratio'   => 'نسبت مربی ۱ به ۷',
        'badge'   => 'badge-info',
        'head'    => 'class-card__head--sky',
        'icon'    => 'cap',
        'lead'    => 'آمادگی مدرسه یعنی توان تمرکز، مدیریت هیجان و اعتماد به‌نفس — نه پر کردن '
                   . 'دفتر مشق. هدف ما این است که کودک با اشتیاق وارد کلاس اول شود.',
        'capacity' => ['fill' => 80, 'text' => '۳ جای خالی از ۱۵'],
        'skills'  => [
            'آمادگی خواندن و نوشتن (آگاهی واج‌شناختی)',
            'مفاهیم ریاضی، الگو و حل مسئله',
            'تقویت دست‌ورزی و عضلات ظریف دست',
            'مهارت‌های زندگی، مسئولیت و کار گروهی',
        ],
        'parents' => [
            'کارنامه توصیفی رشد در پایان هر ترم',
            'ارزیابی آمادگی مدرسه و مشاوره انتخاب',
            'نمایشگاه سالانه آثار و اجرای نمایش',
        ],
        'schedule' => [
            ['۰۷:۳۰ – ۰۹:۰۰', 'پذیرش و صبحانه'],
            ['۰۹:۰۰ – ۱۰:۱۵', 'کارگاه سوادآموزی و زبان'],
            ['۱۰:۱۵ – ۱۱:۰۰', 'میان‌وعده و بازی در حیاط'],
            ['۱۱:۰۰ – ۱۲:۰۰', 'ریاضی، علوم و آزمایش ساده'],
            ['۱۲:۰۰ – ۱۳:۳۰', 'ناهار و زمان آرام'],
            ['۱۳:۳۰ – ۱۵:۰۰', 'پروژه گروهی، هنر یا نمایش خلاق'],
            ['۱۵:۰۰ – ۱۶:۳۰', 'ورزش، جمع‌بندی روز و تحویل'],
        ],
    ],
];

$heroImg = is_file(__DIR__ . '/../../assets/img/hero-classroom.jpg')
    ? 'assets/img/hero-classroom.jpg'
    : 'assets/img/about-story.jpg';
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
            <span class="crumbs__current" aria-current="page">کلاس‌ها و دوره‌ها</span>
        </nav>
        <h1 class="page-hero__title">کلاس‌ها و برنامه‌های سنی <?php echo e($siteNameV); ?></h1>
        <p class="page-hero__sub">
            سه گروه سنی، سه فضای مستقل و سه برنامه روزانه متفاوت — چون نیاز یک نوپا با
            یک کودک پنج‌ساله زمین تا آسمان تفاوت دارد.
        </p>
    </div>
</section>

<?php /* ═══════════════ TABS ═══════════════ */ ?>
<section class="section">
    <div class="container">

        <div class="tabs" data-tabs role="tablist" aria-label="انتخاب گروه سنی">
            <?php foreach ($classGroups as $i => $group): ?>
                <button type="button"
                        class="tab<?php echo $i === 0 ? ' is-active' : ''; ?>"
                        data-tab="#tab-<?php echo e($group['id']); ?>"
                        role="tab"
                        id="tabbtn-<?php echo e($group['id']); ?>"
                        aria-controls="tab-<?php echo e($group['id']); ?>"
                        aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                        tabindex="<?php echo $i === 0 ? '0' : '-1'; ?>">
                    <?php echo e($group['tab']); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($classGroups as $i => $group): ?>
            <div class="tab-panel<?php echo $i === 0 ? ' is-active' : ''; ?>"
                 id="tab-<?php echo e($group['id']); ?>"
                 role="tabpanel"
                 aria-labelledby="tabbtn-<?php echo e($group['id']); ?>"
                 <?php echo $i === 0 ? '' : 'hidden'; ?>>

                <div class="split split--wide-start">

                    <?php /* Left: description + skills */ ?>
                    <div>
                        <div class="card card-pad">
                            <div class="row mb-md">
                                <span class="badge <?php echo e($group['badge']); ?>"><?php echo e($group['age']); ?></span>
                                <span class="badge badge-outline"><?php echo e($group['ratio']); ?></span>
                            </div>

                            <h2 class="split__title mb-sm"><?php echo e($group['title']); ?></h2>
                            <p class="split__text mb-lg"><?php echo e($group['lead']); ?></p>

                            <div class="meter mb-lg">
                                <div class="meter__head">
                                    <span>ظرفیت تکمیل‌شده دوره جدید</span>
                                    <span class="text-secondary"><?php echo e($group['capacity']['text']); ?></span>
                                </div>
                                <div class="meter__track">
                                    <div class="meter__fill"
                                         data-meter="<?php echo (int) $group['capacity']['fill']; ?>%"
                                         style="--w:<?php echo (int) $group['capacity']['fill']; ?>%"></div>
                                </div>
                            </div>

                            <h3 class="info-card__title mb-sm">مهارت‌های کلیدی این دوره</h3>
                            <ul class="check-list mb-lg">
                                <?php foreach ($group['skills'] as $skill): ?>
                                    <li><?php echo icon('check-circle', 19); ?><span><?php echo e($skill); ?></span></li>
                                <?php endforeach; ?>
                            </ul>

                            <h3 class="info-card__title mb-sm">برای شما به‌عنوان والد</h3>
                            <ul class="check-list">
                                <?php foreach ($group['parents'] as $item): ?>
                                    <li><?php echo icon('check', 19); ?><span><?php echo e($item); ?></span></li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="row mt-lg">
                                <a class="btn btn-primary" href="<?php echo e(url('page.php?slug=contact')); ?>">
                                    <?php echo icon('calendar', 17); ?>
                                    رزرو بازدید این کلاس
                                </a>
                            </div>
                        </div>
                    </div>

                    <?php /* Right: daily schedule */ ?>
                    <div>
                        <div class="card card-pad">
                            <div class="info-card__head">
                                <span class="info-card__icon info-card__icon--clay"><?php echo icon('clock', 21); ?></span>
                                <h3 class="info-card__title">برنامه روزانه</h3>
                            </div>

                            <div class="timeline">
                                <?php foreach ($group['schedule'] as $slot): ?>
                                    <div class="timeline__item">
                                        <span class="timeline__time"><?php echo e($slot[0]); ?></span>
                                        <p class="timeline__title"><?php echo e($slot[1]); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="alert alert-info mt-lg">
                                <?php echo icon('info', 18); ?>
                                <span>
                                    برنامه بالا چارچوب کلی است. ریتم خواب و تغذیه هر کودک،
                                    خصوصاً در کلاس نوباوه، به‌صورت فردی تنظیم می‌شود.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php /* ═══════════════ COMPARISON ═══════════════ */ ?>
<section class="section section-paper">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow eyebrow-clay">
                <?php echo icon('activity', 14); ?>
                مقایسه سریع
            </span>
            <h2 class="section-head__title">
                کدام کلاس برای فرزند من <span class="u-mark">مناسب است؟</span>
            </h2>
            <p class="section-head__sub">
                اگر مطمئن نیستید، نگران نباشید — در بازدید حضوری با هم تصمیم می‌گیریم.
            </p>
        </div>

        <div class="class-grid" data-reveal-group data-reveal-step="110">
            <?php foreach ($classGroups as $group): ?>
                <article class="card card-hover class-card" data-reveal="rise">
                    <div class="class-card__head <?php echo e($group['head']); ?>">
                        <span class="class-card__icon"><?php echo icon($group['icon'], 46); ?></span>
                    </div>
                    <div class="class-card__body">
                        <h3 class="class-card__title"><?php echo e($group['age']); ?></h3>
                        <span class="badge <?php echo e($group['badge']); ?>"><?php echo e($group['ratio']); ?></span>
                        <p class="class-card__text"><?php echo e($group['lead']); ?></p>
                        <ul class="class-card__list">
                            <?php foreach (array_slice($group['skills'], 0, 3) as $skill): ?>
                                <li><?php echo icon('check', 17); ?><span><?php echo e($skill); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* ═══════════════ ENROLMENT STEPS ═══════════════ */ ?>
<section class="section">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow">
                <?php echo icon('file-text', 14); ?>
                مسیر ثبت‌نام
            </span>
            <h2 class="section-head__title">
                ثبت‌نام در روما در <span class="u-mark">چهار قدم</span>
            </h2>
            <p class="section-head__sub">شفاف، بدون کاغذبازی اضافه و بدون هزینه پنهان.</p>
        </div>

        <div class="feature-grid" data-reveal-group data-reveal-step="90">
            <article class="card card-hover feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('phone', 27); ?></span>
                <h3 class="feature__title">۱. تماس و هماهنگی</h3>
                <p class="feature__text">
                    تلفنی یا از طریق فرم تماس، زمان بازدید را رزرو می‌کنید. بازدیدها در
                    ساعات فعالیت مهد انجام می‌شود.
                </p>
            </article>

            <article class="card card-hover feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('users', 27); ?></span>
                <h3 class="feature__title">۲. بازدید حضوری</h3>
                <p class="feature__text">
                    کلاس‌ها، حیاط و آشپزخانه را می‌بینید، با مربی گروه سنی فرزندتان صحبت
                    می‌کنید و سوال‌هایتان را می‌پرسید.
                </p>
            </article>

            <article class="card card-hover feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('file-text', 27); ?></span>
                <h3 class="feature__title">۳. تکمیل مدارک</h3>
                <p class="feature__text">
                    شناسنامه، کارت واکسن، فرم سلامت و حساسیت‌های غذایی. حساب پنل والدین
                    همان روز فعال می‌شود.
                </p>
            </article>

            <article class="card card-hover feature" data-reveal="up">
                <span class="feature__icon"><?php echo icon('heart', 27); ?></span>
                <h3 class="feature__title">۴. هفته سازگاری</h3>
                <p class="feature__text">
                    شروع پله‌ای: روز اول یک ساعت همراه شما، و به‌تدریج تا یک روز کامل —
                    تا جدایی برای کودک ایمن باشد، نه شوک.
                </p>
            </article>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../cta-section.php'; ?>
