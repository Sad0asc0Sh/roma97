<?php
declare(strict_types=1);

/**
 * Rooma — About page template (page.php?slug=about)
 */

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../../includes/ui.php';

$siteNameV = siteName();

/* Team members come from the teachers table when available. */
$teamTeachers = [];
try {
    if (function_exists('initializeTeachersTables')) {
        initializeTeachersTables();
    }
    $pdoTeam = getDb();
    $teamStmt = $pdoTeam->query(
        "SELECT first_name, last_name, avatar, role_title, bio, education_level, major
         FROM teachers
         WHERE status = 'active' AND (show_in_team = 1 OR show_in_team IS NULL)
         ORDER BY sort_order ASC, id ASC"
    );
    $teamTeachers = $teamStmt->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
}

if (!function_exists('roomaTeacherInitials')) {
    /**
     * Two-letter Persian monogram for a teacher without a photo.
     */
    function roomaTeacherInitials(string $first, string $last): string
    {
        $f = mb_substr(trim($first), 0, 1, 'UTF-8');
        $l = mb_substr(trim($last), 0, 1, 'UTF-8');

        if ($f !== '' && $l !== '') {
            return $f . '‌' . $l;
        }

        return $f !== '' ? $f : ($l !== '' ? $l : '؟');
    }
}

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
            <span class="crumbs__current" aria-current="page">درباره روما</span>
        </nav>
        <h1 class="page-hero__title">داستان و فلسفه مهدکودک <?php echo e($siteNameV); ?></h1>
        <p class="page-hero__sub">
            ده سال تلاش برای ساختن جایی که کودک با خیال راحت بزرگ شود و والدین با آرامش
            سر کار بروند.
        </p>
    </div>
</section>

<?php /* ═══════════════ STORY ═══════════════ */ ?>
<section class="section">
    <div class="container">
        <div class="split split--wide-start">
            <div class="split__content" data-reveal="start">
                <span class="eyebrow">
                    <?php echo icon('leaf', 14); ?>
                    از کجا شروع شد
                </span>
                <h2 class="split__title">از دو کلاس کوچک تا خانه دوم ۵۰۰ کودک</h2>
                <p class="split__text">
                    سال ۱۳۹۵ روما با دو کلاس، پنج مربی و ظرفیت بیست کودک کار خود را آغاز کرد.
                    ایده اولیه ساده بود: مادرانی که مجبور بودند بین شغل و آرامش خیالشان یکی را
                    انتخاب کنند، باید گزینه سومی داشته باشند — مهدی که بتوانند به آن اعتماد کنند.
                </p>
                <p class="split__text">
                    امروز پس از ده سال، بیش از ۵۰۰ کودک از روما فارغ‌التحصیل شده‌اند. آنچه تغییر
                    نکرده، همان اصل نخست است: هر کودک یک نفر است، نه یک شماره در لیست حضور.
                    برنامه ما با ریتم او هماهنگ می‌شود، نه برعکس.
                </p>

                <ul class="check-list mt-md">
                    <li><?php echo icon('check-circle', 19); ?><span>مجوز رسمی و فعال سازمان بهزیستی</span></li>
                    <li><?php echo icon('check-circle', 19); ?><span>فضای مستقل و ایمن‌سازی‌شده برای هر گروه سنی</span></li>
                    <li><?php echo icon('check-circle', 19); ?><span>آشپزخانه اختصاصی با پخت روزانه</span></li>
                    <li><?php echo icon('check-circle', 19); ?><span>بیمه حوادث کامل برای همه کودکان</span></li>
                </ul>
            </div>

            <div class="split__media" data-reveal="end">
                <?php
                $storyImg = is_file(__DIR__ . '/../../assets/uploads/gallery/about-story.jpg')
                    ? 'assets/uploads/gallery/about-story.jpg'
                    : 'assets/img/about-story.jpg';
                ?>
                <figure class="frame">
                    <img src="<?php echo e(asset($storyImg)); ?>"
                         alt="داستان مهدکودک <?php echo e($siteNameV); ?>"
                         width="800" height="533" loading="lazy" decoding="async">
                    <figcaption class="frame__chip">
                        <span class="frame__chip-icon"><?php echo icon('users', 18); ?></span>
                        <span>
                            <span class="frame__chip-num">۵۰۰+</span>
                            <span class="frame__chip-label">کودک فارغ‌التحصیل</span>
                        </span>
                    </figcaption>
                </figure>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ PILLARS ═══════════════ */ ?>
