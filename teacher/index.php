<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';

requireTeacherLogin();

$teacherId     = (int) $_SESSION['teacher_id'];
$teacherName   = (string) ($_SESSION['teacher_name'] ?? 'مربی');
$teacherRole   = (string) ($_SESSION['teacher_role_title'] ?? 'مربی مهد');
$teacherAvatar = (string) ($_SESSION['teacher_avatar'] ?? '');
$today         = date('Y-m-d');

$classrooms       = [];
$classroom        = null;
$classroomId      = 0;
$children         = [];
$upcomingEvents   = [];
$error            = getFlash('error');
$successMessage   = getFlash('success');

$attendanceCounts  = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0];
$attendanceMissing = 0;
$reportedChildren  = 0;
$allergyChildren   = [];

try {
    initializeTeachersTables();
    $pdo = getDb();

    $ctx = teacherResolveClassroomContext($pdo, $teacherId);
    $classrooms  = $ctx['classrooms'];
    $classroom   = $ctx['active'];
    $classroomId = $ctx['active_id'];

    if ($classroom !== null) {
        $chStmt = $pdo->prepare(
            <<<'SQL'
SELECT
    ch.id,
    ch.first_name,
    ch.last_name,
    ch.preferred_name,
    ch.date_of_birth,
    ch.gender,
    ch.allergies,
    ch.medical_notes,
    ch.second_guardian_name,
    ch.second_guardian_phone,
    ch.photo,
    p.first_name  AS parent_first_name,
    p.last_name   AS parent_last_name,
    p.phone       AS parent_phone,
    a.status      AS attendance_status,
    a.check_in    AS attendance_check_in,
    dr.mood       AS report_mood,
    dr.id         AS report_id
FROM child_classroom cc
INNER JOIN children ch ON ch.id = cc.child_id
INNER JOIN parents  p  ON p.id  = ch.parent_id
LEFT  JOIN attendance a
       ON  a.child_id = ch.id
       AND a.attendance_date = :today_att
LEFT  JOIN daily_reports dr
       ON  dr.child_id = ch.id
       AND dr.classroom_id = cc.classroom_id
       AND dr.report_date = :today_rep
WHERE cc.classroom_id = :cid
  AND ch.status = 'active'
ORDER BY ch.last_name, ch.first_name
SQL
        );
        $chStmt->execute([
            ':cid'       => $classroomId,
            ':today_att' => $today,
            ':today_rep' => $today,
        ]);
        $children = $chStmt->fetchAll();

        foreach ($children as $childRow) {
            $status = (string) ($childRow['attendance_status'] ?? '');
            if ($status === '') {
                $attendanceMissing++;
            } elseif (isset($attendanceCounts[$status])) {
                $attendanceCounts[$status]++;
            }

            if (!empty($childRow['report_id'])) {
                $reportedChildren++;
            }

            if (trim((string) ($childRow['allergies'] ?? '')) !== '' || trim((string) ($childRow['medical_notes'] ?? '')) !== '') {
                $allergyChildren[] = $childRow;
            }
        }
    }

    // Fetch upcoming scheduled events (next 30 days)
    $evStmt = $pdo->prepare(
        "SELECT id, title, description, event_date, start_time, end_time, category
         FROM events
         WHERE event_date >= :today AND status = 'scheduled'
         ORDER BY event_date ASC, start_time IS NULL ASC, start_time ASC
         LIMIT 4"
    );
    $evStmt->execute([':today' => $today]);
    $upcomingEvents = $evStmt->fetchAll();
} catch (Throwable $e) {
    error_log('teacher/index failed: ' . $e->getMessage());
    $error = 'بارگذاری اطلاعات داشبورد با مشکل مواجه شد. لطفاً صفحه را تازه‌سازی کنید.';
}

$hasClassroom   = $classroom !== null;
$childCount     = count($children);
$attendanceDone = $childCount > 0 && $attendanceMissing === 0;
$reportsDone    = $childCount > 0 && $reportedChildren >= $childCount;
$recordedAtt    = max(0, $childCount - $attendanceMissing);
$attPercent     = $childCount > 0 ? (int) round(($recordedAtt / $childCount) * 100) : 0;
$repPercent     = $childCount > 0 ? (int) round(($reportedChildren / $childCount) * 100) : 0;

