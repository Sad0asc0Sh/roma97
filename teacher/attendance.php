<?php
declare(strict_types=1);

/**
 * ROMA — Teacher Attendance & Morning Separation Register
 * (حضور و غیاب و ثبت کیفیت گذار صبحگاهی کودک بر پایه نظریه دلبستگی ایمن)
 *
 * Designed for busy classroom ergonomics:
 * - Instant search & status filter chips (همه / ثبت‌نشده‌ها / حاضرین / غایب و تأخیر)
 * - 1-tap attendance pills with automatic check-in time fill
 * - Collapsible time & morning transition details on mobile so the coach can scan 15+ kids at once
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
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';
require_once __DIR__ . '/../includes/school_planning.php';

requireTeacherLogin();
require_once __DIR__ . '/../includes/portal_rules.php';

$teacherId   = (int) $_SESSION['teacher_id'];
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'مربی');

/** @var list<string> $allowedStatuses */
$allowedStatuses = ['present', 'late', 'excused', 'absent'];

$statusLabels = [
    'present' => 'حاضر (پذیرش ایمن)',
    'late'    => 'ورود با تأخیر',
    'excused' => 'غیبت با هماهنگی',
    'absent'  => 'عدم حضور امروز',
];

/** Attachment-safe, anxiety-free wording used in parent SMS notifications. */
$statusSmsLabels = [
    'present' => 'با آرامش و احساس امنیت وارد کلاس شد و روزش را در کنار دوستانش آغاز کرد 🌈',
    'absent'  => 'امروز در کلاس حضور نداشت؛ امیدواریم روز آرامی را در کنار شما سپری کند و مشتاق دیدارش هستیم 🌱',
    'late'    => 'با همراهی مهربانانه مربی وارد کلاس شد و به جمع دوستانش پیوست 🌸',
    'excused' => 'با هماهنگی شما امروز در کنار خانواده در حال استراحت است 🏡',
];

$transitionNotesChips = [
    '🌈 جدایی ایمن و مستقل با لبخند',
    '🤗 نیازمند اطمینان خاطر کوتاه هنگام تحویل و آرام‌گیری سریع',
    '🌱 دلتنگی اولیه و درگیر شدن شاداب در بازی با هم‌تنظیمی مربی',
    '🕊️ ورود آرام؛ نیازمند فضای کم‌محرک در شروع صبح',
];

function teacherAttendanceDate(string $raw): ?string
{
    return teacherValidOperationalDate($raw, 7);
}

function teacherAttendanceTime(?string $raw): ?string
{
    return teacherNormalizeTimeInput($raw);
}

function teacherTimeForInput(?string $sqlTime): string
{
    return teacherFormatTimeShort($sqlTime);
}

$today        = (new DateTimeImmutable('today'))->format('Y-m-d');
$selectedDate = teacherAttendanceDate((string) ($_GET['date'] ?? $today)) ?? $today;
$selectedFilter = $_GET['filter'] ?? 'all';
$selectedFilter = in_array($selectedFilter, ['expected', 'notices', 'unrecorded'], true) ? $selectedFilter : 'all';

$classrooms  = [];
$classroom   = null;
$classroomId = 0;
$rows        = [];
$attendancePlan = ['holiday' => null, 'expected' => [], 'missing' => [], 'notices' => []];
$summary     = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unset' => 0];

$successMessage = getFlash('success');
$errorMessage   = getFlash('error');