<section class="section section-paper">
    <div class="container">
        <div class="pillars" data-reveal-group data-reveal-step="110">
            <div class="pillars__head" data-reveal="up">
                <span class="eyebrow eyebrow-light">
                    <?php echo icon('target', 14); ?>
                    اصول ما
                </span>
                <h2 class="pillars__title">سه اصلی که هر روز در روما زندگی می‌کنیم</h2>
                <p class="pillars__sub">اینها شعار روی دیوار نیستند؛ معیار تصمیم‌گیری روزانه ما هستند.</p>
            </div>

            <div class="pillars__grid">
                <article class="pillar" data-reveal="up">
                    <div class="pillar__num">۰۱</div>
                    <h3 class="pillar__title">یادگیری از دل بازی</h3>
                    <p class="pillar__text">
                        کودکان با حفظ‌کردن رشد نمی‌کنند؛ با لمس‌کردن، ساختن و تجربه مستقیم،
                        مفاهیم ریاضی، زبان و مهارت‌های اجتماعی را درونی می‌کنند. در روما
                        هیچ کودکی پشت میز نمی‌نشیند تا «درس بدهد».
                    </p>
                </article>

                <article class="pillar" data-reveal="up">
                    <div class="pillar__num">۰۲</div>
                    <h3 class="pillar__title">شفافیت کامل با والدین</h3>
                    <p class="pillar__text">
                        شما حق دارید بدانید فرزندتان چه خورد، چقدر خوابید و امروز چه حالی داشت.
                        پنل والدین، گزارش روزانه و ارتباط مستقیم با مربی، حدس‌زدن را از
                        رابطه ما حذف می‌کند.
                    </p>
                </article>

                <article class="pillar" data-reveal="up">
                    <div class="pillar__num">۰۳</div>
                    <h3 class="pillar__title">رشد فردی، نه یکسان‌سازی</h3>
                    <p class="pillar__text">
                        هر کودک الگوی رشد بی‌نظیر خودش را دارد. یکی زودتر حرف می‌زند، دیگری
                        زودتر راه می‌افتد. مربیان ما برنامه را با ریتم و استعداد هر کودک
                        تنظیم می‌کنند — و هیچ‌وقت او را مقایسه نمی‌کنند.
                    </p>
                </article>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ TIMELINE ═══════════════ */ ?>
<section class="section">
    <div class="container container-narrow">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow">
                <?php echo icon('trending', 14); ?>
                مسیر رشد
            </span>
            <h2 class="section-head__title">
                روما در یک <span class="u-mark">نگاه</span>
            </h2>
            <p class="section-head__sub">از تأسیس تا امروز، همراه با خانواده‌ها.</p>
        </div>

        <div class="timeline" data-reveal-group data-reveal-step="90">
            <div class="timeline__item" data-reveal="start">
                <span class="timeline__time">۱۳۹۵</span>
                <p class="timeline__title">تأسیس روما با دو کلاس نوباوه</p>
                <p class="timeline__desc">
                    آغاز فعالیت با مجوز رسمی بهزیستی، پنج مربی و ظرفیت پذیرش بیست کودک
                    در فضایی کوچک و صمیمی.
                </p>
            </div>
            <div class="timeline__item" data-reveal="start">
                <span class="timeline__time">۱۳۹۷</span>
                <p class="timeline__title">افزودن بخش پیش‌دبستانی</p>
                <p class="timeline__desc">
                    راه‌اندازی دوره آمادگی مدرسه با تمرکز بر دست‌ورزی، تمرکز و سوادآموزی اولیه.
                </p>
            </div>
            <div class="timeline__item" data-reveal="start">
                <span class="timeline__time">۱۳۹۹</span>
                <p class="timeline__title">توسعه فضای بازی سرپوشیده و کارگاه‌ها</p>
                <p class="timeline__desc">
                    ساخت اتاق بازی‌های تعاملی، کارگاه سفالگری و نوسازی کامل حیاط با
                    کف‌پوش ضربه‌گیر استاندارد.
                </p>
            </div>
            <div class="timeline__item" data-reveal="start">
                <span class="timeline__time">۱۴۰۱</span>
                <p class="timeline__title">آشپزخانه اختصاصی و مشاور تغذیه</p>
                <p class="timeline__desc">
                    پایان قرارداد با پیمانکار غذا و آغاز پخت روزانه در محل، زیر نظر
                    کارشناس تغذیه کودک.
                </p>
            </div>
            <div class="timeline__item" data-reveal="start">
                <span class="timeline__time">۱۴۰۳</span>
                <p class="timeline__title">راه‌اندازی پنل هوشمند والدین</p>
                <p class="timeline__desc">
                    استقرار سامانه دیجیتال گزارش روزانه، حضور و غیاب، پیام مربی و
                    مدیریت شهریه به‌صورت کاملاً شفاف.
                </p>
            </div>
            <div class="timeline__item" data-reveal="start">
                <span class="timeline__time">امروز</span>
                <p class="timeline__title">بیش از ۵۰۰ خانواده، یک هدف ثابت</p>
                <p class="timeline__desc">
                    ادامه همان مسیر با بیست مربی و کارشناس، و همان اصل نخست:
                    هر کودک یک نفر است.
                </p>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════ TEAM ═══════════════ */ ?>
