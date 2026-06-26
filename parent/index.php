<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';

requireParentLogin();

$parentId = (int) $_SESSION['parent_id'];
$parentStatus = 'active';
$firstName = (string) ($_SESSION['parent_name'] ?? 'والد');
$avatar = null;
$children = [];
/** @var array<int, string> */
$todayAttendanceByChild = [];
/** @var array<int, string> */
$classroomByChild = [];

$successMessage = getFlash('success');
$errorMessage = getFlash('error');

try {
    initializeParentTables();
    $pdo = getDb();

    $statement = $pdo->prepare(
        'SELECT first_name, last_name, status, avatar FROM parents WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $parentId]);
    $parent = $statement->fetch();

    if ($parent) {
        $parentStatus = (string) $parent['status'];
        $firstName = (string) $parent['first_name'];
        $avatar = $parent['avatar'] ?: null;
        $_SESSION['parent_name'] = trim($parent['first_name'] . ' ' . $parent['last_name']);
        $_SESSION['parent_first_name'] = $firstName;
    }

    // Fetch children with classroom information
    $childrenStatement = $pdo->prepare(
        'SELECT c.id, c.first_name, c.last_name, c.preferred_name, c.date_of_birth, c.gender,
                c.allergies, c.photo, c.status, c.created_at,
                cl.name as classroom_name
         FROM children c
         LEFT JOIN child_classroom cc ON cc.child_id = c.id
         LEFT JOIN classrooms cl ON cl.id = cc.classroom_id
         WHERE c.parent_id = :parent_id
         ORDER BY c.created_at DESC'
    );
    $childrenStatement->execute([':parent_id' => $parentId]);
    $children = $childrenStatement->fetchAll();

    if ($children !== []) {
        $todayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');
        $todayStmt = $pdo->prepare(
            <<<'SQL'
SELECT a.child_id, a.status
FROM attendance a
INNER JOIN children c ON c.id = a.child_id AND c.parent_id = :parent_id
WHERE a.attendance_date = :attendance_date
SQL
        );
        $todayStmt->execute([
            ':parent_id' => $parentId,
            ':attendance_date' => $todayYmd,
        ]);

        while ($ar = $todayStmt->fetch()) {
            $todayAttendanceByChild[(int) $ar['child_id']] = (string) $ar['status'];
        }
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    if ($errorMessage === null || $errorMessage === '') {
        $errorMessage = 'برخی اطلاعات بارگذاری نشد. لطفاً بعداً دوباره تلاش کنید.';
    }
}

$statusMessage = match ($parentStatus) {
    'pending' => 'حساب شما در انتظار تأیید است.',
    'suspended' => 'حساب شما مسدود شده است. با ما تماس بگیرید.',
    default => 'حساب شما فعال است.',
};

$pageTitle = 'داشبورد والدین';
require_once __DIR__ . '/header.php';
?>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage)?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage)?></div>
<?php endif; ?>

<?php if (isset($unreadCount) && $unreadCount > 0): ?>
    <div class="alert alert-info unread-messages-alert">
        <div class="unread-messages-inner">
            <span class="unread-messages-icon">🔔</span>
            <div>
                <strong class="unread-messages-title">شما <?= e(persianNumber((string) $unreadCount)) ?> پیام خوانده نشده دارید</strong>
                <p class="unread-messages-desc">صندوق ورودی خود را برای پیام‌های جدید از مدیران و معلمان بررسی کنید</p>
         </div>
      </div>
        <a href="<?= e(url('parent/messages.php')) ?>" class="btn btn-primary btn-sm">مشاهده پیام‌ها</a>
 </div>
<?php endif; ?>

<!-- Welcome Section -->
<section class="parent-welcome">
    <div class="parent-welcome-content">
        <?php if ($avatar !== null): ?>
            <img class="parent-avatar-large" src="<?= e(url($avatar)) ?>" alt="<?= e($firstName) ?>">
        <?php else: ?>
            <div class="parent-avatar-large parent-avatar-placeholder">
                <?= e(strtoupper(substr($firstName, 0, 1))) ?>
         </div>
        <?php endif; ?>
        <div class="parent-welcome-text">
            <h1>خوش آمدید، <?= e($firstName) ?> عزیز! 🌟</h1>
            <p class="parent-welcome-subtitle">امروز کوچولوهای شما چه خبر؟</p>
            <?php
            $statusLabel = match ($parentStatus) {
                'pending' => 'در انتظار تأیید',
                'suspended' => 'مسدود شده',
                default => 'فعال',
            };
            ?>
            <span class="status-badge status-badge-<?= e($parentStatus) ?>"><?= e($statusLabel)?></span>
      </div>
  </div>
</section>

