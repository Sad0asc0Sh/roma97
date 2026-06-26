<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';

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

if ($childId === 0) {
    setFlash('error', 'کودک مورد نظر یافت نشد.');
    redirect(url('parent/index.php'));
}

$row = null;
$parentRow = null;
$todayAtt = null;
$dailyReport = null;
$classroom = null;

try {
    initializeParentTables();
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

        // Fetch today's per-child daily report
        $reportStmt = $pdo->prepare(
            <<<'SQL'
SELECT dr.mood, dr.activities, dr.notes, t.first_name, t.last_name
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
$initial = strtoupper(substr((string) ($row['first_name'] ?? ''), 0, 1));
$status = (string) ($row['status'] ?? 'pending');
$enrollClass = parentChildEnrollmentClass($status);
$allergiesText = trim((string) ($row['allergies'] ?? ''));
$parentDisplayName = trim((string) $parentRow['first_name'] . ' ' . (string) $parentRow['last_name']);

$statusLabel = match ($status) {
    'pending' => 'در انتظار تأیید',
    'active' => 'ثبت‌نام شده',
    'inactive' => 'غیرفعال',
    default => $status,
};

$pageTitle = 'جزئیات ' . ($fullName !== '' ? $fullName : 'کودک');
require_once __DIR__ . '/header.php';
?>

<p class="parent-back-link">
    <a href="<?= e(url('parent/index.php')) ?>">← بازگشت به داشبورد</a>
</p>

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
        <h2>حضور و غیاب امروز</h2>
        <?php if ($todayAtt !== null): ?>
            <div class="attendance-today">
                <?php
                $attStatus = (string) ($todayAtt['status'] ?? '');
                $attBadge = match($attStatus) {
                    'present' => '<span class="attendance-badge badge-present">حاضر</span>',
                    'absent' => '<span class="attendance-badge badge-absent">غایب</span>',
                    'late' => '<span class="attendance-badge badge-late">تأخیر</span>',
                    'excused' => '<span class="attendance-badge badge-excused">غیبت موجه</span>',
                    default => '<span class="attendance-badge badge-not-marked">ثبت نشده</span>'
                };
                echo $attBadge;
                ?>
                <div class="attendance-times">
                    <?php if (!empty($todayAtt['check_in'])): ?>
                        <p><strong>زمان ورود</strong> <?= e(parentPortalFormatTimeShort($todayAtt['check_in']))?></p>
                    <?php endif; ?>
                    <?php if (!empty($todayAtt['check_out'])): ?>
                        <p><strong>زمان خروج</strong> <?= e(parentPortalFormatTimeShort($todayAtt['check_out']))?></p>
                    <?php endif; ?>
                    <?php if (!empty($todayAtt['notes'])): ?>
                        <p class="attendance-notes"><strong>یادداشت</strong> <?= nl2br(e((string) $todayAtt['notes']))?></p>
                    <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
            <p class="muted">هنوز حضور و غیاب امروز ثبت نشده است</p>
        <?php endif; ?>
  </div>

    <!-- Today's Report -->
    <div class="child-detail-card">
        <h2>گزارش روزانه امروز</h2>
        <?php if ($dailyReport !== null): ?>
            <div class="daily-report">
                <p>
                    <strong>حال و هوای کلاس</strong>
                    <?php
                    $moodEmoji = match ($dailyReport['mood']) {
                        'happy'       => '😊 شاد',
                        'normal'      => '😐 عادی',
                        'mixed'       => '😅 ترکیبی',
                        'challenging' => '😤 چالش‌برانگیز',
                        default       => e((string) ($dailyReport['mood'] ?? 'ثبت نشده')),
                    };
                    echo $moodEmoji;
                    ?>
               </p>
                <?php if (!empty($dailyReport['activities'])): ?>
                    <p><strong>فعالیت‌ها</strong><br><?= nl2br(e((string) $dailyReport['activities']))?></p>
                <?php endif; ?>
                <?php if (!empty($dailyReport['notes'])): ?>
                    <p><strong>یادداشت معلم</strong><br><?= nl2br(e((string) $dailyReport['notes']))?></p>
                <?php endif; ?>
                <p class="report-teacher">
                    گزارش توسط: <?= e(trim($dailyReport['first_name'] . ' ' . $dailyReport['last_name'])) ?>
               </p>
        </div>
        <?php else: ?>
            <p class="muted">گزارش امروز هنوز توسط معلم ارسال نشده است</p>
        <?php endif; ?>
  </div>

    <!-- Allergies -->
    <?php if ($allergiesText !== ''): ?>
        <div class="child-detail-card child-alert-card">
            <h2>⚠️ حساسیت‌ها</h2>
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
