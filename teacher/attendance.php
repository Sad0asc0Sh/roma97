<?php
declare(strict_types=1);

/**
 * ROMA — Teacher Attendance Register (حضور و غیاب کلاس)
 * ------------------------------------------------------------------
 * Lets a teacher record attendance for the children of *their own* classroom.
 *
 * Why this page exists
 * --------------------
 * Attendance could previously only be recorded from the admin panel, which is
 * the wrong place operationally: the teacher is the person standing at the door
 * when the children arrive, while the admin is not. The result was that
 * attendance was entered late (or from memory at the end of the day), and the
 * automatic "your child arrived" SMS reached parents hours after the fact.
 *
 * Guarantees
 * ----------
 *  • Scope — a teacher can only ever see and write attendance for children
 *    enrolled in the classroom assigned to them. The allow-list is rebuilt from
 *    the database on every POST, so a forged child_id in the form is ignored.
 *  • No duplicate SMS — notifications are keyed on
 *    `attendance:{child}:{date}:{status}`, so re-saving the sheet (very common
 *    as children trickle in) is silent, while a genuine correction
 *    (present → absent) does notify.
 *  • Today-only by default, with a small back-dating window for corrections;
 *    future dates are rejected because attendance cannot be known in advance.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';

requireTeacherLogin();

$teacherId   = (int) $_SESSION['teacher_id'];
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'معلم');

/** @var list<string> $allowedStatuses */
$allowedStatuses = ['present', 'late', 'excused', 'absent'];

/** Human-friendly Persian labels shown in the UI. */
$statusLabels = [
    'present' => 'حاضر',
    'late'    => 'با تأخیر',
    'excused' => 'غیبت موجه',
    'absent'  => 'غایب',
];

/** Warm, child-centred wording used in the SMS to parents. */
$statusSmsLabels = [
    'present' => 'با لبخند وارد مهد شد و در حال بازیه 🌈',
    'absent'  => 'امروز جاش خیلی خالیه و دلتنگشیم 🎈',
    'late'    => 'با کمی تاخیر اومد پیشمون و به جمع دوستانش پیوست ⏰',
    'excused' => 'امروز در حال استراحت در کنار شماست 🏡',
];

/**
 * Accept either a Jalali (۱۴۰۳/۰۵/۱۲) or ISO date and return an ISO date.
 * Future dates and dates older than the correction window are rejected.
 */
function teacherAttendanceDate(string $raw): ?string
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    $iso = parseJalaliDate($raw);

    if ($iso === null) {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        $iso = $raw;
    }

    $today = new DateTimeImmutable('today');
    $candidate = new DateTimeImmutable($iso);

    // Attendance cannot be recorded for a day that has not happened yet.
    if ($candidate > $today) {
        return null;
    }

    // Corrections are allowed for the last 7 days; anything older belongs to
    // the admin panel, so a teacher cannot quietly rewrite last month's register.
    if ($candidate < $today->modify('-7 days')) {
        return null;
    }

    return $iso;
}

/** Normalize an HH:MM time input to SQL TIME, or null when empty/invalid. */
function teacherAttendanceTime(?string $raw): ?string
{
    $raw = trim((string) $raw);

    if ($raw === '') {
        return null;
    }

    if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $raw) === 1) {
        return $raw . ':00';
    }

    if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d:[0-5]\d\z/', $raw) === 1) {
        return $raw;
    }

    return null;
}

