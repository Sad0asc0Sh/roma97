<?php
declare(strict_types=1);

/**
 * Teacher Portal — Child profile (پرونده کودک).
 *
 * Read-only, scope-checked view of ONE child: the child must belong
 * to a classroom assigned to the logged-in teacher, otherwise the
 * request is bounced to the roster (fail-closed — an id typed into
 * the URL never leaks another class's child). Shows identity,
 * guardians, health notes, today's attendance/report, and the last
 * 7 operational days of history in the portal's own labels.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';

requireTeacherLogin();

$teacherId = (int) $_SESSION['teacher_id'];
$childId   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$today     = date('Y-m-d');

$child       = null;
$todayAtt    = null;
$todayReport = null;
$history     = [];
$error       = getFlash('error');

try {
    initializeTeachersTables();
    $pdo = getDb();

    if (is_int($childId)) {
        $stmt = $pdo->prepare(
            <<<'SQL'
SELECT
    ch.*, cl.id AS classroom_id, cl.name AS classroom_name,
    p.first_name AS parent_first_name, p.last_name AS parent_last_name,
    p.phone AS parent_phone, p.email AS parent_email
FROM children ch
INNER JOIN child_classroom cc ON cc.child_id = ch.id
INNER JOIN classrooms cl ON cl.id = cc.classroom_id AND cl.teacher_id = :tid
INNER JOIN parents p ON p.id = ch.parent_id
WHERE ch.id = :cid
LIMIT 1
SQL
        );
        $stmt->execute([':tid' => $teacherId, ':cid' => $childId]);
        $child = $stmt->fetch() ?: null;
    }

    if ($child !== null) {
        $classroomId = (int) $child['classroom_id'];

        $attStmt = $pdo->prepare(
            'SELECT status, check_in, check_out, notes FROM attendance WHERE child_id = :cid AND attendance_date = :d LIMIT 1'
        );
        $attStmt->execute([':cid' => $childId, ':d' => $today]);
        $todayAtt = $attStmt->fetch() ?: null;

        $repStmt = $pdo->prepare(
            'SELECT * FROM daily_reports WHERE child_id = :cid AND classroom_id = :clid AND report_date = :d LIMIT 1'
        );
        $repStmt->execute([':cid' => $childId, ':clid' => $classroomId, ':d' => $today]);
        $todayReport = $repStmt->fetch() ?: null;

        $since = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
        $histStmt = $pdo->prepare(
            <<<'SQL'
SELECT d.day,
       a.status AS att_status,
       dr.id AS report_id,
       dr.mood AS report_mood
FROM (
    SELECT :d0 AS day UNION ALL SELECT :d1 UNION ALL SELECT :d2 UNION ALL SELECT :d3
    UNION ALL SELECT :d4 UNION ALL SELECT :d5 UNION ALL SELECT :d6
) d
LEFT JOIN attendance a ON a.child_id = :cid AND a.attendance_date = d.day
LEFT JOIN daily_reports dr ON dr.child_id = :cid AND dr.classroom_id = :clid AND dr.report_date = d.day
ORDER BY d.day DESC
SQL
        );
        $dayParams = [':cid' => $childId, ':clid' => $classroomId];
        $base = new DateTimeImmutable('today');
        for ($i = 0; $i < 7; $i++) {
            $dayParams[':d' . $i] = $base->modify('-' . $i . ' days')->format('Y-m-d');
        }
        $histStmt->execute($dayParams);
        $history = $histStmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('teacher/child-detail failed: ' . $e->getMessage());
    $error = 'بارگذاری پرونده کودک با مشکل مواجه شد.';
}

if ($child === null && !$error) {
    setFlash('error', 'این کودک در کلاس‌های شما یافت نشد.');
    redirect(url('teacher/children.php'));
}

$fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
$pageTitle = 'پرونده ' . $fullName . ' | ' . siteName();
require_once __DIR__ . '/header.php';

$photoUrl = !empty($child['photo']) ? url((string) $child['photo']) : '';
?>

<div class="tp-page-header">
    <div>
        <h1>🧒 پرونده کودک</h1>
        <p class="tp-page-subtitle">
            کلاس <strong><?= e((string) $child['classroom_name']) ?></strong> ·
            <a href="<?= e(url('teacher/children.php')) ?>">بازگشت به فهرست کودکان</a>
        </p>
    </div>
    <div class="tp-page-actions">
        <a href="<?= e(url('teacher/attendance.php')) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">حضور و غیاب</a>
        <a href="<?= e(url('teacher/report.php')) ?>" class="tp-btn tp-btn-primary tp-btn-sm">گزارش امروز</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<section class="tp-card">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <?php if ($photoUrl !== ''): ?>
            <img src="<?= e($photoUrl) ?>" alt="<?= e($fullName) ?>" class="tp-child-avatar" style="width:64px;height:64px;border-radius:18px;font-size:1.4rem;">
        <?php else: ?>
            <span class="tp-child-avatar" style="width:64px;height:64px;border-radius:18px;font-size:1.4rem;"><?= e(userInitial((string) $child['first_name'])) ?></span>
        <?php endif; ?>
        <div>
            <h2 style="margin:0 0 4px;font-size:1.2rem;"><?= e($fullName) ?></h2>
            <p class="tp-page-subtitle" style="margin:0;">
                <?= e(formatIranAge((string) $child['date_of_birth'])) ?>
                <?php if (teacherChildGenderLabel($child['gender'] ?? null) !== ''): ?>
                    · <?= e(teacherChildGenderLabel($child['gender'] ?? null)) ?>
                <?php endif; ?>
                <?php if (trim((string) ($child['preferred_name'] ?? '')) !== ''): ?>
                    · نام آشنا: «<?= e((string) $child['preferred_name']) ?>»
                <?php endif; ?>
                · تولد: <?= e(shamsiDate((string) $child['date_of_birth'])) ?>
            </p>
        </div>
    </div>

    <?php if (trim((string) ($child['allergies'] ?? '')) !== '' || trim((string) ($child['medical_notes'] ?? '')) !== ''): ?>
        <div class="tp-health-chips" style="margin-top:14px;">
            <?php if (trim((string) ($child['allergies'] ?? '')) !== ''): ?>
                <span class="tp-health-chip">⚠️ حساسیت: <?= e((string) $child['allergies']) ?></span>
            <?php endif; ?>
            <?php if (trim((string) ($child['medical_notes'] ?? '')) !== ''): ?>
                <span class="tp-health-chip">🩺 ملاحظه پزشکی: <?= e((string) $child['medical_notes']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="tp-detail-grid" style="margin-top:16px;">
        <div class="tp-child-status-box">
            <span class="tp-child-status-label">والد</span>
            <strong><?= e(trim((string) $child['parent_first_name'] . ' ' . (string) $child['parent_last_name'])) ?></strong>
        </div>
        <div class="tp-child-status-box">
            <span class="tp-child-status-label">تلفن والد</span>
            <?php if (!empty($child['parent_phone'])): ?>
                <a class="tp-phone-pill" href="tel:<?= e((string) $child['parent_phone']) ?>">📞 <?= e(persianNumber((string) $child['parent_phone'])) ?></a>
            <?php else: ?>—<?php endif; ?>
        </div>
        <?php if (trim((string) ($child['second_guardian_name'] ?? '')) !== ''): ?>
            <div class="tp-child-status-box">
                <span class="tp-child-status-label">سرپرست دوم</span>
                <strong><?= e((string) $child['second_guardian_name']) ?></strong>
                <?php if (!empty($child['second_guardian_phone'])): ?>
                    <span><?= e(persianNumber((string) $child['second_guardian_phone'])) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="tp-child-status-box">
            <span class="tp-child-status-label">حضور امروز</span>
            <span class="tp-badge <?= e(teacherAttendanceBadgeClass($todayAtt['status'] ?? null)) ?>">
                <?= e(teacherAttendanceStatusLabel($todayAtt['status'] ?? null)) ?>
            </span>
            <?php if ($todayAtt && ($todayAtt['check_in'] ?? '') !== ''): ?>
                <span>ورود: <?= e(formatIranTime($todayAtt['check_in'])) ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($todayReport !== null): ?>
    <section class="tp-card">
        <div class="tp-card-header">
            <h2>گزارش روان‌شناختی امروز</h2>
            <span class="tp-badge <?= e(teacherMoodBadgeClass((string) ($todayReport['mood'] ?? ''))) ?>">
                <?= e(teacherMoodFullLabel((string) ($todayReport['mood'] ?? ''))) ?>
            </span>
        </div>
        <div class="tp-detail-grid">
            <?php foreach (array_keys(teacherPsychologicalFactorsCatalog()) as $factorKey): ?>
                <div class="tp-child-status-box">
                    <span class="tp-child-status-label"><?= e(teacherPsychologicalFactorsCatalog()[$factorKey]['title']) ?></span>
                    <strong style="font-size:0.85rem;"><?= e(teacherPsychFactorLabel($factorKey, (string) ($todayReport[$factorKey] ?? ''))) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (trim((string) ($todayReport['strengths_observed'] ?? '')) !== ''): ?>
            <p style="line-height:1.9;"><strong>نقطه قوت امروز:</strong> <?= e((string) $todayReport['strengths_observed']) ?></p>
        <?php endif; ?>
        <?php if (trim((string) ($todayReport['activities'] ?? '')) !== ''): ?>
            <p style="line-height:1.9;"><strong>فعالیت‌ها:</strong> <?= e((string) $todayReport['activities']) ?></p>
        <?php endif; ?>
        <?php if (trim((string) ($todayReport['notes'] ?? '')) !== ''): ?>
            <p style="line-height:1.9;"><strong>یادداشت برای والدین:</strong> <?= e((string) $todayReport['notes']) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="tp-card">
    <div class="tp-card-header"><h2>۷ روز اخیر</h2></div>
    <div class="tp-msg-list">
        <?php foreach ($history as $day): ?>
            <div class="tp-msg-item" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <strong><?= e(shamsiDate((string) $day['day'], 'with_day')) ?></strong>
                <span style="display:flex;gap:6px;flex-wrap:wrap;">
                    <span class="tp-badge <?= e(teacherAttendanceBadgeClass($day['att_status'] ?? null)) ?>">
                        حضور: <?= e(teacherAttendanceStatusLabel($day['att_status'] ?? null)) ?>
                    </span>
                    <span class="tp-badge <?= e(teacherMoodBadgeClass($day['report_mood'] ?? null)) ?>">
                        گزارش: <?= e($day['report_id'] !== null && empty($day['report_mood']) ? 'ثبت شده' : teacherMoodLabel($day['report_mood'] ?? null)) ?>
                    </span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
