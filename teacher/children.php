<?php
declare(strict_types=1);

/**
 * Teacher Portal — Classroom children roster (کودکان کلاس).
 *
 * Read-only roster of the ACTIVE classroom's children with the
 * information a teacher needs at a glance in a busy room: photo,
 * age, health alerts, today's attendance/report state and the
 * parent's phone. Scope is the teacher's own classroom context —
 * the same boundary every other portal page enforces.
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
$today     = date('Y-m-d');

$classrooms  = [];
$classroom   = null;
$classroomId = 0;
$children    = [];
$error       = getFlash('error');

try {
    initializeTeachersTables();
    $pdo = getDb();

    $ctx         = teacherResolveClassroomContext($pdo, $teacherId);
    $classrooms  = $ctx['classrooms'];
    $classroom   = $ctx['active'];
    $classroomId = $ctx['active_id'];

    if ($classroom !== null) {
        $stmt = $pdo->prepare(
            <<<'SQL'
SELECT
    ch.id, ch.first_name, ch.last_name, ch.preferred_name,
    ch.date_of_birth, ch.gender, ch.allergies, ch.medical_notes, ch.photo,
    p.first_name AS parent_first_name, p.last_name AS parent_last_name, p.phone AS parent_phone,
    a.status AS attendance_status,
    dr.mood AS report_mood, dr.id AS report_id
FROM child_classroom cc
INNER JOIN children ch ON ch.id = cc.child_id
INNER JOIN parents p ON p.id = ch.parent_id
LEFT JOIN attendance a ON a.child_id = ch.id AND a.attendance_date = :today_att
LEFT JOIN daily_reports dr ON dr.child_id = ch.id AND dr.classroom_id = cc.classroom_id AND dr.report_date = :today_rep
WHERE cc.classroom_id = :cid AND ch.status = 'active'
ORDER BY ch.last_name, ch.first_name
SQL
        );
        $stmt->execute([':cid' => $classroomId, ':today_att' => $today, ':today_rep' => $today]);
        $children = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('teacher/children failed: ' . $e->getMessage());
    $error = 'بارگذاری فهرست کودکان با مشکل مواجه شد. لطفاً صفحه را تازه‌سازی کنید.';
}

$pageTitle = 'کودکان کلاس | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<div class="tp-page-header">
    <div>
        <h1>👧 کودکان کلاس</h1>
        <p class="tp-page-subtitle">
            <?php if ($classroom !== null): ?>
                کلاس <strong><?= e((string) $classroom['name']) ?></strong> ·
                <?= e(persianNumber((string) count($children))) ?> کودک فعال ·
                وضعیت امروز هر کودک و دسترسی سریع به پرونده او
            <?php else: ?>
                هنوز کلاسی به شما اختصاص داده نشده است.
            <?php endif; ?>
        </p>
    </div>
    <div class="tp-page-actions">
        <a href="<?= e(url('teacher/attendance.php')) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">حضور و غیاب امروز</a>
        <a href="<?= e(url('teacher/report.php')) ?>" class="tp-btn tp-btn-primary tp-btn-sm">ثبت گزارش امروز</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<?php if ($classroom === null): ?>
    <div class="tp-empty-state">
        <div class="tp-empty-icon">🏫</div>
        <h2>هیچ کلاسی به شما اختصاص داده نشده است</h2>
        <p>پس از اختصاص کلاس توسط مدیریت، فهرست کودکان کلاستان اینجا نمایش داده می‌شود.</p>
        <a href="<?= e(url('teacher/index.php')) ?>" class="tp-btn tp-btn-secondary">بازگشت به داشبورد</a>
    </div>
<?php elseif ($children === []): ?>
    <div class="tp-empty-state">
        <div class="tp-empty-icon">🧒</div>
        <h2>هنوز کودکی در این کلاس ثبت‌نام نشده است</h2>
        <p>به‌محض ثبت‌نام کودکان در کلاس، پرونده و وضعیت روزانه‌شان اینجا در دسترس است.</p>
    </div>
<?php else: ?>
    <div class="tp-children-grid">
        <?php foreach ($children as $child): ?>
            <?php
            $cid         = (int) $child['id'];
            $fullName    = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
            $preferred   = trim((string) ($child['preferred_name'] ?? ''));
            $photoUrl    = !empty($child['photo']) ? url((string) $child['photo']) : '';
            $hasAllergy  = trim((string) ($child['allergies'] ?? '')) !== '';
            $hasMedical  = trim((string) ($child['medical_notes'] ?? '')) !== '';
            $attStatus   = $child['attendance_status'] ?? null;
            $repMood     = $child['report_mood'] ?? null;
            $age         = teacherChildDisplayAge((string) $child['date_of_birth']);
            $genderLabel = teacherChildGenderLabel($child['gender'] ?? null);
            ?>
            <article class="tp-child-card<?= ($hasAllergy || $hasMedical) ? ' tp-child-card--allergy' : '' ?>">
                <div class="tp-child-top">
                    <div class="tp-child-identity">
                        <?php if ($photoUrl !== ''): ?>
                            <img src="<?= e($photoUrl) ?>" alt="<?= e($fullName) ?>" class="tp-child-avatar">
                        <?php else: ?>
                            <span class="tp-child-avatar"><?= e(userInitial((string) $child['first_name'])) ?></span>
                        <?php endif; ?>
                        <div>
                            <a class="tp-child-name" href="<?= e(url('teacher/child-detail.php?id=' . $cid)) ?>"><?= e($fullName) ?></a>
                            <p class="tp-child-sub">
                                <?= e(trim($age . ($genderLabel !== '' ? ' · ' . $genderLabel : ''))) ?>
                                <?php if ($preferred !== '' && $preferred !== $child['first_name']): ?>
                                    · «<?= e($preferred) ?>»
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <?php if ($hasAllergy || $hasMedical): ?>
                    <div class="tp-health-chips">
                        <?php if ($hasAllergy): ?>
                            <span class="tp-health-chip">⚠️ حساسیت: <?= e((string) $child['allergies']) ?></span>
                        <?php endif; ?>
                        <?php if ($hasMedical): ?>
                            <span class="tp-health-chip">🩺 ملاحظه پزشکی: <?= e((string) $child['medical_notes']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="tp-child-status-grid">
                    <div class="tp-child-status-box">
                        <span class="tp-child-status-label">حضور امروز</span>
                        <span class="tp-badge <?= e(teacherAttendanceBadgeClass($attStatus !== null ? (string) $attStatus : null)) ?>">
                            <?= e(teacherAttendanceStatusLabel($attStatus !== null ? (string) $attStatus : null)) ?>
                        </span>
                    </div>
                    <div class="tp-child-status-box">
                        <span class="tp-child-status-label">گزارش امروز</span>
                        <?php if (!empty($child['report_id'])): ?>
                            <span class="tp-badge <?= e(teacherMoodBadgeClass($repMood !== null ? (string) $repMood : null)) ?>">
                                <?= e(empty($repMood) ? 'گزارش ثبت شده' : teacherMoodLabel((string) $repMood)) ?>
                            </span>
                        <?php else: ?>
                            <span class="tp-badge tp-badge-unrecorded">ثبت نشده</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tp-child-parent-row">
                    <span>والد: <?= e(trim((string) $child['parent_first_name'] . ' ' . (string) $child['parent_last_name'])) ?></span>
                    <?php if (!empty($child['parent_phone'])): ?>
                        <a class="tp-phone-pill" href="tel:<?= e((string) $child['parent_phone']) ?>">📞 <?= e(persianNumber((string) $child['parent_phone'])) ?></a>
                    <?php endif; ?>
                </div>

                <div class="tp-child-actions">
                    <a href="<?= e(url('teacher/child-detail.php?id=' . $cid)) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">مشاهده پرونده</a>
                    <a href="<?= e(url('teacher/messages.php')) ?>" class="tp-btn tp-btn-outline tp-btn-sm">پیام به والد</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