<!-- Your Children Section -->
<section class="parent-section">
    <div class="parent-section-header">
        <h2>فرزندان شما 👨‍👩‍👧‍👦</h2>
        <?php if ($children !== []): ?>
            <a class="btn-text" href="<?= e(url('parent/add-child.php')) ?>">+ افزودن کودک</a>
        <?php endif; ?>
  </div>

    <?php if ($children === []): ?>
        <div class="parent-empty-state">
            <div class="empty-state-icon">🌟</div>
            <h3>هنوز کودکی ثبت نشده است</h3>
            <p>برای شروع، کوچولوی خود را در <?= e(siteName()) ?> ثبت کنید</p>
            <a class="btn btn-primary" href="<?= e(url('parent/add-child.php')) ?>">افزودن کودک</a>
      </div>
    <?php else: ?>
        <div class="children-grid-large">
            <?php foreach ($children as $child): ?>
                <?php
                $childId = (int) $child['id'];
                $fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                $preferredName = trim((string) ($child['preferred_name'] ?? ''));
                $displayName = $preferredName !== '' ? $preferredName : (string) $child['first_name'];
                $initial = strtoupper(substr((string) ($child['first_name'] ?? ''), 0, 1));
                $hasAllergies = trim((string) ($child['allergies'] ?? '')) !== '';
                $gender = (string) ($child['gender'] ?? '');
                $genderIcon = match($gender) {
                    'male' => '👦',
                    'female' => '👧',
                    default => '👤'
                };
                $genderLabel = match($gender) {
                    'male' => 'پسر',
                    'female' => 'دختر',
                    default => 'سایر'
                };

                // Calculate age
                $dob = (string) ($child['date_of_birth'] ?? '');
                $age = '';
                if ($dob !== '') {
                    try {
                        $dobDate = new DateTimeImmutable($dob);
                        $now = new DateTimeImmutable('today');
                        $diff = $now->diff($dobDate);
                        $years = (int) $diff->y;
                        $months = (int) $diff->m;
                        if ($years > 0) {
                            $age = persianNumber((string) $years) . ' سال';
                        } else {
                            $age = persianNumber((string) $months) . ' ماه';
                        }
                    } catch (Exception $e) {
                        $age = '';
                    }
                }

                // Today's attendance status
                $todayStatus = $todayAttendanceByChild[$childId] ?? 'not_marked';
                $statusBadge = match($todayStatus) {
                    'present' => '<span class="attendance-badge badge-present">حاضر</span>',
                    'absent' => '<span class="attendance-badge badge-absent">غایب</span>',
                    'late' => '<span class="attendance-badge badge-late">تأخیر</span>',
                    'excused' => '<span class="attendance-badge badge-excused">غیبت موجه</span>',
                    default => '<span class="attendance-badge badge-not-marked">ثبت نشده</span>'
                };

                // Classroom
                $classroom = trim((string) ($child['classroom_name'] ?? ''));
                $classroomDisplay = $classroom !== '' ? $classroom : 'تعیین نشده';
                ?>
                <article class="child-card-large">
                    <div class="child-card-header">
                        <?php if (!empty($child['photo'])): ?>
                            <img class="child-photo-large" src="<?= e(url((string) $child['photo'])) ?>" alt="<?= e($fullName) ?>">
                        <?php else: ?>
                            <div class="child-photo-large child-photo-placeholder">
                                <?= e($initial) ?>
                         </div>
                        <?php endif; ?>
                        <div class="child-card-info">
                            <h3><?= e($fullName)?></h3>
                            <?php if ($preferredName !== '' && $preferredName !== $child['first_name']): ?>
                                <p class="child-nickname">«<?= e($preferredName) ?>»</p>
                            <?php endif; ?>
                            <div class="child-meta">
                                <span><?= e($genderIcon) ?> <?= e($genderLabel)?></span>
                                <?php if ($age !== ''): ?>
                                    <span>•</span>
                                    <span><?= e($age)?></span>
                                <?php endif; ?>
                         </div>
                      </div>
                  </div>

                    <div class="child-card-body">
                        <div class="child-status-row">
                            <div class="child-status-item">
                                <span class="status-label">وضعیت امروز</span>
                                <?= $statusBadge ?>
                         </div>
                      </div>

                        <div class="child-info-row">
                            <div class="child-info-item">
                                <span class="info-icon">🏫</span>
                                <span>کلاس: <strong><?= e($classroomDisplay) ?></strong></span>
                         </div>
                      </div>

                        <?php if ($hasAllergies): ?>
                            <div class="child-alert-row">
                                <span class="alert-icon">⚠️</span>
                                <span class="alert-text">حساسیت دارد</span>
                         </div>
                        <?php endif; ?>
                  </div>

                    <div class="child-card-footer">
                        <a class="btn btn-primary" href="<?= e(url('parent/child-detail.php?id=' . $childId)) ?>">مشاهده جزئیات</a>
                        <a class="btn btn-outline" href="<?= e(url('parent/attendance.php')) ?>">حضور و غیاب</a>
                  </div>
              </article>
            <?php endforeach; ?>
      </div>
    <?php endif; ?>
</section>

<!-- Quick Actions Section -->
<section class="parent-section">
    <h2>دسترسی سریع ⚡</h2>
    <div class="parent-quick-actions">
        <a class="quick-action-card" href="<?= e(url('parent/add-child.php')) ?>">
            <span class="quick-action-icon">➕</span>
            <span class="quick-action-title">افزودن کودک</span>
            <span class="quick-action-desc">ثبت کودک جدید</span>
      </a>
        <a class="quick-action-card" href="<?= e(url('parent/attendance.php')) ?>">
            <span class="quick-action-icon">📅</span>
            <span class="quick-action-title">مشاهده حضور و غیاب</span>
            <span class="quick-action-desc">بررسی رکوردهای هفتگی</span>
      </a>
        <a class="quick-action-card" href="<?= e(url('parent/payments.php')) ?>">
            <span class="quick-action-icon">💳</span>
            <span class="quick-action-title">پرداخت شهریه</span>
            <span class="quick-action-desc">مشاهده تاریخچه پرداخت‌ها</span>
      </a>
        <a class="quick-action-card" href="<?= e(url('parent/profile.php')) ?>">
            <span class="quick-action-icon">⚙️</span>
            <span class="quick-action-title">ویرایش پروفایل</span>
            <span class="quick-action-desc">تغییر اطلاعات شخصی</span>
      </a>
  </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