try {
    initializeTeachersTables();
    initializeAttendanceTable();
    initializeNotificationTables();
    $pdo = getDb();

    $ctx         = teacherResolveClassroomContext($pdo, $teacherId, isPostRequest());
    $classrooms  = $ctx['classrooms'];
    $classroom   = $ctx['active'];
    $classroomId = $ctx['active_id'];

    if ($classroom !== null) {
        if (isPostRequest()) {
            $redirectUrl = url('teacher/attendance.php?classroom_id=' . $classroomId . '&date=' . rawurlencode($selectedDate));

            if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
                setFlash('error', 'درخواست نامعتبر است. لطفاً صفحه را دوباره بارگذاری کنید.');
                redirect($redirectUrl);
            }

            $postDate = teacherAttendanceDate((string) ($_POST['attendance_date'] ?? ''));

            if ($postDate === null) {
                setFlash('error', 'تاریخ انتخاب‌شده معتبر نیست. حضور و غیاب فقط برای امروز و تا ۷ روز گذشته قابل ثبت است.');
                redirect($redirectUrl);
            }

            $allowedIds = teacherAllowedChildIds($pdo, $classroomId);

            $submitted = $_POST['attendance'] ?? [];
            if (!is_array($submitted)) {
                $submitted = [];
            }

            $previousStatuses = [];
            $prevStmt = $pdo->prepare(
                'SELECT a.child_id, a.status
                 FROM attendance a
                 INNER JOIN child_classroom cc ON cc.child_id = a.child_id
                 WHERE cc.classroom_id = :cid AND a.attendance_date = :adate'
            );
            $prevStmt->execute([':cid' => $classroomId, ':adate' => $postDate]);
            foreach ($prevStmt->fetchAll() as $prev) {
                $previousStatuses[(int) $prev['child_id']] = (string) $prev['status'];
            }

            $upsert = $pdo->prepare(<<<'SQL'
INSERT INTO attendance (child_id, attendance_date, status, check_in, check_out, notes)
VALUES (:child_id, :adate, :status, :check_in, :check_out, :notes)
SQL . ' ' . PORTAL_UPDATE_UNVERIFIED_ATTENDANCE);

            /** @var array<int, string> $changed */
            $changed    = [];
            $savedCount = 0;

            $pdo->beginTransaction();

            try {
                foreach ($submitted as $childKey => $fields) {
                    if (!is_array($fields)) {
                        continue;
                    }

                    $childId = filter_var($childKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                    if (!is_int($childId) || !isset($allowedIds[$childId])) {
                        continue;
                    }

                    $status = (string) ($fields['status'] ?? '');
                    if (!in_array($status, $allowedStatuses, true)) {
                        continue;
                    }

                    $notes = trim((string) ($fields['notes'] ?? ''));
                    if (function_exists('mb_strlen') && mb_strlen($notes, 'UTF-8') > 2000) {
                        $notes = mb_substr($notes, 0, 2000, 'UTF-8');
                    } elseif (strlen($notes) > 2000) {
                        $notes = substr($notes, 0, 2000);
                    }

                    $checkIn  = teacherAttendanceTime($fields['check_in'] ?? null);
                    $checkOut = teacherAttendanceTime($fields['check_out'] ?? null);

                    if ($checkIn === null && in_array($status, ['present', 'late'], true) && $postDate === $today
                        && !array_key_exists($childId, $previousStatuses)) {
                        $checkIn = date('H:i:00');
                    }
                    [$checkIn, $checkOut] = portalAttendanceTimes($status, $checkIn, $checkOut);

                    $upsert->execute([
                        ':child_id'  => $childId,
                        ':adate'     => $postDate,
                        ':status'    => $status,
                        ':check_in'  => $checkIn,
                        ':check_out' => $checkOut,
                        ':notes'     => $notes === '' ? null : $notes,
                    ]);

                    $savedCount++;

                    if ($upsert->rowCount() > 0 && ($previousStatuses[$childId] ?? null) !== $status) {
                        $changed[$childId] = $status;
                    }
                }

                $pdo->commit();
            } catch (Throwable $txError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txError;
            }

            recordAudit('attendance.save', 'classroom', $classroomId, [
                'date'    => $postDate,
                'saved'   => $savedCount,
                'changed' => count($changed),
                'by'      => 'teacher',
            ]);

            $notifyResults = [];

            if ($changed !== []) {
                $idList = implode(',', array_map('intval', array_keys($changed)));
                $recipients = $pdo->query(
                    "SELECT c.id AS child_id, c.first_name AS c_first, c.last_name AS c_last, p.id AS parent_id
                     FROM children c
                     INNER JOIN parents p ON p.id = c.parent_id
                     WHERE c.id IN ({$idList})"
                )->fetchAll();

                $dateFa = shamsiDate($postDate);

                foreach ($recipients as $row) {
                    $childId   = (int) $row['child_id'];
                    $status    = (string) ($changed[$childId] ?? 'present');
                    $childName = trim((string) $row['c_first'] . ' ' . (string) $row['c_last']);

                    $notifyResults[] = notifyParent((int) $row['parent_id'], 'attendance', [
                        'args'        => [$childName, $dateFa, $statusSmsLabels[$status] ?? $statusLabels[$status] ?? 'حاضر'],
                        'dedup_key'   => "attendance:{$childId}:{$postDate}:{$status}",
                        'sender_type' => 'teacher',
                        'sender_id'   => $teacherId,
                    ]);
                }

                smsQueueFlushInline($pdo);
            }

            $flash = 'حضور و غیاب و کیفیت گذار صبحگاهی ' . persianNumber((string) $savedCount) . ' کودک برای ' . shamsiDate($postDate) . ' ذخیره شد.';

            if ($changed === []) {
                $flash .= ' (تغییری نسبت به ثبت قبلی نبود، پیامک تکراری ارسال نشد.)';
            } else {
                $flash .= ' ' . persianNumber((string) count($changed)) . ' تغییر ثبت شد. ' . summarizeNotificationResults($notifyResults);
            }

            setFlash('success', $flash);
            redirect(url('teacher/attendance.php?classroom_id=' . $classroomId . '&date=' . rawurlencode($postDate)));
        }

        $attendancePlan = dailyAttendancePlan($pdo, $selectedDate, $classroomId);
        $listStmt = $pdo->prepare(
            <<<'SQL'
SELECT
    c.id, c.first_name, c.last_name, c.preferred_name, c.photo, c.allergies,
    a.status, a.check_in, a.check_out, a.notes, a.pickup_verified_by
FROM child_classroom cc
INNER JOIN children c ON c.id = cc.child_id
LEFT JOIN attendance a
       ON a.child_id = c.id
      AND a.attendance_date = :adate
WHERE cc.classroom_id = :cid
  AND c.status = 'active'
ORDER BY c.first_name, c.last_name
SQL
        );
        $listStmt->execute([':cid' => $classroomId, ':adate' => $selectedDate]);
        $rows = $listStmt->fetchAll();

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status]++;
            } else {
                $summary['unset']++;
            }
        }
    }
} catch (Throwable $exception) {
    error_log('teacher/attendance failed: ' . $exception->getMessage());
    $errorMessage = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'ذخیره یا بارگذاری حضور و غیاب انجام نشد. لطفاً دوباره تلاش کنید.';
}

