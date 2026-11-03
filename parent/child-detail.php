<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';

requireParentLogin();

function parseParentChildId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return is_int($id) ? $id : 0;
}

$parentId = (int) $_SESSION['parent_id'];
$childId = parseParentChildId($_GET['id'] ?? null);

$successMessage = getFlash('success');
$errorMessage = getFlash('error');

if ($childId === 0) {
    setFlash('error', 'کودک مورد نظر یافت نشد.');
    redirect(url('parent/index.php'));
}

$row = null;
$parentRow = null;
$todayAtt = null;
$dailyReport = null;
$dailyReportHistory = [];
$classroom = null;

try {
    initializeParentTables();
    initializeTeachersTables();
    $pdo = getDb();

    $parentStmt = $pdo->prepare(
        'SELECT first_name, last_name, email, phone FROM parents WHERE id = :id LIMIT 1'
    );
    $parentStmt->execute([':id' => $parentId]);
    $parentRow = $parentStmt->fetch() ?: null;

    if ($parentRow === null) {
        unset($_SESSION['parent_id'], $_SESSION['parent_name']);
        redirect(url('login.php'));
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.preferred_name,
    c.date_of_birth,
    c.gender,
    c.allergies,
    c.medical_notes,
    c.second_guardian_name,
    c.second_guardian_phone,
    c.photo,
    c.status,
    c.created_at,
    cl.name as classroom_name
FROM children c
LEFT JOIN child_classroom cc ON cc.child_id = c.id
LEFT JOIN classrooms cl ON cl.id = cc.classroom_id
WHERE c.id = :child_id AND c.parent_id = :parent_id
LIMIT 1
SQL
    );
    $statement->execute([
        ':child_id' => $childId,
        ':parent_id' => $parentId,
    ]);
    $row = $statement->fetch() ?: null;

    if ($row !== null) {
        $classroom = trim((string) ($row['classroom_name'] ?? ''));

        $todayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');
        $todayStmt = $pdo->prepare(
            <<<'SQL'
SELECT a.status, a.check_in, a.check_out, a.notes
FROM attendance a
INNER JOIN children c ON c.id = a.child_id AND c.parent_id = :parent_id
WHERE a.child_id = :child_id AND a.attendance_date = :attendance_date
LIMIT 1
SQL
        );
        $todayStmt->execute([
            ':parent_id' => $parentId,
            ':child_id' => $childId,
            ':attendance_date' => $todayYmd,
        ]);
        $todayAtt = $todayStmt->fetch() ?: null;

        // Fetch today's per-child daily report including psychological assessment fields
        $reportStmt = $pdo->prepare(
            <<<'SQL'
SELECT dr.mood, dr.social_interaction, dr.focus_level, dr.communication_style,
       dr.separation_adaptation, dr.nutrition_status, dr.rest_energy,
       dr.strengths_observed, dr.coping_strategy,
       dr.activities, dr.notes, t.first_name, t.last_name
FROM daily_reports dr
INNER JOIN teachers t ON t.id = dr.teacher_id
WHERE dr.child_id = :child_id AND dr.report_date = :today
LIMIT 1
SQL
        );
        $reportStmt->execute([
            ':child_id' => $childId,
            ':today' => $todayYmd,
        ]);
        $dailyReport = $reportStmt->fetch() ?: null;

        // Report history — last 4 weeks
        $historyStmt = $pdo->prepare(
            <<<'SQL'
SELECT dr.report_date, dr.mood, dr.social_interaction, dr.focus_level, dr.communication_style,
       dr.separation_adaptation, dr.nutrition_status, dr.rest_energy,
       dr.strengths_observed, dr.coping_strategy,
       dr.activities, dr.notes, t.first_name, t.last_name
FROM daily_reports dr
INNER JOIN teachers t ON t.id = dr.teacher_id
WHERE dr.child_id = :child_id AND dr.report_date < :today
ORDER BY dr.report_date DESC
LIMIT 28
SQL
        );
        $historyStmt->execute([':child_id' => $childId, ':today' => $todayYmd]);
        $dailyReportHistory = $historyStmt->fetchAll();
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'این پروفایل بارگذاری نشد.');
    redirect(url('parent/index.php'));
}

if ($row === null) {
    setFlash('error', 'شما به اطلاعات این کودک دسترسی ندارید.');
    redirect(url('parent/index.php'));
}

$fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
$preferredName = trim((string) ($row['preferred_name'] ?? ''));
$initial = userInitial((string) ($row['first_name'] ?? ''));
$status = (string) ($row['status'] ?? 'pending');
$enrollClass = parentChildEnrollmentClass($status);
$allergiesText = trim((string) ($row['allergies'] ?? ''));
$parentDisplayName = trim((string) $parentRow['first_name'] . ' ' . (string) $parentRow['last_name']);

$statusLabel = match ($status) {
    'pending' => 'در انتظار تأیید',
    'active' => 'ثبت‌نام شده',
    'inactive' => 'غیرفعال',
    'graduated' => 'فارغ‌التحصیل',
    'withdrawn' => 'انصراف داده',
    default => $status,
};

$pageTitle = 'جزئیات ' . ($fullName !== '' ? $fullName : 'کودک');
require_once __DIR__ . '/header.php';
?>

<p class="parent-back-link">
    <a href="<?= e(url('parent/index.php')) ?>">← بازگشت به داشبورد</a>
</p>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<div class="child-detail-container">
    <!-- Main Info Card -->
    <div class="child-detail-card">
        <div class="child-detail-header">
            <?php if (!empty($row['photo'])): ?>
                <img class="child-detail-photo" src="<?= e(url((string) $row['photo'])) ?>" alt="<?= e($fullName) ?>">
            <?php else: ?>
                <div class="child-detail-photo child-photo-placeholder">
                    <?= e($initial) ?>
            </div>
            <?php endif; ?>
            <div class="child-detail-title">
                <h1><?= e($fullName) ?></h1>
                <?php if ($preferredName !== '' && $preferredName !== $row['first_name']): ?>
                    <p class="child-nickname">«<?= e($preferredName) ?>»</p>
                <?php endif; ?>
                <span class="status-badge status-badge-<?= e($status) ?>"><?= e($statusLabel) ?></span>
                <p style="margin-top: 0.75rem;">
                    <a class="btn btn-outline btn-sm" href="<?= e(url('parent/edit-child.php?id=' . $childId)) ?>">✏️ ویرایش اطلاعات کودک</a>
                </p>
          </div>
      </div>

        <div class="child-detail-grid">
            <div class="detail-item">
                <span class="detail-label">تاریخ تولد</span>
                <span class="detail-value"><?= e(shamsiDate((string) ($row['date_of_birth'] ?? ''))) ?></span>
          </div>
            <div class="detail-item">
                <span class="detail-label">سن</span>
                <span class="detail-value"><?= e(parentChildDisplayAge((string) ($row['date_of_birth'] ?? ''))) ?></span>
          </div>
            <div class="detail-item">
                <span class="detail-label">جنسیت</span>
                <span class="detail-value"><?= e(parentChildGenderLabel(($row['gender'] ?? '') !== '' ? (string) $row['gender'] : null)) ?></span>
          </div>
            <div class="detail-item">
                <span class="detail-label">کلاس</span>
                <span class="detail-value"><?= e($classroom !== '' ? $classroom : 'تعیین نشده') ?></span>
          </div>
            <div class="detail-item">
                <span class="detail-label">تاریخ ثبت‌نام</span>
                <span class="detail-value"><?= e(parentChildDetailDateLabel((string) ($row['created_at'] ?? ''))) ?></span>
          </div>
      </div>
  </div>

    <!-- Today's Attendance -->
    <div class="child-detail-card">
        <h2>حضور و گذار صبحگاهی امروز</h2>
        <?php if ($todayAtt !== null): ?>
            <div class="attendance-today">
                <?php
                $attStatus = (string) ($todayAtt['status'] ?? '');
                $attBadge = match($attStatus) {
                    'present' => '<span class="attendance-badge badge-present">حاضر (پذیرش ایمن)</span>',
                    'absent' => '<span class="attendance-badge badge-absent">عدم حضور</span>',
                    'late' => '<span class="attendance-badge badge-late">ورود با تأخیر</span>',
                    'excused' => '<span class="attendance-badge badge-excused">غیبت با هماهنگی</span>',
                    default => '<span class="attendance-badge badge-not-marked">ثبت نشده</span>'
                };
                echo $attBadge;
                ?>
                <div class="attendance-times">
                    <?php if (!empty($todayAtt['check_in'])): ?>
                        <p><strong>زمان پذیرش صبح:</strong> <?= e(parentPortalFormatTimeShort($todayAtt['check_in']))?></p>
                    <?php endif; ?>
                    <?php if (!empty($todayAtt['check_out'])): ?>
                        <p><strong>زمان تحویل:</strong> <?= e(parentPortalFormatTimeShort($todayAtt['check_out']))?></p>
                    <?php endif; ?>
                    <?php if (!empty($todayAtt['notes'])): ?>
                        <p class="attendance-notes"><strong>مشاهده گذار صبحگاهی:</strong> <?= nl2br(e((string) $todayAtt['notes']))?></p>
                    <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
            <p class="muted">هنوز حضور و غیاب امروز ثبت نشده است</p>
        <?php endif; ?>
  </div>

    <!-- Today's Psychological & Developmental Report -->
    <div class="child-detail-card">
        <h2>مشاهده روان‌شناختی و گزارش روزانه امروز</h2>
        <?php if ($dailyReport !== null): ?>
            <div class="daily-report">
                <p>
                    <strong>وضعیت تنظیم هیجانی و آرامش کودک:</strong>
                    <?= e(teacherMoodFullLabel((string) ($dailyReport['mood'] ?? 'normal'))) ?>
                </p>

                <?php if (!empty($dailyReport['social_interaction']) || !empty($dailyReport['focus_level']) || !empty($dailyReport['separation_adaptation'])): ?>
                    <div class="child-detail-grid" style="margin-top:8px;margin-bottom:8px;">
                        <?php if (!empty($dailyReport['social_interaction'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">تعامل با همسالان</span>
                                <span class="detail-value" style="font-size:0.84rem;"><?= e(teacherPsychFactorLabel('social_interaction', (string) $dailyReport['social_interaction'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($dailyReport['focus_level'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">تمرکز و کارکرد اجرایی</span>
                                <span class="detail-value" style="font-size:0.84rem;"><?= e(teacherPsychFactorLabel('focus_level', (string) $dailyReport['focus_level'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($dailyReport['communication_style'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">ابراز هیجان و کلام</span>
                                <span class="detail-value" style="font-size:0.84rem;"><?= e(teacherPsychFactorLabel('communication_style', (string) $dailyReport['communication_style'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($dailyReport['separation_adaptation'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">سازگاری و جدایی صبحگاهی</span>
                                <span class="detail-value" style="font-size:0.84rem;"><?= e(teacherPsychFactorLabel('separation_adaptation', (string) $dailyReport['separation_adaptation'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($dailyReport['nutrition_status'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">رفتار تغذیه‌ای</span>
                                <span class="detail-value" style="font-size:0.84rem;"><?= e(teacherPsychFactorLabel('nutrition_status', (string) $dailyReport['nutrition_status'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($dailyReport['rest_energy'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">تنظیم حسی و انرژی</span>
                                <span class="detail-value" style="font-size:0.84rem;"><?= e(teacherPsychFactorLabel('rest_energy', (string) $dailyReport['rest_energy'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($dailyReport['strengths_observed'])): ?>
                    <p><strong>🌟 نقطه قوت و دستاورد امروز کودک:</strong><br><?= nl2br(e((string) $dailyReport['strengths_observed']))?></p>
                <?php endif; ?>
                <?php if (!empty($dailyReport['coping_strategy'])): ?>
                    <p><strong>🧭 همراهی مربی و پیشنهاد هم‌افزایی برای خانه:</strong><br><?= nl2br(e((string) $dailyReport['coping_strategy']))?></p>
                <?php endif; ?>
                <?php if (!empty($dailyReport['activities'])): ?>
                    <p><strong>🎨 فعالیت‌های رشدی امروز:</strong><br><?= nl2br(e((string) $dailyReport['activities']))?></p>
                <?php endif; ?>
                <?php if (!empty($dailyReport['notes'])): ?>
                    <p><strong>💬 روایت توصیفی مربی:</strong><br><?= nl2br(e((string) $dailyReport['notes']))?></p>
                <?php endif; ?>
                <p class="report-teacher">
                    ثبت‌شده توسط مربی: <?= e(trim($dailyReport['first_name'] . ' ' . $dailyReport['last_name'])) ?>
               </p>
        </div>
        <?php else: ?>
            <p class="muted">گزارش امروز هنوز توسط مربی ثبت نشده است</p>
        <?php endif; ?>
  </div>

    <!-- Report History: the last 4 weeks of the child's story -->
    <?php if ($dailyReportHistory !== []): ?>
    <div class="child-detail-card">
        <h2>گزارش‌های روان‌شناختی و رشدی هفته‌های اخیر</h2>
        <div style="display:grid;gap:12px;">
            <?php foreach ($dailyReportHistory as $hist): ?>
                <div style="border:1px solid var(--border, #e5e5e5);border-radius:12px;padding:12px 14px;">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;align-items:center;">
                        <strong style="font-size:0.9rem;"><?= e(shamsiDate((string) $hist['report_date'], 'with_day')) ?></strong>
                        <span style="font-size:0.85rem;font-weight:700;color:var(--pp-primary);"><?= e(teacherMoodLabel((string) ($hist['mood'] ?? 'normal'))) ?></span>
                    </div>
                    <?php if (trim((string) ($hist['strengths_observed'] ?? '')) !== ''): ?>
                        <p style="margin:8px 0 0;font-size:0.85rem;line-height:1.8;"><strong>🌟 توانمندی مشاهده‌شده:</strong> <?= e((string) $hist['strengths_observed']) ?></p>
                    <?php endif; ?>
                    <?php if (trim((string) ($hist['activities'] ?? '')) !== ''): ?>
                        <p style="margin:6px 0 0;font-size:0.85rem;line-height:1.8;"><strong>🎨 فعالیت‌ها:</strong> <?= nl2br(e((string) $hist['activities'])) ?></p>
                    <?php endif; ?>
                    <?php if (trim((string) ($hist['notes'] ?? '')) !== ''): ?>
                        <p style="margin:6px 0 0;font-size:0.85rem;line-height:1.8;"><strong>💬 یادداشت مربی:</strong> <?= nl2br(e((string) $hist['notes'])) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Allergies -->
    <?php if ($allergiesText !== ''): ?>

        <div class="child-detail-card child-alert-card">
            <h2><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: middle; margin-left: 6px;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>حساسیت‌ها</h2>
            <p><?= nl2br(e($allergiesText))?></p>
     </div>
    <?php else: ?>
        <div class="child-detail-card">
            <h2>حساسیت‌ها</h2>
            <p class="muted">موردی گزارش نشده است</p>
     </div>
    <?php endif; ?>

    <!-- Medical Notes -->
    <div class="child-detail-card">
        <h2>توضیحات پزشکی</h2>
        <?php if (trim((string) ($row['medical_notes'] ?? '')) !== ''): ?>
            <p><?= nl2br(e((string) $row['medical_notes']))?></p>
        <?php else: ?>
            <p class="muted">موردی ثبت نشده است</p>
        <?php endif; ?>
  </div>

    <!-- Second Guardian -->
    <div class="child-detail-card">
        <h2>ولی دوم</h2>
        <?php
        $sgName = trim((string) ($row['second_guardian_name'] ?? ''));
        $sgPhone = trim((string) ($row['second_guardian_phone'] ?? ''));
        ?>
        <?php if ($sgName !== '' || $sgPhone !== ''): ?>
            <?php if ($sgName !== ''): ?>
                <p><strong><?= e($sgName) ?></strong></p>
            <?php endif; ?>
            <?php if ($sgPhone !== ''): ?>
                <p>تلفن: <a href="tel:<?= e(preg_replace('/\s+/', '', $sgPhone)) ?>"><?= e($sgPhone) ?></a></p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">ثبت نشده است</p>
        <?php endif; ?>
  </div>

    <!-- Your Contact Info -->
    <div class="child-detail-card">
        <h2>اطلاعات تماس شما</h2>
        <p><strong><?= e($parentDisplayName !== '' ? $parentDisplayName : 'والد') ?></strong></p>
        <p>ایمیل: <a href="mailto:<?= e((string) $parentRow['email']) ?>"><?= e((string) $parentRow['email']) ?></a></p>
        <?php if (trim((string) ($parentRow['phone'] ?? '')) !== ''): ?>
            <p>تلفن: <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $parentRow['phone'])) ?>"><?= e((string) $parentRow['phone']) ?></a></p>
        <?php else: ?>
            <p class="muted">شماره تلفنی ثبت نشده - در پروفایل خود اضافه کنید</p>
        <?php endif; ?>
        <p class="margin-top-md">
            <a class="btn btn-outline" href="<?= e(url('parent/profile.php')) ?>">ویرایش پروفایل</a>
     </p>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
