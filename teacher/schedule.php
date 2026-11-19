<?php
declare(strict_types=1);

/**
 * Teacher Portal — Class schedule & upcoming events (برنامه و رویدادها).
 *
 * Read-only: the weekly schedule text of the teacher's own
 * classroom(s) (maintained by the admin panel) plus the centre-wide
 * upcoming events the dashboard already previews — here in full.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';
require_once __DIR__ . '/../includes/school_planning.php';

requireTeacherLogin();

$teacherId = (int) $_SESSION['teacher_id'];
$today     = date('Y-m-d');

$classrooms = [];
$workdaysByClass = [];
$events     = [];
$error      = getFlash('error');

try {
    initializeTeachersTables();
    $pdo = getDb();

    $ctx        = teacherResolveClassroomContext($pdo, $teacherId);
    $classrooms = $ctx['classrooms'];
    $workdaysByClass = classroomWorkdays($pdo);

    $evStmt = $pdo->prepare(
        "SELECT id, title, description, event_date, start_time, end_time, category
         FROM events
         WHERE event_date >= :today AND status = 'scheduled'
         ORDER BY event_date ASC, start_time IS NULL ASC, start_time ASC
         LIMIT 30"
    );
    $evStmt->execute([':today' => $today]);
    $events = $evStmt->fetchAll();
} catch (Throwable $e) {
    error_log('teacher/schedule failed: ' . $e->getMessage());
    $error = 'بارگذاری برنامه و رویدادها با مشکل مواجه شد.';
}

$pageTitle = 'برنامه و رویدادها | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<div class="tp-page-header">
    <div>
        <h1>🗓️ برنامه کلاسی و رویدادها</h1>
        <p class="tp-page-subtitle">برنامه هفتگی کلاس‌های شما و رویدادهای پیش‌روی مهد در ۳۰ روز آینده.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<?php if ($classrooms === []): ?>
    <div class="tp-empty-state">
        <div class="tp-empty-icon">🏫</div>
        <h2>هیچ کلاسی به شما اختصاص داده نشده است</h2>
        <p>پس از اختصاص کلاس توسط مدیریت، برنامه کلاسی شما اینجا نمایش داده می‌شود.</p>
    </div>
<?php else: ?>
    <?php foreach ($classrooms as $cl): ?>
        <section class="tp-card">
            <div class="tp-card-header">
                <h2>برنامه کلاس <?= e((string) $cl['name']) ?></h2>
                <span class="tp-badge tp-badge-excused"><?= e(persianNumber((string) ($cl['active_children_count'] ?? 0))) ?> کودک</span>
            </div>
            <?php if (trim((string) ($cl['description'] ?? '')) !== ''): ?>
                <p style="line-height:1.9;color:var(--tp-text-secondary);"><?= e((string) $cl['description']) ?></p>
            <?php endif; ?>
            <?php $workdays = array_intersect_key(SCHOOL_WEEKDAYS, array_fill_keys($workdaysByClass[$cl['id']] ?? [], true)); ?>
            <?php if ($workdays !== []): ?>
                <p class="tp-day-note">روزهای کاری کلاس · <?= e(implode('، ', $workdays)) ?></p>
            <?php else: ?>
                <p style="color:var(--tp-text-muted);">روزهای کاری کلاس هنوز تعیین نشده‌اند.</p>
            <?php endif; ?>
            <?php if (trim((string) ($cl['schedule'] ?? '')) !== ''): ?>
                <p style="line-height:2;white-space:pre-line;"><?= e((string) $cl['schedule']) ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<section class="tp-card">
    <div class="tp-card-header"><h2>رویدادهای پیش‌رو</h2></div>
    <?php if ($events === []): ?>
        <p style="color:var(--tp-text-muted);margin:0;">در ۳۰ روز آینده رویداد برنامه‌ریزی‌شده‌ای ثبت نشده است.</p>
    <?php else: ?>
        <div class="tp-msg-list">
            <?php foreach ($events as $ev): ?>
                <div class="tp-msg-item" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div>
                        <strong><?= e((string) $ev['title']) ?></strong>
                        <div style="font-size:0.82rem;color:var(--tp-text-secondary);line-height:1.8;">
                            <?= e(shamsiDate((string) $ev['event_date'], 'with_day')) ?>
                            <?php if (!empty($ev['start_time'])): ?>
                                · ساعت <?= e(formatIranTime($ev['start_time'])) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (trim((string) ($ev['description'] ?? '')) !== ''): ?>
                            <p style="margin:4px 0 0;font-size:0.84rem;line-height:1.85;color:var(--tp-text-secondary);"><?= e((string) $ev['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="tp-badge tp-badge-excused"><?= e(teacherEventCategoryLabel((string) ($ev['category'] ?? 'general'))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