$isToday       = $selectedDate === $today;
$totalChildren = count($rows);

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

$pageTitle = 'حضور و غیاب و گذار صبحگاهی | پنل مربیان | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<div class="tp-page-header">
    <div>
        <h1>✋ حضور و غیاب و کیفیت گذار صبحگاهی</h1>
        <p class="tp-page-subtitle">
            <?php if ($classroom !== null): ?>
                کلاس <strong><?= e((string) $classroom['name']) ?></strong> ·
                <?= e(shamsiDate($selectedDate, 'with_day')) ?>
                <?= $isToday ? ' (امروز)' : '' ?> · ثبت سریع با یک لمس در محیط شلوغ کلاس
            <?php else: ?>
                هنوز کلاسی به شما اختصاص نیافته است.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($classroom !== null): ?>
        <form method="get" action="<?= e(url('teacher/attendance.php')) ?>" class="tp-page-actions">
            <div>
                <label for="date" class="sr-only">تاریخ</label>
                <input type="date" id="date" name="date" class="tp-input" style="width:auto;"
                    value="<?= e($selectedDate) ?>"
                    max="<?= e($today) ?>"
                    min="<?= e($todayObj->modify('-7 days')->format('Y-m-d')) ?>"
                    onchange="this.form.submit()">
            </div>
            <?php if (!$isToday): ?>
                <a href="<?= e(url('teacher/attendance.php')) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">برو به امروز</a>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if ($classroom === null): ?>
    <div class="tp-empty-state">
        <h2>کلاسی به شما اختصاص نیافته است</h2>
        <p>برای ثبت حضور و غیاب، لطفاً از مدیریت مهدکودک بخواهید کلاسی به حساب شما اختصاص دهد.</p>
        <a href="<?= e(url('teacher/index.php')) ?>" class="tp-btn tp-btn-secondary">بازگشت به داشبورد</a>
    </div>
