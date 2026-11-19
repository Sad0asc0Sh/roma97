<?php
declare(strict_types=1);

/**
 * ROMA — Psychological & Developmental Daily Observation Register
 * (ثبت مشاهده روان‌شناختی، شاخص‌های رفتاری و گزارش رشدی روزانه کودک)
 *
 * Built on non-labeling, strength-based child psychology principles (CASEL SEL,
 * Attachment Theory, and Executive Function observation) AND engineered for
 * busy classroom ergonomics on both mobile and desktop:
 * - 3 View Modes:
 *   1) نمای فشرده هوشمند (Smart Accordion): 1-tap emotional state bar visible on every card, detailed 6 factors collapsible
 *   2) حالت تمرکز تک‌به‌تک (Focus Step-by-Step Wizard): Shows one child at a time with Prev/Next navigation (zero vertical scroll fatigue)
 *   3) نمای باز کامل (Expanded Desktop): Shows all details open simultaneously for large screens
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/classroom_activities.php';
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';

requireTeacherLogin();

$teacherId    = (int) $_SESSION['teacher_id'];
$teacherName  = (string) ($_SESSION['teacher_name'] ?? 'مربی');
$today        = date('Y-m-d');
$selectedDate = teacherValidOperationalDate((string) ($_GET['date'] ?? $today), 7) ?? $today;

$classrooms  = [];
$classroom   = null;
$classroomId = 0;
$children    = [];
$reports     = [];
$classActivity = '';

$moodCatalog    = teacherPsychologicalMoodCatalog();
$factorsCatalog = teacherPsychologicalFactorsCatalog();
$reportOptions = ['mood' => $moodCatalog] + array_map(static fn (array $factor): array => $factor['options'], $factorsCatalog);
$textFields = ['strengths_observed' => 500, 'coping_strategy' => 500, 'activities' => null, 'notes' => null];
$emptyReport = array_fill_keys(array_keys($reportOptions + $textFields), null);

$quickMoodLabels = [
    'happy'    => '🌟 شاداب و خودانگیخته',
    'normal'   => '🌿 آرام و متمرکز',
    'tired'    => '🌙 نیازمند استراحت',
    'restless' => '🌱 نیازمند هم‌تنظیمی',
];

$successMessage = getFlash('success');
$errorMessage   = getFlash('error');

try {
    initializeTeachersTables();
    initializeNotificationTables();
    $pdo = getDb();

    $ctx         = teacherResolveClassroomContext($pdo, $teacherId, isPostRequest());
    $classrooms  = $ctx['classrooms'];
    $classroom   = $ctx['active'];
    $classroomId = $ctx['active_id'];

    if ($classroom !== null) {
        ensureClassroomActivitiesTables($pdo);
        $kidsStmt = $pdo->prepare(
            <<<'SQL'
SELECT c.id, c.first_name, c.last_name, c.preferred_name, c.photo, c.allergies, c.medical_notes, a.status AS attendance_status
FROM child_classroom cc
INNER JOIN children c ON c.id = cc.child_id
LEFT JOIN attendance a ON a.child_id = c.id AND a.attendance_date = :attendance_date
WHERE cc.classroom_id = :cid AND c.status = 'active'
ORDER BY c.first_name, c.last_name
SQL
        );
        $kidsStmt->execute(['cid' => $classroomId, 'attendance_date' => $selectedDate]);
        $children = $kidsStmt->fetchAll();

        $allowedChildIds = array_map(static fn (array $c): int => (int) $c['id'], $children);

        if (isPostRequest()) {
            $postDateInput = $_POST['report_date'] ?? null;
            $postDate = is_string($postDateInput) ? teacherValidOperationalDate($postDateInput, 7) : null;
            $redirectUrl = url('teacher/report.php?classroom_id=' . $classroomId . '&date=' . rawurlencode($postDate ?? $selectedDate));

            $csrfToken = $_POST['csrf_token'] ?? null;
            if (!is_string($csrfToken) || !validateCsrfToken($csrfToken)) {
                setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
                redirect($redirectUrl);
            }
            if ($postDate === null) {
                setFlash('error', 'گزارش ذخیره نشد. تاریخ باید مربوط به امروز یا حداکثر ۷ روز گذشته باشد.');
                redirect($redirectUrl);
            }

            $activityInput = $_POST['class_activity'] ?? null;
            $savingClassActivity = array_key_exists('class_activity', $_POST);
            if ($savingClassActivity && (!is_string($activityInput) || mb_strlen($activityInput, 'UTF-8') > 3000)) {
                setFlash('error', 'فعالیت مشترک کلاس باید حداکثر ۳۰۰۰ نویسه باشد.');
                redirect($redirectUrl);
            }
            $submittedFields = array_filter(array_intersect_key($_POST, $emptyReport), 'is_array');

            $upsertSql = <<<'SQL'
INSERT INTO daily_reports (
    teacher_id, classroom_id, child_id, report_date,
    mood, social_interaction, focus_level, communication_style,
    separation_adaptation, nutrition_status, rest_energy,
    strengths_observed, coping_strategy, activities, notes
) VALUES (
    :tid, :cid, :child_id, :rdate,
    :mood, :social_interaction, :focus_level, :communication_style,
    :separation_adaptation, :nutrition_status, :rest_energy,
    :strengths_observed, :coping_strategy, :activities, :notes
)
ON DUPLICATE KEY UPDATE
    teacher_id            = VALUES(teacher_id),
    classroom_id          = VALUES(classroom_id),
    mood                  = VALUES(mood),
    social_interaction    = VALUES(social_interaction),
    focus_level           = VALUES(focus_level),
    communication_style   = VALUES(communication_style),
    separation_adaptation = VALUES(separation_adaptation),
    nutrition_status      = VALUES(nutrition_status),
    rest_energy           = VALUES(rest_energy),
    strengths_observed    = VALUES(strengths_observed),
    coping_strategy       = VALUES(coping_strategy),
    activities            = VALUES(activities),
    notes                 = VALUES(notes)
SQL;

            try {
                $pdo->beginTransaction();
                $classLock = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? AND teacher_id = ? FOR UPDATE');
                $classLock->execute([$classroomId, $teacherId]);
                if ($classLock->fetchColumn() === false) {
                    throw new InvalidArgumentException('این کلاس دیگر به شما اختصاص ندارد.');
                }

                if ($savingClassActivity) {
                    $classActivity = trim($activityInput);
                    saveClassroomActivity($pdo, $classroomId, $postDate, $classActivity);
                    $pdo->commit();
                    recordAudit('classroom.activity.save', 'classroom', $classroomId, [
                        'date' => $postDate,
                        'cleared' => $classActivity === '',
                    ]);
                    setFlash('success', $classActivity === '' ? 'فعالیت مشترک کلاس پاک شد.' : 'فعالیت مشترک کلاس ذخیره شد.');
                    redirect($redirectUrl);
                }

                $existingStmt = $pdo->prepare(
                    'SELECT child_id, mood, social_interaction, focus_level, communication_style,
                            separation_adaptation, nutrition_status, rest_energy,
                            strengths_observed, coping_strategy, activities, notes
                     FROM daily_reports
                     WHERE classroom_id = :cid AND report_date = :rdate'
                );
                $existingStmt->execute([':cid' => $classroomId, ':rdate' => $postDate]);

                $previousReports = [];
                foreach ($existingStmt->fetchAll() as $prev) {
                    $previousReports[(int) $prev['child_id']] = array_replace($emptyReport, array_intersect_key($prev, $emptyReport));
                }

                $uStmt = $pdo->prepare($upsertSql);
                $clearStmt = $pdo->prepare('DELETE FROM daily_reports WHERE classroom_id = ? AND child_id = ? AND report_date = ?');

                $saved = 0;
                /** @var array<int, string|null> $changedChildren */
                $changedChildren = [];

                foreach ($allowedChildIds as $childId) {
                    $before = $previousReports[$childId] ?? $emptyReport;
                    $report = $before;
                    foreach ($submittedFields as $field => $values) {
                        // Omitted fields can come from an unchanged card or a partial submit.
                        $value = $values[$childId] ?? null;
                        if (!is_string($value)) {
                            continue;
                        }
                        $value = trim($value);
                        if ($value !== '' && isset($reportOptions[$field]) && !isset($reportOptions[$field][$value])) {
                            continue;
                        }
                        $limit = $textFields[$field] ?? null;
                        $report[$field] = $value === '' ? null : ($limit === null ? $value : mb_substr($value, 0, $limit, 'UTF-8'));
                    }
                    if ($report === $before) {
                        continue;
                    }
                    $saved++;
                    if ($report === $emptyReport) {
                        $clearStmt->execute([$classroomId, $childId, $postDate]);
                        continue;
                    }
                    $uStmt->execute($report + [
                        ':tid'        => $teacherId,
                        ':cid'        => $classroomId,
                        ':child_id'   => $childId,
                        ':rdate'      => $postDate,
                    ]);
                    $changedChildren[$childId] = $report['mood'];
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $notifyResults = [];

            if ($changedChildren !== []) {
                $childIdList = implode(',', array_map('intval', array_keys($changedChildren)));
                $recipientStmt = $pdo->query("
                    SELECT c.id AS child_id, c.first_name AS c_first, c.last_name AS c_last,
                           p.id AS parent_id
                    FROM children c
                    INNER JOIN parents p ON p.id = c.parent_id
                    WHERE c.id IN ({$childIdList})
                ");
                $recipients = $recipientStmt ? $recipientStmt->fetchAll() : [];

                $dateFa = shamsiDate($postDate);
                $detailedAll = function_exists('smsDetailedReportEnabled')
                    ? smsDetailedReportEnabled($pdo)
                    : ((string) getSetting('sms_detailed_report_enabled', '0') === '1');

                foreach ($recipients as $row) {
                    $childId   = (int) $row['child_id'];
                    $parentId  = (int) $row['parent_id'];
                    $childName = trim((string) $row['c_first'] . ' ' . (string) $row['c_last']);
                    $mood      = $changedChildren[$childId] ?? '';
                    $moodFa    = $moodCatalog[$mood]['sms_phrase'] ?? 'گزارش امروز در پنل والدین در دسترس است.';

                    $eventKey = $detailedAll ? 'daily_report_vip' : 'daily_report_brief';
                    $args = $detailedAll
                        ? [$childName, $dateFa, $moodFa]
                        : [$childName, siteName()];

                    $notifyResults[] = notifyParent($parentId, $eventKey, [
                        'args'        => $args,
                        'dedup_key'   => "daily_report:{$childId}:{$postDate}",
                        'sender_type' => 'teacher',
                        'sender_id'   => $teacherId,
                    ]);
                }

                smsQueueFlushInline($pdo);
            }

            recordAudit('daily_report.save', 'classroom', $classroomId, [
                'date'     => $postDate,
                'children' => $saved,
                'notified' => count($notifyResults),
            ]);

            $flash = $saved > 0
                ? 'گزارش روزانه ' . persianNumber($saved) . ' کودک به‌روز شد.'
                : 'تغییری در گزارش‌ها ثبت نشد.';

            if ($changedChildren !== []) {
                $summary = summarizeNotificationResults($notifyResults);
                if ($summary !== '') {
                    $flash .= ' ' . $summary;
                }
            }

            setFlash('success', $flash);
            redirect($redirectUrl);
        }

        // Load existing reports for the selected date
        $rStmt = $pdo->prepare(
            'SELECT child_id, mood, social_interaction, focus_level, communication_style,
                    separation_adaptation, nutrition_status, rest_energy,
                    strengths_observed, coping_strategy, activities, notes
             FROM daily_reports
             WHERE classroom_id = :cid AND report_date = :rdate'
        );
        $rStmt->execute([':cid' => $classroomId, ':rdate' => $selectedDate]);
        foreach ($rStmt->fetchAll() as $row) {
            $reports[(int) $row['child_id']] = $row;
        }

        $activityStmt = $pdo->prepare('SELECT activity FROM classroom_daily_activities WHERE classroom_id = ? AND activity_date = ?');
        $activityStmt->execute([$classroomId, $selectedDate]);
        $classActivity = (string) $activityStmt->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('teacher/report failed: ' . $e->getMessage());
    if (!$errorMessage) {
        $errorMessage = $e instanceof InvalidArgumentException ? $e->getMessage() : 'ذخیره یا بارگذاری گزارش روزانه انجام نشد. لطفاً دوباره تلاش کنید.';
    }
}