$pageTitle = 'داشبورد مربی | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alert" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<!-- Welcome Hero Banner -->
<section class="tp-welcome-banner" aria-label="خوش‌آمدگویی مربی">
    <div class="tp-welcome-profile">
        <?php if ($teacherAvatar !== ''): ?>
            <img src="<?= e(url($teacherAvatar)) ?>" alt="<?= e($teacherName) ?>" class="tp-welcome-avatar">
        <?php else: ?>
            <div class="tp-welcome-avatar"><?= e(userInitial($teacherName, 'م')) ?></div>
        <?php endif; ?>
        <div class="tp-welcome-text">
            <h1>روز بخیر، <?= e($teacherName) ?> 🌱</h1>
            <div class="tp-welcome-meta">
                <span class="tp-welcome-pill">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= e(shamsiDate($today, 'with_day')) ?>
                </span>
                <span class="tp-welcome-pill">
                    <?= e($teacherRole !== '' ? $teacherRole : 'مربی مهد') ?>
                </span>
                <?php if ($hasClassroom): ?>
                    <span class="tp-welcome-pill">
                        کلاس فعال: <?= e((string) $classroom['name']) ?> (<?= e(persianNumber((string) $childCount)) ?> از <?= e(persianNumber((string) $classroom['capacity'])) ?> نفر)
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($hasClassroom): ?>
        <div class="tp-welcome-actions">
            <a href="<?= e(url('teacher/attendance.php')) ?>" class="tp-btn-hero-primary">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                <?= $attendanceDone ? 'مشاهده حضور و غیاب' : 'ثبت حضور امروز' ?>
            </a>
            <a href="<?= e(url('teacher/report.php')) ?>" class="tp-btn-hero-outline">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                <?= $reportsDone ? 'مشاهده گزارش‌های امروز' : 'ثبت گزارش روزانه' ?>
            </a>
        </div>
    <?php endif; ?>
</section>

<!-- Access & Scope Governance Strip -->
<section class="tp-access-strip <?= $hasClassroom ? '' : 'tp-access-strip--unassigned' ?>" aria-label="وضعیت دسترسی و کلاس مربی">
    <div class="tp-access-info">
        <span class="tp-access-title">
            <?php if ($hasClassroom): ?>
                🔒 سطح دسترسی: مربی مسئول کلاس «<?= e((string) $classroom['name']) ?>»
                <?= count($classrooms) > 1 ? ' (دارای ' . e(persianNumber((string) count($classrooms))) . ' کلاس)' : '' ?>
            <?php else: ?>
                ⚠️ وضعیت حساب: فعال (در انتظار اختصاص کلاس توسط مدیریت)
            <?php endif; ?>
        </span>
        <div class="tp-cap-pills">
            <?php if ($hasClassroom): ?>
                <span class="tp-cap-pill">✓ پرونده کودکان کلاس</span>
                <span class="tp-cap-pill">✓ ثبت حضور و غیاب</span>
                <span class="tp-cap-pill">✓ گزارش روزانه</span>
                <span class="tp-cap-pill">✓ پیام به والدین کلاس</span>
            <?php else: ?>
                <span class="tp-cap-pill">✓ مشاهده تقویم مهد</span>
                <span class="tp-cap-pill">✓ تکمیل پروفایل و مدارک</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (count($classrooms) > 1): ?>
        <form method="get" action="<?= e(url('teacher/index.php')) ?>" style="display:flex;align-items:center;gap:8px;">
            <label for="dashClassSwitch" style="font-size:0.8rem;font-weight:700;">تغییر کلاس:</label>
            <select id="dashClassSwitch" name="classroom_id" class="tp-select" style="width:auto;padding:6px 12px;" onchange="this.form.submit()">
                <?php foreach ($classrooms as $clOpt): ?>
                    <option value="<?= (int) $clOpt['id'] ?>" <?= (int) $clOpt['id'] === $classroomId ? 'selected' : '' ?>>
                        <?= e((string) $clOpt['name']) ?> (<?= e(persianNumber((string) $clOpt['active_children_count'])) ?> کودک)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</section>

