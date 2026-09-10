<?php
declare(strict_types=1);

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
$today       = date('Y-m-d');
$classroom   = null;
$children    = [];
$reports     = [];

$allowedMoods = ['happy', 'normal', 'mixed', 'challenging'];

$successMessage = getFlash('success');
$errorMessage   = getFlash('error');

try {
    initializeTeachersTables();
    $pdo = getDb();

    // Verify the teacher owns a classroom.
    $cStmt = $pdo->prepare('SELECT id, name FROM classrooms WHERE teacher_id = :tid LIMIT 1');
    $cStmt->execute([':tid' => $teacherId]);
    $classroom = $cStmt->fetch() ?: null;

    if ($classroom !== null) {
        $classroomId = (int) $classroom['id'];

        // Children currently enrolled in this classroom. Only ACTIVE children —
        // graduated/withdrawn leftovers must not appear in today's register.
        $kidsStmt = $pdo->prepare(
            <<<'SQL'
SELECT c.id, c.first_name, c.last_name, c.preferred_name
FROM child_classroom cc
INNER JOIN children c ON c.id = cc.child_id
WHERE cc.classroom_id = :cid AND c.status = 'active'
ORDER BY c.first_name, c.last_name
SQL
        );
        $kidsStmt->execute([':cid' => $classroomId]);
        $children = $kidsStmt->fetchAll();

        $allowedChildIds = array_map(static fn (array $c): int => (int) $c['id'], $children);

        if (isPostRequest()) {
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!validateCsrfToken($csrfToken)) {
                setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
                redirect(url('teacher/report.php'));
            }

            $moods      = (array) ($_POST['mood'] ?? []);
            $activities = (array) ($_POST['activities'] ?? []);
            $notes      = (array) ($_POST['notes'] ?? []);

            // VALUES() syntax: the row-alias form ("AS new") is MySQL 8+ only and
            // fails on MariaDB (the typical shared-hosting database).
            $upsertSql = <<<'SQL'
INSERT INTO daily_reports (teacher_id, classroom_id, child_id, report_date, mood, activities, notes)
VALUES (:tid, :cid, :child_id, :rdate, :mood, :activities, :notes)
ON DUPLICATE KEY UPDATE
    teacher_id   = VALUES(teacher_id),
    classroom_id = VALUES(classroom_id),
    mood         = VALUES(mood),
    activities   = VALUES(activities),
    notes        = VALUES(notes)
SQL;

            try {
                // Snapshot what is already stored for today so we only notify
                // parents whose report actually changed. Without this, pressing
                // "save" twice sent (and charged for) a second SMS to every parent.
                $existingStmt = $pdo->prepare(
                    'SELECT child_id, mood, activities, notes FROM daily_reports
                     WHERE classroom_id = :cid AND report_date = :rdate'
                );
                $existingStmt->execute([':cid' => $classroomId, ':rdate' => $today]);

                $previousReports = [];
                foreach ($existingStmt->fetchAll() as $prev) {
                    $previousReports[(int) $prev['child_id']] = [
                        'mood'       => (string) ($prev['mood'] ?? ''),
                        'activities' => trim((string) ($prev['activities'] ?? '')),
                        'notes'      => trim((string) ($prev['notes'] ?? '')),
                    ];
                }

                $pdo->beginTransaction();
                $uStmt = $pdo->prepare($upsertSql);

                $saved = 0;
                /** @var array<int, array{mood:string}> $changedChildren */
                $changedChildren = [];

                foreach ($allowedChildIds as $childId) {
                    // Only persist children that belong to this teacher's classroom.
                    $mood = (string) ($moods[$childId] ?? 'normal');
                    if (!in_array($mood, $allowedMoods, true)) {
                        $mood = 'normal';
                    }

                    $childActivities = trim((string) ($activities[$childId] ?? ''));
                    $childNotes      = trim((string) ($notes[$childId] ?? ''));

                    $uStmt->execute([
                        ':tid'        => $teacherId,
                        ':cid'        => $classroomId,
                        ':child_id'   => $childId,
                        ':rdate'      => $today,
                        ':mood'       => $mood,
                        ':activities' => $childActivities === '' ? null : $childActivities,
                        ':notes'      => $childNotes === '' ? null : $childNotes,
                    ]);
                    $saved++;

                    $before = $previousReports[$childId] ?? null;
                    $isNew = $before === null;
                    $hasChanged = $isNew
                        || $before['mood'] !== $mood
                        || $before['activities'] !== $childActivities
                        || $before['notes'] !== $childNotes;

                    if ($hasChanged) {
                        $changedChildren[$childId] = ['mood' => $mood];
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            // ── Notify parents (portal + SMS) through the unified engine ──────
            // Runs after the transaction commits so a slow SMS provider can
            // never hold a database lock open.
            $moodLabels = [
                'happy'       => 'پر از خنده، شاد و عالی 🌟',
                'normal'      => 'آرام، خوش‌اخلاق و در حال بازی 🌸',
                'mixed'       => 'بازیگوش، پرحرف و کنجکاو 🎈',
                'challenging' => 'کمی دلتنگ و نیازمند آغوش پرمهر 💖',
            ];

            $notifyResults = [];

            if ($changedChildren !== []) {
                $childIdList = implode(',', array_map('intval', array_keys($changedChildren)));
                $recipientStmt = $pdo->query("
                    SELECT c.id AS child_id, c.first_name AS c_first, c.last_name AS c_last,
                           p.id AS parent_id, p.sms_vip_subscription
                    FROM children c
                    INNER JOIN parents p ON p.id = c.parent_id
                    WHERE c.id IN ({$childIdList})
                ");
                $recipients = $recipientStmt ? $recipientStmt->fetchAll() : [];

                $todayFa = shamsiDate($today);

                foreach ($recipients as $row) {
                    $childId  = (int) $row['child_id'];
                    $parentId = (int) $row['parent_id'];
                    $childName = trim((string) $row['c_first'] . ' ' . (string) $row['c_last']);
                    $isVip = ((int) ($row['sms_vip_subscription'] ?? 0) === 1);
                    $mood = (string) ($changedChildren[$childId]['mood'] ?? 'normal');
                    $moodFa = $moodLabels[$mood] ?? 'خوب';

                    // Parents on the detailed pack get the full report by SMS;
                    // everyone else gets the short "check the portal" message.
                    $eventKey = $isVip ? 'daily_report_vip' : 'daily_report_brief';
                    $args = $isVip
                        ? [$childName, $todayFa, $moodFa]
                        : [$childName, siteName()];

                    $notifyResults[] = notifyParent($parentId, $eventKey, [
                        'args' => $args,
                        // One notification per child per day, regardless of how
                        // many times the teacher saves the form.
                        'dedup_key'   => "daily_report:{$childId}:{$today}",
                        'sender_type' => 'teacher',
                        'sender_id'   => $teacherId,
                    ]);
                }

                smsQueueFlushInline($pdo);
            }

            recordAudit('daily_report.save', 'classroom', $classroomId, [
                'children' => $saved,
                'notified' => count($notifyResults),
            ]);

            $flash = 'گزارش روزانه برای ' . persianNumber((string) $saved) . ' کودک با موفقیت ذخیره شد!';

            if ($changedChildren === []) {
                $flash .= ' (تغییری نسبت به گزارش قبلی نبود، پیامک تکراری ارسال نشد.)';
            } else {
                $summary = summarizeNotificationResults($notifyResults);
                if ($summary !== '') {
                    $flash .= ' ' . $summary;
                }
            }

            setFlash('success', $flash);
            redirect(url('teacher/report.php'));
        }

        // Existing reports for today, keyed by child_id.
        $rStmt = $pdo->prepare(
            'SELECT child_id, mood, activities, notes FROM daily_reports WHERE classroom_id = :cid AND report_date = :today'
        );
        $rStmt->execute([':cid' => $classroomId, ':today' => $today]);
        foreach ($rStmt->fetchAll() as $row) {
            $reports[(int) $row['child_id']] = $row;
        }
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    if (!$errorMessage) {
        $errorMessage = 'خطایی در بارگذاری فرم گزارش رخ داد.';
    }
}

$moodOptions = [
    'happy'       => '😊 شاد و پرانرژی',
    'normal'      => '😐 عادی / خوب',
    'mixed'       => '😅 ترکیبی (با چالش)',
    'challenging' => '😤 چالش‌برانگیز',
];

$pageTitle = 'گزارش روزانه | ' . e(siteName());
require_once __DIR__ . '/header.php';
?>

<section class="teacher-dashboard">
    <div class="teacher-dash-top">
        <div>
            <h1>گزارش روزانه</h1>
            <p class="muted">برای کلاس <?= e($classroom['name'] ?? 'کلاس') ?> در تاریخ <?= e(shamsiDate($today)) ?></p>
        </div>
        <div>
            <a href="<?= e(url('teacher/index.php')) ?>" class="btn btn-secondary">← بازگشت به داشبورد</a>
        </div>
    </div>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($classroom === null): ?>
        <div class="teacher-no-classroom">
            <div class="teacher-no-classroom-icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg></div>
            <h2>هیچ کلاسی به شما اختصاص داده شده است</h2>
            <p>برای ثبت گزارش روزانه باید به یک کلاس اختصاص داده شده باشید</p>
        </div>
    <?php elseif ($children === []): ?>
        <div class="teacher-no-classroom">
            <div class="teacher-no-classroom-icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg></div>
            <h2>هنوز کودکی در این کلاس ثبت‌نام نشده است</h2>
            <p>پس از ثبت‌نام کودکان در کلاس، می‌توانید برای هر کودک گزارش روزانه ثبت کنید.</p>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e(url('teacher/report.php')) ?>" class="report-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

            <p class="muted report-intro">برای هر کودک حال‌وهوا، فعالیت‌ها و یادداشت‌های امروز را ثبت کنید.</p>

            <div class="report-children-grid">
                <?php foreach ($children as $child): ?>
                    <?php
                    $cid          = (int) $child['id'];
                    $existing     = $reports[$cid] ?? null;
                    $moodCurrent  = (string) ($existing['mood'] ?? 'normal');
                    $actCurrent   = (string) ($existing['activities'] ?? '');
                    $notesCurrent = (string) ($existing['notes'] ?? '');
                    $displayName  = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                    $preferred    = trim((string) ($child['preferred_name'] ?? ''));
                    ?>
                    <div class="form-card report-child-card">
                        <div class="report-child-head">
                            <h3><?= e($displayName) ?></h3>
                            <?php if ($preferred !== ''): ?>
                                <span class="muted">«<?= e($preferred) ?>»</span>
                            <?php endif; ?>
                            <?php if ($existing !== null): ?>
                                <span class="badge badge-active">ثبت‌شده</span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="mood_<?= $cid ?>">حال و هوا</label>
                            <select id="mood_<?= $cid ?>" name="mood[<?= $cid ?>]" required>
                                <?php foreach ($moodOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $moodCurrent === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group margin-top-md">
                            <label for="activities_<?= $cid ?>">فعالیت‌های امروز</label>
                            <textarea id="activities_<?= $cid ?>" name="activities[<?= $cid ?>]" rows="2" placeholder="مانند نقاشی، بازی، قصه‌گویی..."><?= e($actCurrent) ?></textarea>
                        </div>

                        <div class="form-group margin-top-md">
                            <label for="notes_<?= $cid ?>">یادداشت برای والدین</label>
                            <textarea id="notes_<?= $cid ?>" name="notes[<?= $cid ?>]" rows="3" placeholder="دستاوردها یا اطلاعیه‌های مهم برای والدین این کودک."><?= e($notesCurrent) ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions margin-top-lg">
                <button type="submit" class="btn btn-primary">ذخیره گزارش‌های روزانه</button>
                <a href="<?= e(url('teacher/index.php')) ?>" class="btn-link btn-cancel-link">انصراف</a>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