$devActivitiesCatalog = teacherDevelopmentalActivitiesCatalog();
$noteTemplates        = teacherStrengthBasedNoteTemplates();
$nonLabelingDict      = teacherNonLabelingDictionary();

$isToday       = $selectedDate === $today;
$totalChildren = count($children);
$attendingChildren = array_filter($children, static fn (array $child): bool => in_array($child['attendance_status'], ['present', 'late'], true));
$reportedCount = count(array_filter($attendingChildren, static fn (array $child): bool => isset($reports[$child['id']])));

$recentDays = [];
$todayObj   = new DateTimeImmutable('today');
for ($d = 0; $d <= 7; $d++) {
    $dayObj = $todayObj->modify("-{$d} days");
    $ymd    = $dayObj->format('Y-m-d');
    $recentDays[] = [
        'ymd'   => $ymd,
        'label' => $d === 0 ? 'امروز' : ($d === 1 ? 'دیروز' : persianDayName($ymd)),
        'date'  => shamsiDate($ymd),
    ];
}

$pageTitle = 'ارزیابی روان‌شناختی و گزارش روزانه | پنل مربیان | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<div class="tp-page-header">
    <div>
        <h1>🧠 مشاهده روان‌شناختی و گزارش رشدی روزانه</h1>
        <p class="tp-page-subtitle">
            <?php if ($classroom !== null): ?>
                کلاس <strong><?= e((string) $classroom['name']) ?></strong> ·
                <?= e(shamsiDate($selectedDate, 'with_day')) ?>
                <?= $isToday ? ' (امروز)' : '' ?> ·
                گزارش فردی <strong><?= e(persianNumber($reportedCount)) ?></strong> از <strong><?= e(persianNumber(count($attendingChildren))) ?></strong> کودک حاضر یا باتأخیر
            <?php else: ?>
                هنوز کلاسی به شما اختصاص داده نشده است.
            <?php endif; ?>
        </p>
    </div>

    <div class="tp-page-actions">
        <?php if ($classroom !== null): ?>
            <form method="get" action="<?= e(url('teacher/report.php')) ?>" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="classroom_id" value="<?= e($classroomId) ?>">
                <label for="repDate" class="sr-only">تاریخ گزارش</label>
                <input type="date" id="repDate" name="date" class="tp-input" style="width:auto;"
                    value="<?= e($selectedDate) ?>"
                    max="<?= e($today) ?>"
                    min="<?= e($todayObj->modify('-7 days')->format('Y-m-d')) ?>"
                    onchange="this.form.submit()">
                <?php if (!$isToday): ?>
                    <a href="<?= e(url('teacher/report.php?classroom_id=' . $classroomId)) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">امروز</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <button type="button" class="tp-btn tp-btn-secondary tp-btn-sm" onclick="togglePsychGuide()">
            📖 راهنمای ادبیات بدون برچسب
        </button>
    </div>