<?php elseif ($totalChildren === 0): ?>
    <div class="tp-empty-state">
        <h2>هنوز کودکی در این کلاس ثبت‌نام نشده است</h2>
        <p>پس از اختصاص کودکان به کلاس شما، فهرست آن‌ها در این صفحه نمایش داده می‌شود.</p>
    </div>
<?php else: ?>

    <!-- 7-Day Quick Date Strip -->
    <nav class="tp-date-strip" aria-label="انتخاب سریع روز">
        <?php foreach ($recentDays as $dayChip): ?>
            <a href="<?= e(url('teacher/attendance.php?date=' . rawurlencode($dayChip['ymd']))) ?>"
               class="tp-day-chip <?= $dayChip['ymd'] === $selectedDate ? 'active' : '' ?>">
                <span><?= e($dayChip['label']) ?></span>
                <strong><?= e($dayChip['date']) ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($attendancePlan['holiday'] !== null): ?>
        <p class="notice">تعطیلی مهد · <?= e($attendancePlan['holiday']) ?></p>
    <?php endif; ?>
    <p class="tp-day-note">
        <?= e(persianNumber(count($attendancePlan['expected']))) ?> کودک مورد انتظار ·
        <?= e(persianNumber(count($attendancePlan['missing']))) ?> ثبت‌نشده ·
        <?= e(persianNumber(count($attendancePlan['notices']))) ?> اعلام خانواده
    </p>

    <!-- Live Headcount Summary Cards -->
    <div class="tp-stat-grid">
        <div class="tp-stat-card">
            <div class="tp-stat-body">
                <div class="tp-stat-value"><?= e(persianNumber((string) $totalChildren)) ?></div>
                <div class="tp-stat-label">کل کودکان کلاس</div>
            </div>
        </div>
        <div class="tp-stat-card" style="border-bottom:3px solid var(--tp-present);">
            <div class="tp-stat-body">
                <div class="tp-stat-value" style="color:var(--tp-present);" data-count="present"><?= e(persianNumber((string) $summary['present'])) ?></div>
                <div class="tp-stat-label">حاضر (پذیرش ایمن)</div>
            </div>
        </div>
        <div class="tp-stat-card" style="border-bottom:3px solid var(--tp-late);">
            <div class="tp-stat-body">
                <div class="tp-stat-value" style="color:var(--tp-late);" data-count="late"><?= e(persianNumber((string) $summary['late'])) ?></div>
                <div class="tp-stat-label">ورود با تأخیر</div>
            </div>
        </div>
        <div class="tp-stat-card" style="border-bottom:3px solid var(--tp-excused);">
            <div class="tp-stat-body">
                <div class="tp-stat-value" style="color:var(--tp-excused);" data-count="excused"><?= e(persianNumber((string) $summary['excused'])) ?></div>
                <div class="tp-stat-label">غیبت با هماهنگی</div>
            </div>
        </div>
        <div class="tp-stat-card" style="border-bottom:3px solid var(--tp-absent);">
            <div class="tp-stat-body">
                <div class="tp-stat-value" style="color:var(--tp-absent);" data-count="absent"><?= e(persianNumber((string) $summary['absent'])) ?></div>
                <div class="tp-stat-label">عدم حضور امروز</div>
            </div>
        </div>
    </div>

    <form method="post" action="<?= e(url('teacher/attendance.php?date=' . rawurlencode($selectedDate))) ?>" id="attendanceForm">
        <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
        <input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">

        <!-- Fast Classroom Filter, Search & Bulk Actions Toolbar -->
        <div class="tp-toolbar" style="flex-direction:column;align-items:stretch;gap:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;min-width:240px;">
                    <input type="search" id="attSearchInput" class="tp-input" style="max-width:240px;padding:8px 12px;"
                           placeholder="🔍 جستجوی سریع نام کودک..." oninput="applyAttendanceFilters()">
                    <div class="tp-mode-switch" role="group" aria-label="فیلتر وضعیت حضور">
                        <button type="button" class="tp-mode-btn active" data-att-filter="all" onclick="setAttendanceFilter('all', this)">همه (<?= e(persianNumber((string) $totalChildren)) ?>)</button>
                        <button type="button" class="tp-mode-btn" data-att-filter="expected" onclick="setAttendanceFilter('expected', this)">مورد انتظار</button>
                        <button type="button" class="tp-mode-btn" data-att-filter="unrecorded" onclick="setAttendanceFilter('unrecorded', this)">مورد انتظارِ ثبت‌نشده</button>
                        <button type="button" class="tp-mode-btn" data-att-filter="notices" onclick="setAttendanceFilter('notices', this)">اعلام خانواده</button>
                        <button type="button" class="tp-mode-btn" data-att-filter="present" onclick="setAttendanceFilter('present', this)">حاضرین</button>
                        <button type="button" class="tp-mode-btn" data-att-filter="absent_late" onclick="setAttendanceFilter('absent_late', this)">تأخیر / غایب</button>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="tp-btn tp-btn-secondary tp-btn-sm" onclick="toggleAllAttExtra()">
                        ⏱️ باز/بسته کردن جزئیات ساعت و گذار همه
                    </button>
                </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-top:8px;border-top:1px dashed var(--tp-border);">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="font-size:0.82rem;">ثبت یک‌جا برای کودکان مورد انتظارِ نمایش‌داده‌شده</strong>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <button type="button" class="tp-btn tp-btn-secondary tp-btn-sm" onclick="setAllAttendance('<?= e($value) ?>')">
                            همه «<?= e($label) ?>»
                        </button>
                    <?php endforeach; ?>
                </div>
                <span style="font-size:0.76rem;color:var(--tp-text-muted);">
                    ⚡ با انتخاب «حاضر» یا «ورود با تأخیر»، ساعت پذیرش به‌صورت خودکار ثبت می‌شود.
                </span>
            </div>
        </div>

        <div class="tp-att-list" id="attendanceRowsList">
            <?php foreach ($rows as $row): ?>
                <?php
                $childId       = (int) $row['id'];
                $fullName      = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                $displayName   = trim((string) ($row['preferred_name'] ?? '')) !== ''
                    ? $fullName . ' («' . trim((string) $row['preferred_name']) . '»)'
                    : $fullName;
                $currentStatus = (string) ($row['status'] ?? '');
                $hasRecord     = $currentStatus !== '';
                $familyNotice = $attendancePlan['notices'][$childId] ?? null;
                $photoUrl      = !empty($row['photo']) ? url((string) $row['photo']) : '';
                $checkInShort  = teacherTimeForInput($row['check_in'] ?? null);
                $checkOutShort = teacherTimeForInput($row['check_out'] ?? null);
                $noteVal       = trim((string) ($row['notes'] ?? ''));
                $pickupVerified = $row['pickup_verified_by'] !== null;
                $hasExtraData  = $checkInShort !== '' || $checkOutShort !== '' || $noteVal !== '';
                ?>
                <div class="tp-att-row attendance-row <?= $hasRecord ? '' : 'tp-att-row--unrecorded' ?>"
                     data-child="<?= $childId ?>"
                     data-name="<?= e($displayName) ?>"
                     data-recorded="<?= $hasRecord ? '1' : '0' ?>"
                     data-expected="<?= isset($attendancePlan['expected'][$childId]) ? '1' : '0' ?>"
                     data-family-notice="<?= $familyNotice !== null ? '1' : '0' ?>"
                     <?= $pickupVerified ? 'data-pickup-locked' : '' ?>>
                    <div class="tp-att-row-top">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <?php if ($photoUrl !== ''): ?>
                                <img src="<?= e($photoUrl) ?>" alt="<?= e($fullName) ?>" class="tp-child-avatar" style="width:44px;height:44px;border-radius:14px;font-size:1rem;">
                            <?php else: ?>
                                <div class="tp-child-avatar" style="width:44px;height:44px;border-radius:14px;font-size:1rem;"><?= e(userInitial((string) $row['first_name'])) ?></div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight:800;font-size:1rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <a href="<?= e(url('teacher/child-detail.php?id=' . $childId)) ?>" style="color:inherit;text-decoration:none;"><?= e($displayName) ?></a>
                                    <?php if (!$hasRecord): ?>
                                        <span class="tp-badge tp-badge-unrecorded" id="rec-badge-<?= $childId ?>">ثبت نشده</span>
                                    <?php else: ?>
                                        <span class="tp-badge tp-badge-present" id="rec-badge-<?= $childId ?>">ثبت شده <?= $checkInShort !== '' ? '· ' . e(persianNumber($checkInShort)) : '' ?></span>
                                    <?php endif; ?>
                                    <?php if ($pickupVerified): ?>
                                        <span class="tp-badge tp-badge-present">تحویل در پذیرش تأیید شد</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($familyNotice !== null): ?>
                                    <div class="tp-day-note">
                                        <?= e(attendanceNoticeLabel($familyNotice)) ?>
                                        <?php if ($familyNotice['note'] !== null): ?> · <?= e($familyNotice['note']) ?><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) ($row['allergies'] ?? '')) !== ''): ?>
                                    <div style="font-size:0.76rem;color:var(--tp-absent);font-weight:700;margin-top:2px;">
                                        ⚠️ حساسیت: <?= e((string) $row['allergies']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tp-status-pills">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <?php $inputId = 'att-' . $childId . '-' . $value; ?>
                                <label class="tp-status-pill" for="<?= e($inputId) ?>">
                                    <input type="radio" id="<?= e($inputId) ?>"
                                        name="attendance[<?= $childId ?>][status]"
                                        value="<?= e($value) ?>"
                                        onchange="onChildStatusChange(<?= $childId ?>, '<?= e($value) ?>')"
                                        <?= $pickupVerified ? 'disabled' : '' ?>
                                        <?= $currentStatus === $value ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" class="tp-att-details-toggle" onclick="toggleAttRowExtra(<?= $childId ?>)">
                        <span id="att-toggle-lbl-<?= $childId ?>">
                            ⏱️ ساعت ورود/خروج و یادداشت گذار صبحگاهی <?= $hasExtraData ? '(دارای جزئیات ثبت‌شده)' : '(اختیاری - ضربه بزنید)' ?>
                        </span>
                        <span id="att-toggle-ico-<?= $childId ?>">▾</span>
                    </button>

                    <div class="tp-att-inputs tp-att-extra <?= $hasExtraData ? 'is-open' : '' ?>" id="att-extra-<?= $childId ?>">
                        <div>
                            <label for="in-<?= $childId ?>" class="tp-label">ساعت پذیرش صبح</label>
                            <div class="tp-time-input-group">
                                <input type="time" id="in-<?= $childId ?>"
                                    name="attendance[<?= $childId ?>][check_in]"
                                    class="tp-input"
                                    <?= $pickupVerified ? 'disabled' : '' ?>
                                    value="<?= e($checkInShort) ?>">
                                <button type="button" class="tp-now-btn" <?= $pickupVerified ? 'disabled' : '' ?> onclick="setNowTime('in-<?= $childId ?>')" title="ثبت ساعت اکنون">الان</button>
                            </div>
                        </div>
                        <div>
                            <label for="out-<?= $childId ?>" class="tp-label">ساعت تحویل به والد</label>
                            <div class="tp-time-input-group">
                                <input type="time" id="out-<?= $childId ?>"
                                    name="attendance[<?= $childId ?>][check_out]"
                                    class="tp-input"
                                    <?= $pickupVerified ? 'disabled' : '' ?>
                                    value="<?= e($checkOutShort) ?>">
                                <button type="button" class="tp-now-btn" <?= $pickupVerified ? 'disabled' : '' ?> onclick="setNowTime('out-<?= $childId ?>')" title="ثبت ساعت اکنون">الان</button>
                            </div>
                        </div>
                        <div>
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px;">
                                <label for="notes-<?= $childId ?>" class="tp-label" style="margin:0;">مشاهده کیفیت جدایی صبحگاهی و گذار (اختیاری)</label>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <?php foreach ($transitionNotesChips as $chipTxt): ?>
                                        <button type="button" class="tp-template-chip" style="font-size:0.68rem;padding:2px 7px;"
                                                <?= $pickupVerified ? 'disabled' : '' ?>
                                                onclick="setTransitionNote(<?= $childId ?>, <?= e(json_encode($chipTxt, JSON_UNESCAPED_UNICODE)) ?>)">
                                            <?= e($chipTxt) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="text" id="notes-<?= $childId ?>"
                                name="attendance[<?= $childId ?>][notes]"
                                class="tp-input" maxlength="2000"
                                <?= $pickupVerified ? 'disabled' : '' ?>
                                placeholder="توصیف نحوه جدایی از والد و ورود به جمع کلاس..."
                                value="<?= e($noteVal) ?>">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="tp-sticky-save-bar">
            <span style="font-size:0.82rem;color:var(--tp-text-secondary);" id="attUnsavedHint">
                🔔 پیامک اطلاع‌رسانی به والدین با ادبیات آرامش‌بخش و بدون ایجاد حس گناه ارسال می‌شود.
            </span>
            <button type="submit" class="tp-btn tp-btn-primary">💾 ذخیره حضور و کیفیت گذار صبحگاهی</button>
        </div>
    </form>

    <script>
    var currentAttFilter = <?= json_encode($selectedFilter) ?>;
    var allExtraExpanded = false;

    function nowHHMM() {
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        return hh + ':' + mm;
    }

    function onChildStatusChange(childId, statusVal) {
        var inInput = document.getElementById('in-' + childId);
        if (inInput && (statusVal === 'present' || statusVal === 'late') && !inInput.value) {
            inInput.value = nowHHMM();
        }
        var badge = document.getElementById('rec-badge-' + childId);
        if (badge) {
            badge.className = 'tp-badge tp-badge-late';
            badge.textContent = 'تغییر یافته (آماده ذخیره)';
        }
        var hint = document.getElementById('attUnsavedHint');
        if (hint) {
            hint.innerHTML = '⚡ <strong>تغییرات جدید انتخاب شد.</strong> برای ثبت نهایی دکمه «ذخیره» را بزنید.';
        }
        refreshAttendanceSummary();
        applyAttendanceFilters();
    }

    function setAllAttendance(status) {
        var timeStr = nowHHMM();
        document.querySelectorAll('.attendance-row').forEach(function (row) {
            if (row.style.display === 'none' || row.dataset.expected !== '1' || row.hasAttribute('data-pickup-locked')) return;
            var input = row.querySelector('input[type="radio"][value="' + status + '"]');
            if (input) { input.checked = true; }
            var cid = row.getAttribute('data-child');
            if (cid && (status === 'present' || status === 'late')) {
                var inEl = document.getElementById('in-' + cid);
                if (inEl && !inEl.value) { inEl.value = timeStr; }
            }
        });
        refreshAttendanceSummary();
        applyAttendanceFilters();
    }

    function setNowTime(inputId) {
        var el = document.getElementById(inputId);
        if (!el) return;
        el.value = nowHHMM();
    }

    function setTransitionNote(childId, text) {
        var el = document.getElementById('notes-' + childId);
        if (el) { el.value = text; }
    }

    function toggleAttRowExtra(childId) {
        var box = document.getElementById('att-extra-' + childId);
        var ico = document.getElementById('att-toggle-ico-' + childId);
        if (!box) return;
        var open = box.classList.toggle('is-open');
        if (ico) { ico.textContent = open ? '▴' : '▾'; }
    }

    function toggleAllAttExtra() {
        allExtraExpanded = !allExtraExpanded;
        document.querySelectorAll('.tp-att-extra').forEach(function (box) {
            box.classList.toggle('is-open', allExtraExpanded);
        });
    }

    function setAttendanceFilter(filterKey, btnEl) {
        currentAttFilter = filterKey;
        document.querySelectorAll('[data-att-filter]').forEach(function (b) {
            b.classList.toggle('active', b === btnEl);
            b.setAttribute('aria-pressed', String(b === btnEl));
        });
        applyAttendanceFilters();
    }

    function applyAttendanceFilters() {
        var searchEl = document.getElementById('attSearchInput');
        var q = searchEl ? searchEl.value.trim().toLowerCase() : '';

        document.querySelectorAll('.attendance-row').forEach(function (row) {
            var name = (row.getAttribute('data-name') || '').toLowerCase();
            var recorded = row.getAttribute('data-recorded') === '1';
            var checked = row.querySelector('input[type="radio"]:checked');
            var status = checked ? checked.value : '';

            var matchSearch = !q || name.indexOf(q) !== -1;
            var matchFilter = true;
            if (currentAttFilter === 'unrecorded') {
                matchFilter = row.dataset.expected === '1' && !recorded;
            } else if (currentAttFilter === 'expected') {
                matchFilter = row.dataset.expected === '1';
            } else if (currentAttFilter === 'notices') {
                matchFilter = row.dataset.familyNotice === '1';
            } else if (currentAttFilter === 'present') {
                matchFilter = status === 'present';
            } else if (currentAttFilter === 'absent_late') {
                matchFilter = (status === 'late' || status === 'absent' || status === 'excused');
            }

            row.style.display = (matchSearch && matchFilter) ? '' : 'none';
        });
    }

    function refreshAttendanceSummary() {
        var counts = { present: 0, late: 0, excused: 0, absent: 0 };

        document.querySelectorAll('.attendance-row').forEach(function (row) {
            var checked = row.querySelector('input[type="radio"]:checked');
            if (checked && Object.prototype.hasOwnProperty.call(counts, checked.value)) {
                counts[checked.value]++;
            }
        });

        Object.keys(counts).forEach(function (key) {
            var el = document.querySelector('[data-count="' + key + '"]');
            if (el) { el.textContent = toPersianDigits(counts[key]); }
        });
    }

    function toPersianDigits(value) {
        var map = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(value).replace(/\d/g, function (d) { return map[Number(d)]; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setAttendanceFilter(currentAttFilter, document.querySelector('[data-att-filter="' + currentAttFilter + '"]'));
        refreshAttendanceSummary();
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