<?php if (!$hasClassroom): ?>
    <div class="tp-empty-state">
        <div class="tp-empty-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
        </div>
        <h2>هنوز کلاسی به حساب شما اختصاص داده نشده است</h2>
        <p>حساب کاربری شما فعال است، اما برای دسترسی به فهرست کودکان، ثبت حضور و غیاب و ارسال گزارش روزانه، لازم است مدیریت مهدکودک یک کلاس به شما اختصاص دهد. در این فاصله می‌توانید پروفایل و مدارک خود را تکمیل کنید.</p>
        <div style="display:flex;justify-content:center;gap:10px;flex-wrap:wrap;">
            <a href="<?= e(url('teacher/profile.php')) ?>" class="tp-btn tp-btn-primary">تکمیل پروفایل و مشاهده حقوق</a>
            <a href="<?= e(url('teacher/schedule.php')) ?>" class="tp-btn tp-btn-secondary">مشاهده تقویم رویدادها</a>
        </div>
    </div>
<?php else: ?>

    <!-- KPI Stat Grid -->
    <section class="tp-stat-grid" aria-label="شاخص‌های امروز کلاس">
        <div class="tp-stat-card">
            <div class="tp-stat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            </div>
            <div class="tp-stat-body">
                <div class="tp-stat-value"><?= e(persianNumber((string) $childCount)) ?> <small style="font-size:0.85rem;font-weight:600;color:var(--tp-text-muted);">/ <?= e(persianNumber((string) $classroom['capacity'])) ?></small></div>
                <div class="tp-stat-label">کودکان فعال کلاس</div>
            </div>
        </div>

        <div class="tp-stat-card">
            <div class="tp-stat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="tp-stat-body">
                <div class="tp-stat-value"><?= e(persianNumber((string) ($attendanceCounts['present'] + $attendanceCounts['late']))) ?></div>
                <div class="tp-stat-label">حاضرین امروز (<?= e(persianNumber((string) $attendanceCounts['late'])) ?> با تأخیر)</div>
            </div>
        </div>

        <div class="tp-stat-card">
            <div class="tp-stat-icon tp-stat-icon--blue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="tp-stat-body">
                <div class="tp-stat-value"><?= e(persianNumber((string) $reportedChildren)) ?> <small style="font-size:0.85rem;font-weight:600;color:var(--tp-text-muted);">از <?= e(persianNumber((string) $childCount)) ?></small></div>
                <div class="tp-stat-label">گزارش‌های ثبت‌شده امروز</div>
            </div>
        </div>

        <div class="tp-stat-card">
            <div class="tp-stat-icon <?= count($allergyChildren) > 0 ? 'tp-stat-icon--red' : 'tp-stat-icon--amber' ?>">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="tp-stat-body">
                <div class="tp-stat-value"><?= e(persianNumber((string) count($allergyChildren))) ?></div>
                <div class="tp-stat-label">دارای پرونده حساسیت / پزشکی</div>
            </div>
        </div>
    </section>

    <!-- Operational Task Board -->
    <section class="tp-section" aria-label="کارتابل عملیاتی امروز">
        <div class="tp-section-header">
            <h2 class="tp-section-title">کارتابل عملیاتی امروز</h2>
            <span style="font-size:0.8rem;color:var(--tp-text-muted);">والدین به‌طور خودکار پس از ثبت تغییرات مطلع می‌شوند</span>
        </div>

        <div class="tp-task-grid">
            <!-- Attendance Task Card -->
            <div class="tp-task-card <?= $attendanceDone ? 'tp-task-card--done' : 'tp-task-card--pending' ?>">
                <div class="tp-task-head">
                    <h3 class="tp-task-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                        لیست حضور و غیاب امروز
                    </h3>
                    <span class="tp-badge <?= $attendanceDone ? 'tp-badge-present' : 'tp-badge-late' ?>">
                        <?= $attendanceDone ? 'تکمیل شده ✓' : persianNumber((string) $attendanceMissing) . ' نفر ثبت‌نشده' ?>
                    </span>
                </div>
                <div class="tp-progress-track" aria-hidden="true">
                    <div class="tp-progress-fill <?= $attendanceDone ? '' : 'tp-progress-fill--pending' ?>" style="width: <?= $attPercent ?>%;"></div>
                </div>
                <p style="margin:0;font-size:0.84rem;color:var(--tp-text-secondary);">
                    <?php if ($childCount === 0): ?>
                        هنوز کودکی در این کلاس ثبت‌نام نشده است.
                    <?php else: ?>
                        حاضر: <strong><?= e(persianNumber((string) $attendanceCounts['present'])) ?></strong> ·
                        با تأخیر: <strong><?= e(persianNumber((string) $attendanceCounts['late'])) ?></strong> ·
                        غیبت موجه: <strong><?= e(persianNumber((string) $attendanceCounts['excused'])) ?></strong> ·
                        غایب: <strong><?= e(persianNumber((string) $attendanceCounts['absent'])) ?></strong>
                    <?php endif; ?>
                </p>
                <div class="tp-task-foot">
                    <span style="font-size:0.78rem;color:var(--tp-text-muted);"><?= e(persianNumber((string) $attPercent)) ?>٪ تکمیل</span>
                    <a href="<?= e(url('teacher/attendance.php')) ?>" class="tp-btn <?= $attendanceDone ? 'tp-btn-secondary' : 'tp-btn-primary' ?> tp-btn-sm">
                        <?= $attendanceDone ? 'مشاهده و ویرایش حضور' : 'ثبت حضور و غیاب کلاس' ?>
                    </a>
                </div>
            </div>

            <!-- Daily Reports Task Card -->
            <div class="tp-task-card <?= $reportsDone ? 'tp-task-card--done' : 'tp-task-card--pending' ?>">
                <div class="tp-task-head">
                    <h3 class="tp-task-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                        گزارش روزانه کودکان
                    </h3>
                    <span class="tp-badge <?= $reportsDone ? 'tp-badge-present' : 'tp-badge-late' ?>">
                        <?= $reportsDone ? 'تکمیل شده ✓' : persianNumber((string) max(0, $childCount - $reportedChildren)) . ' گزارش باقی‌مانده' ?>
                    </span>
                </div>
                <div class="tp-progress-track" aria-hidden="true">
                    <div class="tp-progress-fill <?= $reportsDone ? '' : 'tp-progress-fill--pending' ?>" style="width: <?= $repPercent ?>%;"></div>
                </div>
                <p style="margin:0;font-size:0.84rem;color:var(--tp-text-secondary);">
                    <?php if ($childCount === 0): ?>
                        هنوز کودکی در این کلاس ثبت‌نام نشده است.
                    <?php else: ?>
                        از مجموع <strong><?= e(persianNumber((string) $childCount)) ?></strong> کودک، گزارش امروز برای
                        <strong><?= e(persianNumber((string) $reportedChildren)) ?></strong> نفر ثبت شده است.
                    <?php endif; ?>
                </p>
                <div class="tp-task-foot">
                    <span style="font-size:0.78rem;color:var(--tp-text-muted);"><?= e(persianNumber((string) $repPercent)) ?>٪ تکمیل</span>
                    <a href="<?= e(url('teacher/report.php')) ?>" class="tp-btn <?= $reportsDone ? 'tp-btn-secondary' : 'tp-btn-primary' ?> tp-btn-sm">
                        <?= $reportsDone ? 'مشاهده و ویرایش گزارش‌ها' : 'ثبت گزارش‌های امروز' ?>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Health & Allergy Watchlist -->
    <?php if ($allergyChildren !== []): ?>
        <section class="tp-health-watch" aria-label="هشدارهای سلامت و حساسیت کودکان کلاس">
            <div class="tp-health-watch-head">
                <h2 class="tp-health-watch-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    مراقبت ویژه و حساسیت‌های ثبت‌شده در کلاس (<?= e(persianNumber((string) count($allergyChildren))) ?> کودک)
                </h2>
                <a href="<?= e(url('teacher/children.php?filter=allergy')) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">مشاهده همه پرونده‌های پزشکی</a>
            </div>
            <div class="tp-health-chips">
                <?php foreach ($allergyChildren as $ac): ?>
                    <?php
                    $acName = trim((string) $ac['first_name'] . ' ' . (string) $ac['last_name']);
                    $acAllergy = trim((string) ($ac['allergies'] ?? ''));
                    $acMed = trim((string) ($ac['medical_notes'] ?? ''));
                    ?>
                    <a href="<?= e(url('teacher/child-detail.php?id=' . (int) $ac['id'])) ?>" class="tp-health-chip">
                        <div>
                            <strong style="display:block;font-size:0.9rem;margin-bottom:3px;"><?= e($acName) ?></strong>
                            <?php if ($acAllergy !== ''): ?>
                                <div style="font-size:0.78rem;color:var(--tp-absent);">حساسیت: <?= e($acAllergy) ?></div>
                            <?php endif; ?>
                            <?php if ($acMed !== ''): ?>
                                <div style="font-size:0.76rem;color:var(--tp-text-secondary);">پزشکی: <?= e(mb_substr($acMed, 0, 60, 'UTF-8')) ?><?= mb_strlen($acMed, 'UTF-8') > 60 ? '…' : '' ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="tp-badge tp-badge-absent">پرونده ›</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Classroom Children Grid -->
    <section class="tp-section" aria-label="فهرست کودکان کلاس">
        <div class="tp-section-header">
            <h2 class="tp-section-title">کودکان کلاس <?= e((string) $classroom['name']) ?> (<?= e(persianNumber((string) $childCount)) ?>)</h2>
            <a href="<?= e(url('teacher/children.php')) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">مدیریت و جست‌وجوی پیشرفته کودکان ←</a>
        </div>

        <?php if ($children === []): ?>
            <div class="tp-empty-state">
                <h3>هنوز کودکی در این کلاس ثبت‌نام نشده است</h3>
                <p>به محض اینکه مدیریت مهد کودکان را به کلاس شما اضافه کند، لیست آن‌ها در این بخش قرار می‌گیرد.</p>
            </div>
        <?php else: ?>
            <div class="tp-children-grid">
                <?php foreach ($children as $child): ?>
                    <?php
                    $cid        = (int) $child['id'];
                    $childName  = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                    $prefName   = trim((string) ($child['preferred_name'] ?? ''));
                    $parentName = trim((string) $child['parent_first_name'] . ' ' . (string) $child['parent_last_name']);
                    $age        = teacherChildDisplayAge((string) $child['date_of_birth']);
                    $hasAllergy = trim((string) ($child['allergies'] ?? '')) !== '' || trim((string) ($child['medical_notes'] ?? '')) !== '';
                    $photoUrl   = !empty($child['photo']) ? url((string) $child['photo']) : '';
                    $attStatus  = (string) ($child['attendance_status'] ?? '');
                    $repMood    = (string) ($child['report_mood'] ?? '');
                    ?>
                    <article class="tp-child-card <?= $hasAllergy ? 'tp-child-card--allergy' : '' ?>">
                        <div class="tp-child-top">
                            <?php if ($photoUrl !== ''): ?>
                                <img src="<?= e($photoUrl) ?>" alt="<?= e($childName) ?>" class="tp-child-avatar">
                            <?php else: ?>
                                <div class="tp-child-avatar"><?= e(userInitial((string) $child['first_name'])) ?></div>
                            <?php endif; ?>
                            <div class="tp-child-identity">
                                <h3 class="tp-child-name">
                                    <a href="<?= e(url('teacher/child-detail.php?id=' . $cid)) ?>"><?= e($childName) ?></a>
                                    <?php if ($prefName !== '' && $prefName !== $child['first_name']): ?>
                                        <span style="font-size:0.78rem;color:var(--tp-text-muted);font-weight:600;">«<?= e($prefName) ?>»</span>
                                    <?php endif; ?>
                                </h3>
                                <p class="tp-child-sub"><?= e($age) ?> · <?= e(teacherChildGenderLabel($child['gender'] ?? null)) ?></p>
                            </div>
                        </div>

                        <?php if ($hasAllergy): ?>
                            <div class="tp-child-allergy-alert">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <span><?= e((string) ($child['allergies'] ?: $child['medical_notes'])) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="tp-child-status-grid">
                            <div class="tp-child-status-box">
                                <span class="tp-child-status-label">حضور امروز</span>
                                <span class="tp-badge <?= e(teacherAttendanceBadgeClass($attStatus)) ?>">
                                    <?= e(teacherAttendanceStatusLabel($attStatus)) ?>
                                </span>
                            </div>
                            <div class="tp-child-status-box">
                                <span class="tp-child-status-label">گزارش امروز</span>
                                <?php if ($repMood !== ''): ?>
                                    <span class="tp-badge <?= e(teacherMoodBadgeClass($repMood)) ?>">
                                        <?= e(teacherMoodLabel($repMood)) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="tp-badge tp-badge-unrecorded">ثبت نشده</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tp-child-parent-row">
                            <span>ولی: <strong><?= e($parentName) ?></strong></span>
                            <?php if (!empty($child['parent_phone'])): ?>
                                <a href="tel:<?= e(telHref((string) $child['parent_phone'])) ?>" class="tp-phone-pill" title="تماس با والد">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
                                    <?= e(persianNumber((string) $child['parent_phone'])) ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="tp-child-actions">
                            <a href="<?= e(url('teacher/child-detail.php?id=' . $cid)) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">پرونده کودک</a>
                            <a href="<?= e(url('teacher/report.php#child-report-' . $cid)) ?>" class="tp-btn tp-btn-primary tp-btn-sm">ثبت گزارش</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Classroom Schedule & Upcoming Events -->
    <section class="tp-detail-grid" aria-label="برنامه کلاس و رویدادهای پیش رو">
        <div class="tp-card">
            <div class="tp-card-header">
                <h2>برنامه هفتگی و مشخصات کلاس</h2>
                <a href="<?= e(url('teacher/schedule.php')) ?>" class="tp-btn tp-btn-outline tp-btn-sm">مشاهده کامل برنامه</a>
            </div>
            <?php if (!empty($classroom['description'])): ?>
                <p style="margin:0 0 12px;color:var(--tp-text-secondary);font-size:0.9rem;"><?= e((string) $classroom['description']) ?></p>
            <?php endif; ?>
            <?php if (!empty($classroom['schedule'])): ?>
                <div style="background:var(--tp-surface-soft);border:1px solid var(--tp-border-light);border-radius:var(--tp-radius-sm);padding:14px;font-size:0.88rem;line-height:1.9;">
                    <?= nl2br(e((string) $classroom['schedule'])) ?>
                </div>
            <?php else: ?>
                <p style="margin:0;color:var(--tp-text-muted);font-size:0.88rem;">برنامه متنی خاصی برای این کلاس توسط مدیریت ثبت نشده است.</p>
            <?php endif; ?>
        </div>

        <div class="tp-card">
            <div class="tp-card-header">
                <h2>رویدادهای پیش روی مهد</h2>
                <a href="<?= e(url('teacher/schedule.php')) ?>" class="tp-btn tp-btn-outline tp-btn-sm">تقویم کامل</a>
            </div>
            <?php if ($upcomingEvents === []): ?>
                <p style="margin:0;color:var(--tp-text-muted);font-size:0.88rem;">رویداد برنامه‌ریزی‌شده‌ای برای روزهای آینده ثبت نشده است.</p>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($upcomingEvents as $ev): ?>
                        <div style="background:var(--tp-surface-soft);border:1px solid var(--tp-border-light);border-radius:var(--tp-radius-sm);padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                            <div>
                                <strong style="display:block;font-size:0.92rem;"><?= e((string) $ev['title']) ?></strong>
                                <span style="font-size:0.78rem;color:var(--tp-text-secondary);">
                                    <?= e(shamsiDate((string) $ev['event_date'], 'with_day')) ?>
                                    <?php if (!empty($ev['start_time'])): ?>
                                        · ساعت <?= e(persianNumber(teacherFormatTimeShort((string) $ev['start_time']))) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <span class="tp-badge tp-badge-excused"><?= e(teacherEventCategoryLabel((string) ($ev['category'] ?? 'general'))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
