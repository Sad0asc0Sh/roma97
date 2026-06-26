<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';

requireTeacherLogin();

$teacherId   = (int) $_SESSION['teacher_id'];
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'معلم');
$today       = date('Y-m-d');

$classroom  = null;
$children   = [];
$report     = null;
$error      = null;

try {
    initializeTeachersTables();
    $pdo = getDb();

    // Fetch assigned classroom
    $cStmt = $pdo->prepare(
        'SELECT id, name, description, capacity, schedule FROM classrooms WHERE teacher_id = :tid LIMIT 1'
    );
    $cStmt->execute([':tid' => $teacherId]);
    $classroom = $cStmt->fetch() ?: null;

    if ($classroom !== null) {
        $classroomId = (int) $classroom['id'];

        // Fetch children in this classroom with parent info + today's attendance
        $chStmt = $pdo->prepare(
            <<<'SQL'
SELECT
    ch.id,
    ch.first_name,
    ch.last_name,
    ch.date_of_birth,
    ch.allergies,
    ch.photo,
    p.first_name  AS parent_first_name,
    p.last_name   AS parent_last_name,
    p.phone       AS parent_phone,
    a.status      AS attendance_status
FROM child_classroom cc
INNER JOIN children ch ON ch.id = cc.child_id
INNER JOIN parents  p  ON p.id  = ch.parent_id
LEFT  JOIN attendance a
       ON  a.child_id = ch.id
       AND a.attendance_date = :today
WHERE cc.classroom_id = :cid
  AND ch.status = 'active'
ORDER BY ch.last_name, ch.first_name
SQL
        );
        $chStmt->execute([':cid' => $classroomId, ':today' => $today]);
        $children = $chStmt->fetchAll();

        // Today's daily report
        $rStmt = $pdo->prepare(
            'SELECT id, mood, activities, notes FROM daily_reports WHERE classroom_id = :cid AND report_date = :today LIMIT 1'
        );
        $rStmt->execute([':cid' => $classroomId, ':today' => $today]);
        $report = $rStmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    $error = 'بارگذاری اطلاعات داشبورد با مشکل مواجه شد. لطفاً صفحه را تازه‌سازی کنید.';
}

$pageTitle = 'داشبورد معلم | ' . e(siteName());
require_once __DIR__ . '/header.php';

// Helper: compute readable age
function teacherDashAge(string $dob): string
{
    try {
        $birth = new DateTimeImmutable($dob);
        $diff  = $birth->diff(new DateTimeImmutable('today'));
        if ($diff->y < 1) {
            return $diff->m . ' ماه';
        }
        return $diff->y . ' سال';
    } catch (Throwable) {
        return '';
    }
}

// Helper: attendance badge
function attendanceBadge(?string $status): string
{
    return match ($status) {
        'present'  => '<span class="badge badge-success">حاضر</span>',
        'absent'   => '<span class="badge badge-danger">غایب</span>',
        'late'     => '<span class="badge badge-warning">تأخیر</span>',
        'excused'  => '<span class="badge badge-info">غیبت موجه</span>',
        default    => '<span class="badge">ثبت نشده</span>',
    };
}
?>

<div class="teacher-dashboard">
    <div class="teacher-welcome">
        <h1>👋 خوش آمدید، <?= e($teacherName) ?></h1>
        <p class="text-muted">تاریخ امروز: <?= e(persianDayName($today)) ?> <?= e(shamsiDate($today))?></p>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($classroom === null): ?>
        <!-- No classroom assigned -->
        <div class="teacher-no-classroom">
            <div class="teacher-no-classroom-icon">🏫</div>
            <h2>هیچ کلاسی به شما اختصاص داده نشده است</h2>
            <p>هنوز کلاسی به شما اختصاص داده نشده است. لطفاً با مدیر تماس بگیرید.</p>
        </div>
    <?php else: ?>
        <!-- Classroom info card -->
        <div class="teacher-section">
            <div class="classroom-info-card">
                <div class="classroom-info-header">
                    <h2>🏫 <?= e((string) $classroom['name']) ?></h2>
                </div>
                <div class="classroom-info-grid">
                    <div class="classroom-info-item">
                        <span class="info-label">ظرفیت</span>
                        <span class="info-value"><?= e((string) $classroom['capacity']) ?> کودک</span>
                    </div>
                    <div class="classroom-info-item">
                        <span class="info-label">ثبت‌نام شده امروز</span>
                        <span class="info-value"><?= count($children) ?></span>
                    </div>
                    <?php if (!empty($classroom['description'])): ?>
                    <div class="classroom-info-item classroom-info-full">
                        <span class="info-label">توضیحات</span>
                        <span class="info-value"><?= e((string) $classroom['description']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($classroom['schedule'])): ?>
                    <div class="classroom-info-item classroom-info-full">
                        <span class="info-label">برنامه هفتگی</span>
                        <span class="info-value"><?= nl2br(e((string) $classroom['schedule'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Daily Report Widget -->
        <div class="teacher-section">
            <h2 class="teacher-section-title">📋 گزارش روزانه امروز</h2>
            <?php if ($report !== null): ?>
                <div class="daily-report-card">
                    <div class="daily-report-item">
                        <strong>حال و هوا:</strong>
                        <?php
                        $moodEmoji = match ($report['mood']) {
                            'happy'       => '😊 شاد',
                            'normal'      => '😐 عادی',
                            'mixed'       => '😅 ترکیبی',
                            'challenging' => '😓 چالش‌برانگیز',
                            default       => e($report['mood']),
                        };
                        echo $moodEmoji;
                        ?>
                    </div>
                    <?php if (!empty($report['activities'])): ?>
                    <div class="daily-report-item">
                        <strong>فعالیت‌ها:</strong>
                        <p><?= nl2br(e((string) $report['activities'])) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($report['notes'])): ?>
                    <div class="daily-report-item">
                        <strong>یادداشت:</strong>
                        <p><?= nl2br(e((string) $report['notes'])) ?></p>
                    </div>
                    <?php endif; ?>
                    <a href="<?= e(url('teacher/report.php')) ?>" class="btn btn-primary btn-sm">ویرایش گزارش</a>
                </div>
            <?php else: ?>
                <div class="daily-report-empty">
                    <p>گزارش روزانه‌ای برای امروز ثبت نشده است.</p>
                    <a href="<?= e(url('teacher/report.php')) ?>" class="btn btn-primary">ایجاد گزارش امروز</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Children Cards -->
        <div class="teacher-section">
            <h2 class="teacher-section-title">👶 کودکان کلاس شما (<?= count($children) ?>)</h2>
            <?php if (empty($children)): ?>
                <p class="text-muted">هیچ کودکی در این کلاس ثبت‌نام نشده است.</p>
            <?php else: ?>
                <div class="children-grid">
                    <?php foreach ($children as $child): ?>
                        <?php
                        $childName = e($child['first_name'] . ' ' . $child['last_name']);
                        $parentName = e($child['parent_first_name'] . ' ' . $child['parent_last_name']);
                        $age = teacherDashAge((string) $child['date_of_birth']);
                        $hasAllergy = !empty($child['allergies']);
                        $photoUrl = !empty($child['photo']) ? e(url((string) $child['photo'])) : '';
                        ?>
                        <div class="child-card">
                            <div class="child-card-header">
                                <?php if ($photoUrl !== ''): ?>
                                    <img src="<?= $photoUrl ?>" alt="<?= $childName ?>" class="child-card-photo">
                                <?php else: ?>
                                    <div class="child-card-photo-placeholder">👶</div>
                                <?php endif; ?>
                                <?php if ($hasAllergy): ?>
                                    <span class="child-allergy-badge" title="دارای حساسیت">⚠️</span>
                                <?php endif; ?>
                            </div>
                            <div class="child-card-body">
                                <h3 class="child-card-name"><?= $childName ?></h3>
                                <?php if ($age !== ''): ?>
                                    <p class="child-card-age text-muted"><?= e($age) ?> سن</p>
                                <?php endif; ?>
                                <?php if ($hasAllergy): ?>
                                    <div class="child-allergy-note">
                                        <strong>⚠️ حساسیت‌ها:</strong> <?= e((string) $child['allergies']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="child-card-attendance">
                                    <strong>حضور و غیاب:</strong>
                                    <?= attendanceBadge($child['attendance_status'] !== '' ? $child['attendance_status'] : null) ?>
                                </div>
                                <div class="child-card-parent">
                                    <span class="info-label">والد:</span>
                                    <strong><?= $parentName ?></strong>
                                    <?php if (!empty($child['parent_phone'])): ?>
                                        <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $child['parent_phone'])) ?>"
                                           class="parent-phone-link">
                                            📞 <?= e((string) $child['parent_phone']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
