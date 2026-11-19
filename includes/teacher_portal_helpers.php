<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Teacher Portal — shared helpers & domain catalogs.
 * ------------------------------------------------------------------
 * This file is REQUIRED by the coach-portal pages (teacher/*.php)
 * and by parent/child-detail.php, which renders the same daily-report
 * factors for parents. It centralises:
 *
 *  • Classroom context resolution (multi-classroom aware, with the
 *    active class switchable from the portal header);
 *  • Operational date/time normalisation (Jalali or ISO input, a
 *    bounded back-dating window, no future dates);
 *  • The psychological observation catalogs used by the daily report:
 *    mood states, the six developmental factors, the activities bank,
 *    strength-based note templates and the non-labeling dictionary.
 *    Keeping them here (instead of inside one page) means the
 *    teacher's form, the parent's view of the same report and the
 *    SMS wording can never drift apart;
 *  • Small label/badge resolvers shared by the dashboard, the
 *    attendance register and the parent portal.
 *
 * Everything here is pure or read-only: no writes, no redirects.
 */

/* ================================================================
   Classroom context
   ================================================================ */

/**
 * Resolve every classroom assigned to a teacher and which one is
 * "active" for this request.
 *
 * Active-class selection, in order:
 *   1. ?classroom_id=… when it belongs to this teacher (the header
 *      switcher submits exactly this) — also remembered in session;
 *   2. the classroom remembered in the session, when still assigned;
 *   3. the first assigned classroom (preserves the historical
 *      single-classroom behaviour of the panel).
 *
 * @return array{
 *     classrooms: list<array<string, mixed>>,
 *     active: ?array<string, mixed>,
 *     active_id: int
 * }
 */
function teacherResolveClassroomContext(PDO $pdo, int $teacherId, bool $forWrite = false): array
{
    $stmt = $pdo->prepare(
        <<<'SQL'
SELECT
    cl.id, cl.name, cl.description, cl.capacity, cl.schedule,
    (SELECT COUNT(*)
     FROM child_classroom cc
     INNER JOIN children c ON c.id = cc.child_id
     WHERE cc.classroom_id = cl.id AND c.status = 'active') AS active_children_count
FROM classrooms cl
WHERE cl.teacher_id = :tid
ORDER BY cl.id ASC
SQL
    );
    $stmt->execute([':tid' => $teacherId]);
    $classrooms = $stmt->fetchAll();

    if ($classrooms === []) {
        return ['classrooms' => [], 'active' => null, 'active_id' => 0];
    }

    $byId = [];
    foreach ($classrooms as $cl) {
        $byId[(int) $cl['id']] = $cl;
    }

    // A form belongs to the classroom where it was opened, even if another
    // browser tab changes the session's active classroom in the meantime.
    $isWrite = $forWrite;
    $requested = filter_var($isWrite ? ($_POST['classroom_id'] ?? null) : ($_GET['classroom_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($isWrite && (!is_int($requested) || !isset($byId[$requested]))) {
        throw new InvalidArgumentException('کلاس این فرم معتبر نیست یا دسترسی شما تغییر کرده است. صفحه را دوباره باز کنید.');
    }
    if (is_int($requested) && isset($byId[$requested])) {
        $_SESSION['teacher_classroom_id'] = $requested;
        return ['classrooms' => $classrooms, 'active' => $byId[$requested], 'active_id' => $requested];
    }

    $remembered = filter_var($_SESSION['teacher_classroom_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (is_int($remembered) && isset($byId[$remembered])) {
        return ['classrooms' => $classrooms, 'active' => $byId[$remembered], 'active_id' => $remembered];
    }

    $first = $classrooms[0];

    return ['classrooms' => $classrooms, 'active' => $first, 'active_id' => (int) $first['id']];
}

/**
 * Allow-list of the ACTIVE children of a classroom, as a set
 * (child_id => true). Rebuilt from the database on every call so a
 * forged child_id in a submitted form is ignored — the same rule the
 * attendance register has always enforced inline.
 *
 * @return array<int, true>
 */
function teacherAllowedChildIds(PDO $pdo, int $classroomId): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id
         FROM child_classroom cc
         INNER JOIN children c ON c.id = cc.child_id
         WHERE cc.classroom_id = :cid AND c.status = 'active'"
    );
    $stmt->execute([':cid' => $classroomId]);

    $ids = [];
    while (($id = $stmt->fetchColumn()) !== false) {
        $ids[(int) $id] = true;
    }

    return $ids;
}

/* ================================================================
   Operational dates & times
   ================================================================ */

/**
 * Accept a Jalali (۱۴۰۳/۰۵/۱۲) or ISO date and return an ISO date.
 * Future dates are rejected, as are dates older than the correction
 * window ($windowDays, default 7) — older corrections belong to the
 * admin panel so a register cannot be quietly rewritten weeks later.
 */
function teacherValidOperationalDate(mixed $raw, int $windowDays = 7): ?string
{
    $date = parseIranDate($raw);
    $today = new DateTimeImmutable('today');
    if ($date === null || $date > $today->format('Y-m-d')) {
        return null;
    }
    if ($windowDays >= 0 && $date < $today->modify('-' . $windowDays . ' days')->format('Y-m-d')) {
        return null;
    }
    return $date;
}

/* ================================================================
   Child display helpers
   ================================================================ */

function teacherChildGenderLabel(mixed $gender): string
{
    return match ((string) $gender) {
        'male'   => 'پسر',
        'female' => 'دختر',
        default  => '',
    };
}

/* ================================================================
   Attendance labels & badges
   ================================================================ */

function teacherAttendanceStatusLabel(?string $status): string
{
    return match ($status) {
        'present' => 'حاضر',
        'late'    => 'با تأخیر',
        'excused' => 'غیبت موجه',
        'absent'  => 'غایب',
        default   => 'ثبت نشده',
    };
}

function teacherAttendanceBadgeClass(?string $status): string
{
    return match ($status) {
        'present' => 'tp-badge-present',
        'late'    => 'tp-badge-late',
        'excused' => 'tp-badge-excused',
        'absent'  => 'tp-badge-absent',
        default   => 'tp-badge-unrecorded',
    };
}

/* ================================================================
   Events
   ================================================================ */

function teacherEventCategoryLabel(string $category): string
{
    return match ($category) {
        'trip'        => 'گردش و بازدید',
        'celebration' => 'جشن',
        'meeting'     => 'جلسه با والدین',
        'holiday'     => 'تعطیلی',
        default       => 'عمومی',
    };
}

/* ================================================================
   Psychological observation catalogs (daily report)
   ----------------------------------------------------------------
   Mood keys are stored in daily_reports.mood. The four quick keys
   (happy / normal / tired / restless) are the 1-tap bar in the
   report form; 'mixed' and 'challenging' are kept for backward
   compatibility with reports written before the catalog existed,
   so old rows still render and re-save correctly.
   ================================================================ */

/**
 * @return array<string, array{label:string, clinical_desc:string, coach_tip:string, sms_phrase:string}>
 */
function teacherPsychologicalMoodCatalog(): array
{
    return [
        'happy' => [
            'label'         => '🌟 شاداب و خودانگیخته',
            'clinical_desc' => 'تنظیم هیجانی پایدار، مشارکت فعال و لذت از فعالیت‌ها',
            'coach_tip'     => 'این انرژی را با مسئولیت‌های کوچک و تحسین توصیفیِ تلاش او تقویت کنید.',
            'sms_phrase'    => 'پر از لبخند، شاداب و باانگیزه در کنار دوستانش بود 🌟',
        ],
        'normal' => [
            'label'         => '🌿 آرام و متمرکز',
            'clinical_desc' => 'حال‌وهوای متعادل، همراهی با ریتم کلاس و تمرکز مناسب',
            'coach_tip'     => 'ریتم آرام امروز را حفظ کنید و در فعالیت‌های گروهی نقش‌های متنوع به او بدهید.',
            'sms_phrase'    => 'روزی آرام، متعادل و پر از بازی و یادگیری را گذراند 🌿',
        ],
        'tired' => [
            'label'         => '🌙 نیازمند استراحت',
            'clinical_desc' => 'نشانه‌های خستگی یا کم‌خوابی؛ نیاز به کاهش محرک و استراحت بیشتر',
            'coach_tip'     => 'فضای کم‌محرک و فرصت استراحت فراهم کنید و از فعالیت‌های پرفشار امروز صرف‌نظر کنید.',
            'sms_phrase'    => 'امروز کمی خسته بود و با آرامش و استراحت کافی روزش را گذراند 🌙',
        ],
        'restless' => [
            'label'         => '🌱 نیازمند هم‌تنظیمی',
            'clinical_desc' => 'بی‌قراری یا دلتنگی؛ نیازمند حضور آرام و اطمینان‌بخش مربی',
            'coach_tip'     => 'نزدیک بمانید، احساسش را نام ببرید و با فعالیت حسی-حرکتی کوتاه به آرام‌سازی کمک کنید.',
            'sms_phrase'    => 'امروز به همراهی و آغوش بیشتری نیاز داشت و با همدلی مربی به آرامش رسید 🌱',
        ],
        'mixed' => [
            'label'         => '🎈 بازیگوش و کنجکاو (ترکیبی)',
            'clinical_desc' => 'نوسان میان هیجان و آرامش در طول روز؛ الگوی طبیعی این سن',
            'coach_tip'     => 'تغییر فعالیت‌ها را با پیش‌آگهی کوتاه همراه کنید تا گذارها برایش آسان شود.',
            'sms_phrase'    => 'روزی پر از کنجکاوی، بازی و تجربه‌های تازه داشت 🎈',
        ],
        'challenging' => [
            'label'         => '💖 نیازمند حمایت بیشتر',
            'clinical_desc' => 'روز دشوارتر؛ ناکامی یا هیجان شدید که با حمایت مربی مدیریت شد',
            'coach_tip'     => 'مرزهای آرام و ثابت نگه دارید، انتخاب‌های محدود بدهید و موفقیت‌های کوچکش را برجسته کنید.',
            'sms_phrase'    => 'امروز روز پرچالشی داشت و با حمایت و همدلی مربی آن را به‌خوبی پشت سر گذاشت 💖',
        ],
    ];
}

function teacherMoodLabel(?string $mood): string
{
    if ($mood === null || $mood === '') {
        return 'ثبت نشده';
    }

    $catalog = teacherPsychologicalMoodCatalog();

    return $catalog[$mood]['label'] ?? $mood;
}

/** Full label for parent-facing views: label + clinical description. */
function teacherMoodFullLabel(string $mood): string
{
    if ($mood === '') {
        return 'مشاهده‌نشده';
    }
    $catalog = teacherPsychologicalMoodCatalog();
    if (!isset($catalog[$mood])) {
        return $mood;
    }

    return $catalog[$mood]['label'] . ' — ' . $catalog[$mood]['clinical_desc'];
}

function teacherMoodBadgeClass(?string $mood): string
{
    return match ($mood) {
        'happy'       => 'tp-badge-present',
        'normal'      => 'tp-badge-excused',
        'tired'       => 'tp-badge-late',
        'restless'    => 'tp-badge-late',
        'mixed'       => 'tp-badge-late',
        'challenging' => 'tp-badge-absent',
        default       => 'tp-badge-unrecorded',
    };
}

/**
 * Explicit options for the six observations stored on daily_reports.
 *
 * @return array<string, array{title:string, icon:string, domain:string, options:array<string,string>}>
 */
function teacherPsychologicalFactorsCatalog(): array
{
    return [
        'social_interaction' => [
            'title'   => 'تعامل اجتماعی و بازی با همسالان',
            'icon'    => '🧑‍🤝‍🧑',
            'domain'  => 'رشد اجتماعی',
            'options' => [
                'cooperative_play' => 'بازی مشارکتی و نوبت‌گیری با دوستان',
                'parallel_play'    => 'بازی موازی در کنار همسالان',
                'observing'        => 'مشاهده‌گر؛ در حال آشنایی با جمع',
                'needs_support'    => 'نیازمند میانجی‌گری مربی برای ورود به بازی',
            ],
        ],
        'focus_level' => [
            'title'   => 'تمرکز و کارکرد اجرایی',
            'icon'    => '🧩',
            'domain'  => 'رشد شناختی',
            'options' => [
                'deep_focus'       => 'تمرکز عمیق و اتمام فعالیت',
                'age_appropriate'  => 'تمرکز متناسب با سن در فعالیت‌ها',
                'needs_scaffolding' => 'با یادآوری گام‌به‌گام مربی متمرکز می‌شود',
                'easily_distracted' => 'امروز زود حواسش پرت می‌شد؛ نیازمند فعالیت کوتاه‌تر',
            ],
        ],
        'communication_style' => [
            'title'   => 'ارتباط و ابراز نیازها',
            'icon'    => '💬',
            'domain'  => 'رشد زبانی',
            'options' => [
                'expressive'   => 'پرگفتار و مشتاق گفت‌وگو و قصه‌گویی',
                'verbal_clear' => 'نیازها را روشن و کلامی بیان می‌کند',
                'emerging'     => 'در حال گسترش واژگان؛ گاهی با اشاره کمک می‌گیرد',
                'needs_support' => 'نیازمند حمایت مربی برای ابراز کلامی نیازها',
            ],
        ],
        'separation_adaptation' => [
            'title'   => 'سازگاری با جدایی صبحگاهی',
            'icon'    => '🌈',
            'domain'  => 'دلبستگی ایمن',
            'options' => [
                'independent'   => 'جدایی مستقل و با لبخند',
                'smooth'        => 'جدایی آرام با آیین خداحافظی کوتاه',
                'brief_anxiety' => 'دلتنگی کوتاه و آرام‌گیری سریع با هم‌تنظیمی',
                'needs_support' => 'نیازمند اطمینان خاطر بیشتر هنگام تحویل',
            ],
        ],
        'nutrition_status' => [
            'title'   => 'تغذیه و تجربه‌ی خوراکی',
            'icon'    => '🍎',
            'domain'  => 'سلامت جسمی',
            'options' => [
                'good_appetite'   => 'اشتهای خوب و تجربه‌ی مثبت از وعده‌ها',
                'normal_appetite' => 'خوردن متناسب و بدون فشار',
                'selective'       => 'انتخاب‌گر؛ در حال تجربه‌ی تدریجی خوراکی‌ها',
                'low_appetite'    => 'امروز کم‌اشتها بود (بدون اصرار از طرف مربی)',
            ],
        ],
        'rest_energy' => [
            'title'   => 'استراحت و سطح انرژی',
            'icon'    => '🔋',
            'domain'  => 'سلامت جسمی',
            'options' => [
                'energetic'  => 'پرانرژی و فعال در تمام روز',
                'balanced'   => 'انرژی متعادل و استراحت کافی',
                'sleepy'     => 'خواب‌آلود؛ از زمان استراحت بیشتر بهره برد',
                'low_energy' => 'کم‌انرژی؛ امروز با ریتم آرام‌تری همراه شد',
            ],
        ],
    ];
}

/** Option label of one factor value, for parent-facing views. */
function teacherPsychFactorLabel(string $factorKey, string $value): string
{
    if ($value === '') {
        return 'ثبت نشده';
    }

    $catalog = teacherPsychologicalFactorsCatalog();

    return $catalog[$factorKey]['options'][$value] ?? $value;
}

/**
 * Activities bank, grouped by developmental domain for the shared class report.
 *
 * @return array<string, list<string>>
 */
function teacherDevelopmentalActivitiesCatalog(): array
{
    return [
        'شناختی و حل مسئله' => [
            'پازل و جورچین متناسب با سن',
            'بازی دسته‌بندی رنگ و شکل',
            'قصه‌گویی تعاملی و پاسخ به پرسش‌های قصه',
        ],
        'حسی ـ حرکتی' => [
            'بازی با خمیر و مواد نرم',
            'مسیر حرکتی و تعادلی در حیاط',
            'نقاشی با انگشت و رنگ‌های طبیعی',
        ],
        'اجتماعی ـ هیجانی' => [
            'بازی نوبتی گروهی با هدایت مربی',
            'نمایش عروسکی درباره‌ی نام‌گذاری احساسات',
            'حلقه‌ی صبحگاهی و شنیدن صحبت دوستان',
        ],
        'زبان و سواد اولیه' => [
            'شعر و ترانه‌ی گروهی با حرکت',
            'بازی واژه و صداهای آغازین',
            'کتاب‌خوانی تصویری و گفت‌وگو درباره‌ی تصاویر',
        ],
    ];
}

/**
 * Ready-made, strength-based parent-note templates.
 *
 * @return list<array{title:string, text:string}>
 */
function teacherStrengthBasedNoteTemplates(): array
{
    return [
        [
            'title' => '🌟 توانمندی',
            'text'  => 'امروز شاهد تلاش و پشتکار زیبای فرزندتان بودیم؛ با تمرکز و لذت فعالیتش را به پایان رساند و از موفقیتش خوشحال بود.',
        ],
        [
            'title' => '🤗 همدلی',
            'text'  => 'امروز فرزندتان در کنار دوستانش مهربانی و همراهی نشان داد و در بازی‌های گروهی مشارکت دلنشینی داشت.',
        ],
        [
            'title' => '🌱 گام رشدی',
            'text'  => 'امروز یک گام تازه در مسیر استقلال فرزندتان دیدیم؛ با اطمینان بیشتری کارهایش را خودش انجام داد و ما این پیشرفت را با تحسین همراه کردیم.',
        ],
        [
            'title' => '💖 هم‌تنظیمی',
            'text'  => 'امروز لحظاتی نیازمند آرامش بیشتر بود که با حضور و همدلی مربی به‌خوبی سپری شد؛ در خانه نیز یک آیین آرام و ثابت قبل از خواب به او احساس امنیت می‌دهد.',
        ],
    ];
}

/**
 * Non-labeling dictionary: words to avoid in parent-facing text and
 * the descriptive, need-based replacement — the same pairs the
 * report form's language assistant suggests live while typing.
 *
 * @return list<array{avoid:string, use:string, reason:string}>
 */
function teacherNonLabelingDictionary(): array
{
    return [
        ['avoid' => 'لجباز',      'use' => 'دارای نیاز به خودمختاری و نیازمند پیش‌آگهی قبل از تغییر فعالیت', 'reason' => 'مقاومت کودک نشانه‌ی رشد خودمختاری است، نه لجبازی.'],
        ['avoid' => 'حرف گوش نکن', 'use' => 'نیازمند دستورالعمل‌های کوتاه، دیداری و گام‌به‌گام',              'reason' => 'کودک خردسال با هدایت ایجابی و روشن بهتر همراه می‌شود.'],
        ['avoid' => 'بیش‌فعال',   'use' => 'پرانرژی و نیازمند فعالیت‌های جنبشی-حرکتی',                        'reason' => '«بیش‌فعالی» تشخیص بالینی است و نباید به‌عنوان برچسب روزانه استفاده شود.'],
        ['avoid' => 'پرخاشگر',    'use' => 'در حال تمرین ابراز کلامی ناکامی و مدیریت هیجان با هدایت مربی',    'reason' => 'توصیف مهارت در حال یادگیری به‌جای برچسب زدن به شخصیت کودک.'],
        ['avoid' => 'بدغذا',      'use' => 'دارای حساسیت حسی-ذائقه‌ای و نیازمند تجربه‌ی بدون فشار خوراکی‌ها', 'reason' => 'پرهیز از ایجاد اضطراب تغذیه‌ای در کودک و والد.'],
        ['avoid' => 'بهانه‌گیر',  'use' => 'خسته یا نیازمند اطمینان خاطر عاطفی و هم‌تنظیمی',                  'reason' => 'رفتار کودک پیامِ یک نیاز زیستی یا هیجانی است.'],
        ['avoid' => 'شلوغ',       'use' => 'پرجنب‌وجوش و علاقه‌مند به بازی‌های حرکتی',                        'reason' => 'استفاده از توصیف رفتاری مثبت به‌جای برچسب منفی.'],
        ['avoid' => 'خجالتی',     'use' => 'مشاهده‌گر دقیق و نیازمند زمان بیشتر برای احساس امنیت در جمع',     'reason' => 'احترام به سرشت محتاط کودک بدون تثبیت برچسب کم‌رویی.'],
        ['avoid' => 'وابسته',     'use' => 'نیازمند اطمینان خاطر عاطفی در زمان جدایی',                        'reason' => 'پشتیبانی از دلبستگی ایمن کودک.'],
    ];
}