/** Render the HH:MM portion of a stored SQL TIME for an <input type="time">. */
function teacherTimeForInput(?string $sqlTime): string
{
    if ($sqlTime === null || $sqlTime === '') {
        return '';
    }

    return preg_match('/^\d{2}:\d{2}/', $sqlTime, $m) === 1 ? $m[0] : '';
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$selectedDate = teacherAttendanceDate((string) ($_GET['date'] ?? $today)) ?? $today;

$classroom = null;
$rows = [];
$summary = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unset' => 0];

$successMessage = getFlash('success');
$errorMessage   = getFlash('error');

try {
    initializeTeachersTables();
    initializeNotificationTables();
    $pdo = getDb();

    // ── Scope: the classroom this teacher owns ──────────────────────────────
    $cStmt = $pdo->prepare('SELECT id, name FROM classrooms WHERE teacher_id = :tid LIMIT 1');
    $cStmt->execute([':tid' => $teacherId]);
    $classroom = $cStmt->fetch() ?: null;

    if ($classroom !== null) {
        $classroomId = (int) $classroom['id'];

        if (isPostRequest()) {
            $redirectUrl = url('teacher/attendance.php?date=' . rawurlencode($selectedDate));

            if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
                setFlash('error', 'درخواست نامعتبر است. لطفاً صفحه را دوباره بارگذاری کنید.');
                redirect($redirectUrl);
            }

            $postDate = teacherAttendanceDate((string) ($_POST['attendance_date'] ?? ''));

            if ($postDate === null) {
                setFlash('error', 'تاریخ انتخاب‌شده معتبر نیست. حضور و غیاب فقط برای امروز و تا ۷ روز گذشته قابل ثبت است.');
                redirect($redirectUrl);
            }

            // Rebuild the allow-list from the database on every submit; never
            // trust the child ids that came back from the form.
            $allowStmt = $pdo->prepare(
                "SELECT c.id
                 FROM child_classroom cc
                 INNER JOIN children c ON c.id = cc.child_id
                 WHERE cc.classroom_id = :cid AND c.status = 'active'"
            );
            $allowStmt->execute([':cid' => $classroomId]);

            $allowedIds = [];
            while (($cid = $allowStmt->fetchColumn()) !== false) {
                $allowedIds[(int) $cid] = true;
            }

            $submitted = $_POST['attendance'] ?? [];
            if (!is_array($submitted)) {
                $submitted = [];
            }

            // Snapshot the stored statuses so we can notify only real changes.
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

            // VALUES() syntax: the row-alias form ("AS new") is MySQL 8+ only and
            // fails on MariaDB (the typical shared-hosting database).
            $upsert = $pdo->prepare(
                <<<'SQL'
INSERT INTO attendance (child_id, attendance_date, status, check_in, check_out, notes)
VALUES (:child_id, :adate, :status, :check_in, :check_out, :notes)
ON DUPLICATE KEY UPDATE
    status    = VALUES(status),
    check_in  = VALUES(check_in),
    check_out = VALUES(check_out),
    notes     = VALUES(notes)
SQL
            );

            /** @var array<int, string> $changed child_id => new status */
            $changed = [];
            $savedCount = 0;

            $pdo->beginTransaction();

            try {
                foreach ($submitted as $childKey => $fields) {
                    if (!is_array($fields)) {
                        continue;
                    }

                    $childId = filter_var($childKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                    if (!is_int($childId) || !isset($allowedIds[$childId])) {
                        continue; // Not in this teacher's classroom — ignore silently.
                    }

                    // A child with no explicit status on the form (e.g. added to the
                    // class after the page loaded) is skipped entirely rather than
                    // silently recorded as present with an SMS to their parent.
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

                    // A present/late child with no recorded arrival time gets one
                    // automatically when the register is filled in on the same day —
                    // but only on the FIRST save for that child/date. Re-saving later
                    // (e.g. an evening correction) must not backfill a bogus arrival
                    // time or overwrite a time the teacher deliberately cleared.
                    if ($checkIn === null && in_array($status, ['present', 'late'], true) && $postDate === $today
                        && !array_key_exists($childId, $previousStatuses)) {
                        $checkIn = date('H:i:00');
                    }

                    $upsert->execute([
                        ':child_id'  => $childId,
                        ':adate'     => $postDate,
                        ':status'    => $status,
                        ':check_in'  => $checkIn,
                        ':check_out' => $checkOut,
                        ':notes'     => $notes === '' ? null : $notes,
                    ]);

                    $savedCount++;

                    if (($previousStatuses[$childId] ?? null) !== $status) {
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

            // ── Notify parents AFTER the commit ─────────────────────────────
            // Sending inside the transaction would hold row locks for the whole
            // duration of the SMS provider's response time.
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
                    $childId = (int) $row['child_id'];
                    $status = (string) ($changed[$childId] ?? 'present');
                    $childName = trim((string) $row['c_first'] . ' ' . (string) $row['c_last']);

                    $notifyResults[] = notifyParent((int) $row['parent_id'], 'attendance', [
                        'args' => [$childName, $dateFa, $statusSmsLabels[$status] ?? $statusLabels[$status] ?? 'حاضر'],
                        // Status-keyed: a correction re-notifies, a plain re-save does not.
                        'dedup_key'   => "attendance:{$childId}:{$postDate}:{$status}",
                        'sender_type' => 'teacher',
                        'sender_id'   => $teacherId,
                    ]);
                }

                smsQueueFlushInline($pdo);
            }

            $flash = 'حضور و غیاب ' . persianNumber((string) $savedCount) . ' کودک برای ' . shamsiDate($postDate) . ' ذخیره شد.';

            if ($changed === []) {
                $flash .= ' (تغییری نسبت به ثبت قبلی نبود، پیامک تکراری ارسال نشد.)';
            } else {
                $flash .= ' ' . persianNumber((string) count($changed)) . ' تغییر ثبت شد. ' . summarizeNotificationResults($notifyResults);
            }

            setFlash('success', $flash);
            redirect(url('teacher/attendance.php?date=' . rawurlencode($postDate)));
        }

        // ── Read model: children + their attendance for the selected date ────
        $listStmt = $pdo->prepare(
            <<<'SQL'
SELECT
    c.id, c.first_name, c.last_name, c.preferred_name, c.photo, c.allergies,
    a.status, a.check_in, a.check_out, a.notes
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
    $errorMessage = 'بارگذاری حضور و غیاب با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
}

$isToday = $selectedDate === $today;
$totalChildren = count($rows);

$pageTitle = 'حضور و غیاب | پنل معلم | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<div class="teacher-page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:18px;">
    <div>
        <h1 style="margin:0 0 4px;font-size:1.4rem;">حضور و غیاب کلاس ✋</h1>
        <p class="muted" style="margin:0;font-size:0.9rem;">
            <?php if ($classroom !== null): ?>
                کلاس <strong><?= e((string) $classroom['name']) ?></strong> ·
                <?= e(shamsiDate($selectedDate, 'full')) ?>
                <?= $isToday ? ' (امروز)' : '' ?>
            <?php else: ?>
                هنوز کلاسی به شما اختصاص نیافته است.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($classroom !== null): ?>
        <form method="get" action="<?= e(url('teacher/attendance.php')) ?>" style="display:flex;gap:8px;align-items:flex-end;">
            <div>
                <label for="date" class="form-label" style="font-size:0.8rem;">تاریخ</label>
                <input type="date" id="date" name="date" class="form-control"
                    value="<?= e($selectedDate) ?>"
                    max="<?= e($today) ?>"
                    min="<?= e((new DateTimeImmutable('today'))->modify('-7 days')->format('Y-m-d')) ?>"
                    onchange="this.form.submit()">
            </div>
            <?php if (!$isToday): ?>
                <a href="<?= e(url('teacher/attendance.php')) ?>" class="btn btn-secondary" style="font-size:0.82rem;">برو به امروز</a>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if ($classroom === null): ?>
    <div class="card" style="padding:28px;text-align:center;">
        <h3 style="margin:0 0 8px;">کلاسی به شما اختصاص نیافته است</h3>
        <p class="muted" style="margin:0;">برای ثبت حضور و غیاب، لطفاً از مدیریت مهدکودک بخواهید کلاسی به حساب شما اختصاص دهد.</p>
    </div>
<?php elseif ($totalChildren === 0): ?>
    <div class="card" style="padding:28px;text-align:center;">
        <h3 style="margin:0 0 8px;">هنوز کودکی در این کلاس ثبت نشده است</h3>
        <p class="muted" style="margin:0;">پس از اختصاص کودکان به کلاس شما، فهرست آن‌ها در این صفحه نمایش داده می‌شود.</p>
    </div>
<?php else: ?>

    <!-- Live summary; updated by JS as the teacher taps through the register -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:18px;">
        <div class="card" style="padding:10px 12px;text-align:center;">
            <div style="font-size:0.75rem;color:var(--muted);font-weight:600;">کل کودکان</div>
            <div style="font-size:1.2rem;font-weight:800;"><?= e(persianNumber((string) $totalChildren)) ?></div>
        </div>
        <div class="card" style="padding:10px 12px;text-align:center;border-block-end:3px solid #2e8540;">
            <div style="font-size:0.75rem;color:var(--muted);font-weight:600;">حاضر</div>
            <div style="font-size:1.2rem;font-weight:800;color:#1e632b;" data-count="present"><?= e(persianNumber((string) $summary['present'])) ?></div>
        </div>
        <div class="card" style="padding:10px 12px;text-align:center;border-block-end:3px solid #c8860d;">
            <div style="font-size:0.75rem;color:var(--muted);font-weight:600;">با تأخیر</div>
            <div style="font-size:1.2rem;font-weight:800;color:#7a5205;" data-count="late"><?= e(persianNumber((string) $summary['late'])) ?></div>
        </div>
        <div class="card" style="padding:10px 12px;text-align:center;border-block-end:3px solid #0b5c8a;">
            <div style="font-size:0.75rem;color:var(--muted);font-weight:600;">غیبت موجه</div>
            <div style="font-size:1.2rem;font-weight:800;color:#0b5c8a;" data-count="excused"><?= e(persianNumber((string) $summary['excused'])) ?></div>
        </div>
        <div class="card" style="padding:10px 12px;text-align:center;border-block-end:3px solid #c62828;">
            <div style="font-size:0.75rem;color:var(--muted);font-weight:600;">غایب</div>
            <div style="font-size:1.2rem;font-weight:800;color:#c62828;" data-count="absent"><?= e(persianNumber((string) $summary['absent'])) ?></div>
        </div>
    </div>

    <form method="post" action="<?= e(url('teacher/attendance.php?date=' . rawurlencode($selectedDate))) ?>" id="attendanceForm">
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
        <input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">

        <div class="card" style="padding:14px 16px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <span style="font-size:0.85rem;font-weight:600;">ثبت سریع همه:</span>
            <?php foreach ($statusLabels as $value => $label): ?>
                <button type="button" class="btn btn-secondary" style="font-size:0.8rem;padding:6px 12px;"
                    onclick="setAllAttendance('<?= e($value) ?>')">
                    <?= e($label) ?>
                </button>
            <?php endforeach; ?>
            <span class="muted" style="font-size:0.78rem;">می‌توانید ابتدا همه را «حاضر» کنید و سپس فقط موارد استثنا را تغییر دهید.</span>
        </div>

        <div style="display:grid;gap:12px;">
            <?php foreach ($rows as $row): ?>
                <?php
                $childId = (int) $row['id'];
                $displayName = trim((string) ($row['preferred_name'] ?? '')) !== ''
                    ? (string) $row['preferred_name']
                    : trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                $currentStatus = (string) ($row['status'] ?? '');
                $hasRecord = $currentStatus !== '';
                if (!in_array($currentStatus, $allowedStatuses, true)) {
                    // Default the radio to "present" but keep the card visually marked
                    // as unrecorded so the teacher can see what is still pending.
                    $currentStatus = 'present';
                }
                ?>
                <div class="card attendance-row" data-child="<?= e((string) $childId) ?>"
                    style="padding:14px 16px;<?= $hasRecord ? '' : 'border:1px dashed var(--border, #d8d8d8);' ?>">

                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                        <div>
                            <div style="font-weight:700;font-size:1rem;">
                                <?= e($displayName) ?>
                                <?php if (!$hasRecord): ?>
                                    <span style="font-size:0.72rem;color:#7a5205;font-weight:600;">· ثبت نشده</span>
                                <?php endif; ?>
                            </div>
                            <?php if (trim((string) ($row['allergies'] ?? '')) !== ''): ?>
                                <div style="font-size:0.75rem;color:#c62828;margin-top:3px;">
                                    ⚠️ حساسیت: <?= e((string) $row['allergies']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="status-radio-group" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <?php $inputId = 'att-' . $childId . '-' . $value; ?>
                            <label class="status-radio-label" for="<?= e($inputId) ?>"
                                style="display:inline-flex;align-items:center;gap:5px;font-size:0.85rem;padding:6px 10px;border:1px solid var(--border, #ddd);border-radius:8px;cursor:pointer;">
                                <input type="radio" id="<?= e($inputId) ?>"
                                    name="attendance[<?= e((string) $childId) ?>][status]"
                                    value="<?= e($value) ?>"
                                    onchange="refreshAttendanceSummary()"
                                    <?= $currentStatus === $value ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">
                        <div>
                            <label for="in-<?= e((string) $childId) ?>" class="form-label" style="font-size:0.75rem;">ساعت ورود</label>
                            <input type="time" id="in-<?= e((string) $childId) ?>"
                                name="attendance[<?= e((string) $childId) ?>][check_in]"
                                class="form-control"
                                value="<?= e(teacherTimeForInput($row['check_in'] ?? null)) ?>">
                        </div>
                        <div>
                            <label for="out-<?= e((string) $childId) ?>" class="form-label" style="font-size:0.75rem;">ساعت خروج</label>
                            <input type="time" id="out-<?= e((string) $childId) ?>"
                                name="attendance[<?= e((string) $childId) ?>][check_out]"
                                class="form-control"
                                value="<?= e(teacherTimeForInput($row['check_out'] ?? null)) ?>">
                        </div>
                        <div style="grid-column:1/-1;">
                            <label for="notes-<?= e((string) $childId) ?>" class="form-label" style="font-size:0.75rem;">یادداشت (اختیاری)</label>
                            <input type="text" id="notes-<?= e((string) $childId) ?>"
                                name="attendance[<?= e((string) $childId) ?>][notes]"
                                class="form-control" maxlength="2000"
                                placeholder="مثال: با تاخیر و همراه پدربزرگ آمد"
                                value="<?= e((string) ($row['notes'] ?? '')) ?>">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions" style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <button type="submit" class="btn btn-primary">💾 ذخیره حضور و غیاب</button>
            <span class="muted" style="font-size:0.8rem;">
                فقط برای کودکانی که وضعیتشان تغییر کرده به والدین پیامک ارسال می‌شود.
            </span>
        </div>
    </form>

    <script>
    // Bulk-set every radio group, then refresh the counters.
    function setAllAttendance(status) {
        document.querySelectorAll('.attendance-row').forEach(function (row) {
            var input = row.querySelector('input[type="radio"][value="' + status + '"]');
            if (input) { input.checked = true; }
        });
        refreshAttendanceSummary();
    }

    // Keep the summary cards in sync with the form without a round-trip, so the
    // teacher can verify the headcount before saving.
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

    document.addEventListener('DOMContentLoaded', refreshAttendanceSummary);
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