<?php if ($teamTeachers !== []): ?>
<section class="section section-sunken">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow eyebrow-clay">
                <?php echo icon('users', 14); ?>
                تیم روما
            </span>
            <h2 class="section-head__title">
                کسانی که هر روز <span class="u-mark">کنار فرزند شما</span> هستند
            </h2>
            <p class="section-head__sub">
                همه مربیان ما دارای مدرک مرتبط، گواهی مربی‌گری بهزیستی و کارت بهداشت معتبرند.
            </p>
        </div>

        <div class="team-grid" data-reveal-group data-reveal-step="80">
            <?php foreach ($teamTeachers as $teacher): ?>
                <?php
                $fullName = trim((string) $teacher['first_name'] . ' ' . (string) $teacher['last_name']);
                $initials = roomaTeacherInitials((string) $teacher['first_name'], (string) $teacher['last_name']);
                $roleTitle = (string) ($teacher['role_title'] ?? '');
                if ($roleTitle === '') {
                    $roleTitle = (string) ($teacher['education_level'] ?? '') !== ''
                        ? (string) $teacher['education_level']
                        : 'مربی روما';
                }
                $bioText = (string) ($teacher['bio'] ?? '');
                if ($bioText === '' && (string) ($teacher['major'] ?? '') !== '') {
                    $bioText = 'تخصص: ' . (string) $teacher['major'];
                }
                $avatar = (string) ($teacher['avatar'] ?? '');
                ?>
                <article class="card card-hover person" data-reveal="up">
                    <div class="person__photo">
                        <?php if ($avatar !== ''): ?>
                            <img src="<?php echo e(url($avatar)); ?>" alt="<?php echo e($fullName); ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span class="person__initials"><?php echo e($initials); ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="person__name"><?php echo e($fullName); ?></h3>
                    <div class="person__role">
                        <span class="badge badge-primary"><?php echo e($roleTitle); ?></span>
                    </div>
                    <?php if ($bioText !== ''): ?>
                        <p class="person__bio"><?php echo e($bioText); ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php /* ═══════════════ CERTIFICATES ═══════════════ */ ?>
<section class="section">
    <div class="container">
        <div class="section-head" data-reveal="up">
            <span class="eyebrow">
                <?php echo icon('shield', 14); ?>
                مجوزها و استانداردها
            </span>
            <h2 class="section-head__title">
                اعتماد شما با <span class="u-mark">سند</span> پشتیبانی می‌شود
            </h2>
            <p class="section-head__sub">
                همه مجوزها در محل مجموعه قابل رؤیت و استعلام است.
            </p>
        </div>

        <div class="feature-grid" data-reveal-group data-reveal-step="80">
            <article class="card card-hover feature text-center" data-reveal="up">
                <span class="feature__icon mx-auto"><?php echo icon('shield', 27); ?></span>
                <h3 class="feature__title">پروانه رسمی بهزیستی</h3>
                <p class="feature__text">
                    فعالیت روما تحت پروانه معتبر سازمان بهزیستی است و شماره ثبت آن
                    قابل استعلام می‌باشد.
                </p>
            </article>

            <article class="card card-hover feature text-center" data-reveal="up">
                <span class="feature__icon mx-auto"><?php echo icon('utensils', 27); ?></span>
                <h3 class="feature__title">تأییدیه بهداشت و تغذیه</h3>
                <p class="feature__text">
                    کارت بهداشت همه کارکنان به‌روز است و آشپزخانه به‌صورت دوره‌ای
                    بازرسی می‌شود.
                </p>
            </article>

            <article class="card card-hover feature text-center" data-reveal="up">
                <span class="feature__icon mx-auto"><?php echo icon('camera', 27); ?></span>
                <h3 class="feature__title">ایمنی فیزیکی و پایش</h3>
                <p class="feature__text">
                    دوربین مداربسته کلاس‌ها و حیاط، کف‌پوش ضربه‌گیر و مهار تجهیزات
                    مطابق استاندارد ایمنی کودک.
                </p>
            </article>

            <article class="card card-hover feature text-center" data-reveal="up">
                <span class="feature__icon mx-auto"><?php echo icon('heart', 27); ?></span>
                <h3 class="feature__title">بیمه حوادث کودکان</h3>
                <p class="feature__text">
                    همه کودکان از روز نخست ثبت‌نام تحت پوشش بیمه حوادث قرار می‌گیرند.
                </p>
            </article>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../cta-section.php'; ?>