</div>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<!-- Collapsible Psychological Non-Labeling Guide -->
<section class="tp-card" id="psychGuideBox" style="display:none;border-inline-start:5px solid var(--tp-primary);background:var(--tp-surface-soft);">
    <div class="tp-card-header">
        <h2>🧭 منشور مشاهده روان‌شناختی کودک و پرهیز از برچسب‌زنی (Non-Labeling Observation)</h2>
        <button type="button" class="tp-btn tp-btn-secondary tp-btn-sm" onclick="togglePsychGuide()">بستن راهنما</button>
    </div>
    <p style="margin:0 0 14px;font-size:0.88rem;color:var(--tp-text-secondary);">
        در روان‌شناسی رشد، گزارش مربی باید <strong>توصیفِ عینی رفتار و نیاز هیجانی کودک</strong> باشد، نه برچسب زدن به شخصیت او. دستیار هوشمند این صفحه نیز هنگام تایپ، واژگان برچسب‌زننده را تشخیص داده و جایگزین علمی پیشنهاد می‌دهد:
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;">
        <?php foreach ($nonLabelingDict as $dictItem): ?>
            <div style="background:var(--tp-surface);border:1px solid var(--tp-border);border-radius:var(--tp-radius-sm);padding:12px 14px;">
                <div style="font-size:0.8rem;color:var(--tp-absent);font-weight:700;margin-bottom:4px;">
                    ❌ پرهیز از برچسب: «<?= e($dictItem['avoid']) ?>»
                </div>
                <div style="font-size:0.84rem;color:var(--tp-present);font-weight:800;margin-bottom:6px;">
                    ✅ جایگزین علمی و توصیفی: «<?= e($dictItem['use']) ?>»
                </div>
                <div style="font-size:0.76rem;color:var(--tp-text-muted);line-height:1.6;">
                    💡 چرایی روان‌شناختی: <?= e($dictItem['reason']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($classroom === null): ?>
    <div class="tp-empty-state">
        <h2>هیچ کلاسی به شما اختصاص داده نشده است</h2>
        <p>برای ثبت مشاهدات روان‌شناختی و گزارش روزانه، ابتدا باید توسط مدیریت به یک کلاس اختصاص داده شوید.</p>
        <a href="<?= e(url('teacher/index.php')) ?>" class="tp-btn tp-btn-secondary">بازگشت به داشبورد</a>
    </div>
<?php else: ?>

    <!-- 7-Day Quick Date Strip -->
    <nav class="tp-date-strip" aria-label="انتخاب سریع روز گزارش">
        <?php foreach ($recentDays as $dayChip): ?>
            <a href="<?= e(url('teacher/report.php?classroom_id=' . $classroomId . '&date=' . rawurlencode($dayChip['ymd']))) ?>"
               class="tp-day-chip <?= $dayChip['ymd'] === $selectedDate ? 'active' : '' ?>">
                <span><?= e($dayChip['label']) ?></span>
                <strong><?= e($dayChip['date']) ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="<?= e(url('teacher/report.php?date=' . rawurlencode($selectedDate))) ?>" class="tp-card tp-class-activity-form">
        <input type="hidden" name="classroom_id" value="<?= e($classroomId) ?>">
        <input type="hidden" name="report_date" value="<?= e($selectedDate) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
        <div class="tp-card-header">
            <h2><label for="class_activity">فعالیت مشترک کلاس</label></h2>
        </div>
        <p id="class_activity_help" class="tp-class-activity-help">پس از ثبت حضور، فعالیت را ذخیره کنید تا برای خانوادهٔ کودکان حاضر و باتأخیر نمایش داده شود.</p>
        <textarea id="class_activity" name="class_activity" class="tp-textarea" rows="3" maxlength="3000" aria-describedby="class_activity_help"><?= e($classActivity) ?></textarea>
        <details class="tp-class-activity-bank">
            <summary>بانک فعالیت‌های کلاسی</summary>
            <div class="tp-class-activity-suggestions">
                <?php foreach ($devActivitiesCatalog as $domainTitle => $domainActs): ?>
                    <div>
                        <strong><?= e($domainTitle) ?></strong>
                        <div class="tp-class-activity-options">
                            <?php foreach ($domainActs as $actItem): ?>
                                <button type="button" class="tp-template-chip" data-class-activity="<?= e($actItem) ?>"><?= e($actItem) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
        <button type="submit" class="tp-btn tp-btn-primary">ذخیره فعالیت کلاس</button>
    </form>
    <script>
    document.querySelector('.tp-class-activity-bank').addEventListener('click', function (event) {
        const suggestion = event.target.closest('[data-class-activity]');
        if (!suggestion) return;
        const field = document.getElementById('class_activity');
        const text = field.value.trim();
        const activity = suggestion.dataset.classActivity;
        if (!text.split('، ').includes(activity)) field.value = text ? text + '، ' + activity : activity;
    });
    </script>
<?php endif; ?>

<?php if ($classroom !== null && $children === []): ?>
    <div class="tp-empty-state">
        <h2>هنوز کودکی در این کلاس ثبت‌نام نشده است</h2>
    </div>
<?php elseif ($children !== []): ?>
    <!-- Busy-Classroom Ergonomic Mode Switcher & Filter Toolbar -->
    <div class="tp-toolbar" style="flex-direction:column;align-items:stretch;gap:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <strong style="font-size:0.82rem;">🎛️ حالت کار در کلاس شلوغ:</strong>
                <div class="tp-mode-switch" role="group" aria-label="حالت نمایش فرم">
                    <button type="button" class="tp-mode-btn active" id="mode-btn-accordion" onclick="setReportViewMode('accordion')">
                        ⚡ نمای فشرده سریع (آکاردئونی)
                    </button>
                    <button type="button" class="tp-mode-btn" id="mode-btn-wizard" onclick="setReportViewMode('wizard')">
                        🎯 حالت تمرکز تک‌به‌تک (ویژه موبایل)
                    </button>
                    <button type="button" class="tp-mode-btn" id="mode-btn-expanded" onclick="setReportViewMode('expanded')">
                        🖥️ نمای باز کامل (دسکتاپ)
                    </button>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="search" id="repSearchInput" class="tp-input" style="max-width:210px;padding:7px 12px;"
                       placeholder="🔍 جستجوی نام کودک..." oninput="applyReportFilters()">
            </div>
        </div>
    </div>

    <!-- Step-by-Step Focus Wizard Navigation Bar (Shown in 'wizard' mode) -->
    <div class="tp-wizard-bar" id="reportWizardBar" style="display:none;">
        <button type="button" class="tp-btn tp-btn-secondary tp-btn-sm" onclick="stepWizardChild(-1)">‹ کودک قبلی</button>
        <div class="tp-wizard-progress" id="wizardProgressText">کودک ۱ از <?= e(persianNumber((string) $totalChildren)) ?></div>
        <button type="button" class="tp-btn tp-btn-primary tp-btn-sm" onclick="stepWizardChild(1)">کودک بعدی ›</button>
    </div>

    <form method="post" action="<?= e(url('teacher/report.php?date=' . rawurlencode($selectedDate))) ?>" class="report-form" id="psychReportForm">
        <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
        <input type="hidden" name="report_date" value="<?= e($selectedDate) ?>">

        <div style="display:flex;flex-direction:column;gap:14px;" id="reportCardsContainer">
            <?php foreach ($children as $idx => $child): ?>
                <?php
                $cid          = (int) $child['id'];
                $existing     = $reports[$cid] ?? null;
                $moodCurrent  = (string) ($existing['mood'] ?? '');
                $strCurrent   = (string) ($existing['strengths_observed'] ?? '');
                $copCurrent   = (string) ($existing['coping_strategy'] ?? '');
                $actCurrent   = (string) ($existing['activities'] ?? '');
                $notesCurrent = (string) ($existing['notes'] ?? '');

                $displayName  = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                $preferred    = trim((string) ($child['preferred_name'] ?? ''));
                $photoUrl     = !empty($child['photo']) ? url((string) $child['photo']) : '';
                $hasHealth    = trim((string) ($child['allergies'] ?? '')) !== '' || trim((string) ($child['medical_notes'] ?? '')) !== '';
                $isFirstCard  = $idx === 0;
                ?>
                <article class="tp-report-card report-child-card <?= $existing !== null ? 'tp-report-card--saved' : '' ?>"
                         id="child-report-<?= $cid ?>"
                         data-child-id="<?= $cid ?>"
                         data-index="<?= $idx ?>"
                         data-name="<?= e($displayName) ?>">
                    <!-- Child Card Header -->
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <?php if ($photoUrl !== ''): ?>
                                <img src="<?= e($photoUrl) ?>" alt="<?= e($displayName) ?>" class="tp-child-avatar" style="width:46px;height:46px;border-radius:14px;font-size:1.05rem;">
                            <?php else: ?>
                                <div class="tp-child-avatar" style="width:46px;height:46px;border-radius:14px;font-size:1.05rem;"><?= e(userInitial((string) $child['first_name'])) ?></div>
                            <?php endif; ?>
                            <div>
                                <h3 style="margin:0;font-size:1.06rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <a href="<?= e(url('teacher/child-detail.php?id=' . $cid)) ?>" style="color:inherit;text-decoration:none;"><?= e($displayName) ?></a>
                                    <?php if ($preferred !== '' && $preferred !== $child['first_name']): ?>
                                        <span style="font-size:0.78rem;color:var(--tp-text-muted);font-weight:600;">«<?= e($preferred) ?>»</span>
                                    <?php endif; ?>
                                </h3>
                                <?php if ($hasHealth): ?>
                                    <div style="font-size:0.75rem;color:var(--tp-absent);font-weight:700;margin-top:2px;">
                                        ⚠️ ملاحظه سلامت/حساسیت: <?= e((string) ($child['allergies'] ?: $child['medical_notes'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <?php if ($existing !== null): ?>
                                <span class="tp-badge tp-badge-present">ثبت شده ✓</span>
                            <?php else: ?>
                                <span class="tp-badge tp-badge-unrecorded">در انتظار ثبت</span>
                            <?php endif; ?>
                            <button type="button" class="tp-accordion-toggle" id="acc-btn-<?= $cid ?>" onclick="toggleChildCardBody(<?= $cid ?>)">
                                <span id="acc-lbl-<?= $cid ?>"><?= $isFirstCard ? 'بستن جزئیات ۶ عامل روان‌شناختی ▴' : 'باز کردن ۶ عامل روان‌شناختی و یادداشت ▾' ?></span>
                            </button>
                        </div>
                    </div>

                    <!-- 1-Tap Quick Emotional Regulation Bar (Always visible even when collapsed!) -->
                    <div>
                        <span style="font-size:0.76rem;font-weight:700;color:var(--tp-text-secondary);display:block;margin-bottom:5px;">
                            ۱. انتخاب سریع وضعیت تنظیم هیجانی امروز کودک (با یک لمس):
                        </span>
                        <div class="tp-quick-mood-bar" id="qmood-bar-<?= $cid ?>">
                            <?php foreach ($quickMoodLabels as $mKey => $mShortLabel): ?>
                                <button type="button"
                                        class="tp-quick-mood-btn <?= $moodCurrent === $mKey ? 'active' : '' ?>"
                                        data-mood="<?= e($mKey) ?>"
                                        onclick="selectQuickMood(<?= $cid ?>, '<?= e($mKey) ?>')">
                                    <?= e($mShortLabel) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Collapsible Detailed Observation Body -->
                    <div class="tp-report-body-collapsible <?= $isFirstCard ? 'is-open' : '' ?>" id="card-body-<?= $cid ?>">
                        <!-- Hidden/Synced Select for Emotional Regulation + Coach Co-Regulation Tip -->
                        <div class="tp-form-group" style="margin-bottom:2px;">
                            <label for="mood_<?= $cid ?>" class="tp-label">شرح بالینی وضعیت تنظیم هیجانی و راهبرد هم‌تنظیمی مربی</label>
                            <select id="mood_<?= $cid ?>" name="mood[<?= $cid ?>]" class="tp-select child-mood-select" onchange="selectQuickMood(<?= $cid ?>, this.value)">
                                <option value="">مشاهده‌نشده</option>
                                <?php foreach ($moodCatalog as $mKey => $mMeta): ?>
                                    <option value="<?= e($mKey) ?>" <?= $moodCurrent === $mKey ? 'selected' : '' ?>>
                                        <?= e($mMeta['label']) ?> — (<?= e($mMeta['clinical_desc']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="mood_tip_<?= $cid ?>" style="font-size:0.77rem;color:var(--tp-primary);background:var(--tp-primary-tint);padding:7px 12px;border-radius:8px;margin-top:4px;">
                                <?= e($moodCatalog[$moodCurrent]['coach_tip'] ?? '') ?>
                            </div>
                        </div>

                        <!-- Section 2: 6 Core Psychological & Behavioral Factors Grid -->
                        <div style="background:var(--tp-surface-soft);border:1px solid var(--tp-border-light);border-radius:var(--tp-radius-md);padding:14px 16px;">
                            <strong style="display:block;font-size:0.86rem;margin-bottom:10px;color:var(--tp-text);">
                                ۲. شاخص‌های ۶گانه مشاهده رفتاری و رشدی کودک (بر پایه استانداردهای روان‌شناسی رشد):
                            </strong>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;">
                                <?php
                                foreach ($factorsCatalog as $fKey => $fMeta):
                                    $selectedOpt = (string) ($existing[$fKey] ?? '');
                                ?>
                                    <div class="tp-form-group" style="margin:0;">
                                        <label for="<?= e($fKey . '_' . $cid) ?>" class="tp-label" style="font-size:0.78rem;">
                                            <?= e($fMeta['icon']) ?> <?= e($fMeta['title']) ?>
                                            <small style="color:var(--tp-text-muted);font-weight:500;">(<?= e($fMeta['domain']) ?>)</small>
                                        </label>
                                        <select id="<?= e($fKey . '_' . $cid) ?>" name="<?= e($fKey) ?>[<?= $cid ?>]" class="tp-select" style="font-size:0.81rem;padding:8px 10px;">
                                            <option value="">مشاهده‌نشده</option>
                                            <?php foreach ($fMeta['options'] as $optVal => $optLabel): ?>
                                                <option value="<?= e($optVal) ?>" <?= $selectedOpt === $optVal ? 'selected' : '' ?>>
                                                    <?= e($optLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Section 3: Strengths, Coping Strategy, Activities & Parent Feedback -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
                            <div class="tp-form-group" style="margin:0;">
                                <label for="strengths_<?= $cid ?>" class="tp-label">۳. نقطه قوت، توانمندی یا رفتار مثبت مشاهده‌شده امروز</label>
                                <input type="text" id="strengths_<?= $cid ?>" name="strengths_observed[<?= $cid ?>]"
                                       class="tp-input psych-monitored-input" data-child="<?= $cid ?>" maxlength="500"
                                       placeholder="مثال: شریک شدن اسباب‌بازی با دوست، تمرکز عالی در پازل"
                                       value="<?= e($strCurrent) ?>">
                            </div>

                            <div class="tp-form-group" style="margin:0;">
                                <label for="coping_<?= $cid ?>" class="tp-label">۴. اقدام حمایتی / هم‌تنظیمی مربی و پیشنهاد برای خانه</label>
                                <input type="text" id="coping_<?= $cid ?>" name="coping_strategy[<?= $cid ?>]"
                                       class="tp-input psych-monitored-input" data-child="<?= $cid ?>" maxlength="500"
                                       placeholder="مثال: پیش‌آگهی ۵ دقیقه‌ای قبل از جمع کردن بازی‌ها"
                                       value="<?= e($copCurrent) ?>">
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
                            <div class="tp-form-group" style="margin:0;">
                                <label for="activities_<?= $cid ?>" class="tp-label">۵. فعالیت‌های فردی کودک</label>
                                <textarea id="activities_<?= $cid ?>" name="activities[<?= $cid ?>]" class="tp-textarea" rows="2"><?= e($actCurrent) ?></textarea>
                            </div>

                            <div class="tp-form-group" style="margin:0;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <label for="notes_<?= $cid ?>" class="tp-label">۶. روایت توصیفی و پیام همدلانه برای والدین (بدون برچسب)</label>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                        <?php foreach ($noteTemplates as $ntIdx => $ntItem): ?>
                                            <button type="button" class="tp-template-chip" style="font-size:0.7rem;padding:2px 8px;"
                                                    onclick="fillNoteForChild(<?= $cid ?>, <?= e(json_encode($ntItem['text'], JSON_UNESCAPED_UNICODE)) ?>)">
                                                <?= e($ntItem['title']) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <textarea id="notes_<?= $cid ?>" name="notes[<?= $cid ?>]" class="tp-textarea psych-monitored-input" data-child="<?= $cid ?>" rows="2" placeholder="توصیف عینی تجربه امروز کودک با لحن محترمانه و آرامش‌بخش برای والدین..."><?= e($notesCurrent) ?></textarea>
                            </div>
                        </div>

                        <!-- Live Psychological Language Assistant Alert Box -->
                        <div id="psych_warn_<?= $cid ?>" style="display:none;background:var(--tp-late-bg);border:1px solid var(--tp-late);border-inline-start:4px solid var(--tp-late);padding:10px 14px;border-radius:10px;font-size:0.8rem;color:var(--tp-text);"></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="tp-sticky-save-bar">
            <div style="display:flex;gap:10px;align-items:center;">
                <a href="<?= e(url('teacher/index.php')) ?>" class="tp-btn tp-btn-secondary">انصراف</a>
                <button type="submit" class="tp-btn tp-btn-primary">ذخیره گزارش‌های فردی</button>
            </div>
        </div>
    </form>

    <script>
    var MOOD_TIPS = <?= json_encode(array_map(static fn(array $m): string => $m['coach_tip'], $moodCatalog), JSON_UNESCAPED_UNICODE) ?>;
    var currentReportMode = 'accordion';
    var currentWizardIndex = 0;

    const reportForm = document.getElementById('psychReportForm');
    const initialReportValues = new Map(
        Array.from(reportForm.querySelectorAll('.report-child-card [name]'), field => [field, field.value])
    );
    reportForm.addEventListener('formdata', function (event) {
        for (const [field, initialValue] of initialReportValues) {
            if (field.value === initialValue) event.formData.delete(field.name);
        }
    });

    var PSYCH_REPLACEMENTS = [
        { bad: 'لجباز', good: 'دارای نیاز به خودمختاری و نیازمند پیش‌آگهی قبل از تغییر فعالیت', reason: 'مقاومت کودک نشانه رشد خودمختاری است، نه لجبازی.' },
        { bad: 'حرف گوش نکن', good: 'نیازمند دستورالعمل‌های کوتاه، دیداری و گام‌به‌گام', reason: 'کودک خردسال با هدایت ایجابی و روشن بهتر همراه می‌شود.' },
        { bad: 'بیش فعال', good: 'پرانرژی و نیازمند فعالیت‌های جنبشی-حرکتی', reason: '«بیش‌فعالی» تشخیص بالینی است و نباید به‌عنوان برچسب روزانه استفاده شود.' },
        { bad: 'بیش‌فعال', good: 'پرانرژی و نیازمند فعالیت‌های جنبشی-حرکتی', reason: '«بیش‌فعالی» تشخیص بالینی است و نباید به‌عنوان برچسب روزانه استفاده شود.' },
        { bad: 'پرخاشگر', good: 'در حال تمرین ابراز کلامی ناکامی و مدیریت هیجان با هدایت مربی', reason: 'توصیف مهارت در حال یادگیری به‌جای برچسب زدن به شخصیت کودک.' },
        { bad: 'بدغذا', good: 'دارای حساسیت حسی-ذائقه‌ای و نیازمند تجربه بدون فشار خوراکی‌ها', reason: 'پرهیز از ایجاد اضطراب تغذیه‌ای در کودک و والد.' },
        { bad: 'بهانه گیر', good: 'خسته یا نیازمند اطمینان خاطر عاطفی و هم‌تنظیمی', reason: 'رفتار کودک پیامِ یک نیاز زیستی یا هیجانی است.' },
        { bad: 'بهانه‌گیر', good: 'خسته یا نیازمند اطمینان خاطر عاطفی و هم‌تنظیمی', reason: 'رفتار کودک پیامِ یک نیاز زیستی یا هیجانی است.' },
        { bad: 'شلوغ', good: 'پرجنب‌وجوش و علاقه‌مند به بازی‌های حرکتی', reason: 'استفاده از توصیف رفتاری مثبت به‌جای برچسب منفی.' },
        { bad: 'خجالتی', good: 'مشاهده‌گر دقیق و نیازمند زمان بیشتر برای احساس امنیت در جمع', reason: 'احترام به سرشت محتاط کودک بدون تثبیت برچسب کم‌رویی.' },
        { bad: 'وابسته', good: 'نیازمند اطمینان خاطر عاطفی در زمان جدایی', reason: 'پشتیبانی از دلبستگی ایمن کودک.' }
    ];

    function toPersianDigits(value) {
        var map = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(value).replace(/\d/g, function (d) { return map[Number(d)]; });
    }

    function togglePsychGuide() {
        var box = document.getElementById('psychGuideBox');
        if (!box) return;
        box.style.display = box.style.display === 'none' ? 'block' : 'none';
    }

    function toggleChildCardBody(childId) {
        var body = document.getElementById('card-body-' + childId);
        var lbl  = document.getElementById('acc-lbl-' + childId);
        if (!body) return;
        var isOpen = body.classList.toggle('is-open');
        if (lbl) {
            lbl.textContent = isOpen ? 'بستن جزئیات ۶ عامل روان‌شناختی ▴' : 'باز کردن ۶ عامل روان‌شناختی و یادداشت ▾';
        }
    }

    function selectQuickMood(childId, moodVal) {
        var sel = document.getElementById('mood_' + childId);
        if (sel) { sel.value = moodVal; sel.dispatchEvent(new Event('input', {bubbles: true})); }
        var bar = document.getElementById('qmood-bar-' + childId);
        if (bar) {
            bar.querySelectorAll('.tp-quick-mood-btn').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-mood') === moodVal);
            });
        }
        updateMoodGuidance(childId, moodVal);
    }

    function updateMoodGuidance(childId, moodVal) {
        var el = document.getElementById('mood_tip_' + childId);
        if (el) el.textContent = MOOD_TIPS[moodVal] || '';
    }

    function setReportViewMode(mode) {
        currentReportMode = mode;
        ['accordion', 'wizard', 'expanded'].forEach(function (m) {
            var btn = document.getElementById('mode-btn-' + m);
            if (btn) btn.classList.toggle('active', m === mode);
        });

        var wizardBar = document.getElementById('reportWizardBar');
        var cards = document.querySelectorAll('.report-child-card');

        if (mode === 'wizard') {
            if (wizardBar) wizardBar.style.display = 'flex';
            currentWizardIndex = 0;
            renderWizardStep();
        } else {
            if (wizardBar) wizardBar.style.display = 'none';
            cards.forEach(function (card, idx) {
                card.style.display = '';
                var cid = card.getAttribute('data-child-id');
                var body = document.getElementById('card-body-' + cid);
                var lbl  = document.getElementById('acc-lbl-' + cid);
                if (!body) return;
                var open = (mode === 'expanded') || (mode === 'accordion' && idx === 0);
                body.classList.toggle('is-open', open);
                if (lbl) {
                    lbl.textContent = open ? 'بستن جزئیات ۶ عامل روان‌شناختی ▴' : 'باز کردن ۶ عامل روان‌شناختی و یادداشت ▾';
                }
            });
            applyReportFilters();
        }
    }

    function renderWizardStep() {
        var cards = Array.from(document.querySelectorAll('.report-child-card'));
        if (cards.length === 0) return;
        if (currentWizardIndex < 0) currentWizardIndex = 0;
        if (currentWizardIndex >= cards.length) currentWizardIndex = cards.length - 1;

        cards.forEach(function (card, idx) {
            var isCurrent = idx === currentWizardIndex;
            card.style.display = isCurrent ? '' : 'none';
            var cid = card.getAttribute('data-child-id');
            var body = document.getElementById('card-body-' + cid);
            var lbl  = document.getElementById('acc-lbl-' + cid);
            if (isCurrent && body) {
                body.classList.add('is-open');
                if (lbl) lbl.textContent = 'بستن جزئیات ۶ عامل روان‌شناختی ▴';
            }
        });

        var prog = document.getElementById('wizardProgressText');
        if (prog) {
            var cName = cards[currentWizardIndex].getAttribute('data-name') || '';
            prog.textContent = 'کودک ' + toPersianDigits(currentWizardIndex + 1) + ' از ' + toPersianDigits(cards.length) + ' — ' + cName;
        }
    }

    function stepWizardChild(delta) {
        var cards = document.querySelectorAll('.report-child-card');
        if (cards.length === 0) return;
        currentWizardIndex = (currentWizardIndex + delta + cards.length) % cards.length;
        renderWizardStep();
    }

    function applyReportFilters() {
        if (currentReportMode === 'wizard') return;
        var inp = document.getElementById('repSearchInput');
        var q = inp ? inp.value.trim().toLowerCase() : '';
        document.querySelectorAll('.report-child-card').forEach(function (card) {
            var name = (card.getAttribute('data-name') || '').toLowerCase();
            card.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
        });
    }

    function fillNoteForChild(childId, text) {
        var ta = document.getElementById('notes_' + childId);
        if (!ta) return;
        ta.value = text;
        checkPsychLanguageForChild(childId);
    }

    function replaceStigmatizingWord(childId, badWord, goodPhrase) {
        var card = document.getElementById('child-report-' + childId);
        if (!card) return;
        card.querySelectorAll('.psych-monitored-input').forEach(function (inp) {
            if (inp.value.indexOf(badWord) !== -1) {
                inp.value = inp.value.split(badWord).join(goodPhrase);
            }
        });
        checkPsychLanguageForChild(childId);
    }

    function checkPsychLanguageForChild(childId) {
        var card = document.getElementById('child-report-' + childId);
        var warnBox = document.getElementById('psych_warn_' + childId);
        if (!card || !warnBox) return;

        var combined = '';
        card.querySelectorAll('.psych-monitored-input').forEach(function (inp) {
            combined += ' ' + inp.value;
        });

        var matches = [];
        PSYCH_REPLACEMENTS.forEach(function (rule) {
            if (combined.indexOf(rule.bad) !== -1) {
                matches.push(rule);
            }
        });

        if (matches.length === 0) {
            warnBox.style.display = 'none';
            warnBox.innerHTML = '';
            return;
        }

        var html = '<strong>🧭 دستیار هوشمند روان‌شناسی کودک (پیشنهاد اصلاح ادبیات برچسب‌زننده):</strong><div style="margin-top:6px;display:flex;flex-direction:column;gap:6px;">';
        matches.forEach(function (m) {
            html += '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;background:var(--tp-surface);padding:6px 10px;border-radius:8px;">'
                + '<span>واژه «<strong style="color:var(--tp-absent);">' + m.bad + '</strong>» برچسب‌زننده است (' + m.reason + '). پیشنهاد جایگزین: «<strong style="color:var(--tp-present);">' + m.good + '</strong>»</span>'
                + '<button type="button" class="tp-btn tp-btn-primary tp-btn-sm" onclick="replaceStigmatizingWord(' + childId + ', ' + JSON.stringify(m.bad) + ', ' + JSON.stringify(m.good) + ')">جایگزینی خودکار ✓</button>'
                + '</div>';
        });
        html += '</div>';
        warnBox.innerHTML = html;
        warnBox.style.display = 'block';
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.psych-monitored-input').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var cid = inp.getAttribute('data-child');
                if (cid) checkPsychLanguageForChild(cid);
            });
        });

        if (window.innerWidth >= 1150) {
            setReportViewMode('expanded');
        }
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
