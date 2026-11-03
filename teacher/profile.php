<?php
declare(strict_types=1);

/**
 * Teacher Portal — My profile & salary (پروفایل و فیش حقوقی).
 *
 * Read-only self view: the teacher's own record (maintained by the
 * admin panel), their classrooms, contracted salary and the salary
 * payments registered for them. A teacher can only ever see their
 * own row — the query is keyed by the session teacher id.
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

$teacher    = null;
$classrooms = [];
$payments   = [];
$error      = getFlash('error');

try {
    initializeTeachersTables();
    $pdo = getDb();

    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, phone, avatar, national_id,
                education_level, major, role_title, bio, hire_date, salary, status
         FROM teachers WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $teacherId]);
    $teacher = $stmt->fetch() ?: null;

    $ctx        = teacherResolveClassroomContext($pdo, $teacherId);
    $classrooms = $ctx['classrooms'];

    try {
        $payStmt = $pdo->prepare(
            'SELECT amount, payment_date, payment_method, month_year, notes
             FROM salary_payments
             WHERE teacher_id = :id
             ORDER BY payment_date DESC, id DESC
             LIMIT 12'
        );
        $payStmt->execute([':id' => $teacherId]);
        $payments = $payStmt->fetchAll();
    } catch (Throwable $payError) {
        error_log('teacher/profile payments failed: ' . $payError->getMessage());
        $payments = [];
    }
} catch (Throwable $e) {
    error_log('teacher/profile failed: ' . $e->getMessage());
    $error = 'بارگذاری پروفایل با مشکل مواجه شد.';
}

$pageTitle = 'پروفایل من | ' . siteName();
require_once __DIR__ . '/header.php';

$fullName = $teacher !== null
    ? trim((string) $teacher['first_name'] . ' ' . (string) $teacher['last_name'])
    : (string) ($_SESSION['teacher_name'] ?? 'مربی');
$photoUrl = ($teacher !== null && !empty($teacher['avatar'])) ? url((string) $teacher['avatar']) : '';

$methodLabels = [
    'cash'          => 'نقدی',
    'bank_transfer' => 'واریز بانکی',
    'check'         => 'چک',
];
?>

<div class="tp-page-header">
    <div>
        <h1>👤 پروفایل و فیش حقوقی</h1>
        <p class="tp-page-subtitle">اطلاعات شما همان‌طور که در مدیریت ثبت شده است؛ برای اصلاح هر مورد با مدیریت مهد هماهنگ کنید.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<?php if ($teacher !== null): ?>
    <section class="tp-card">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <?php if ($photoUrl !== ''): ?>
                <img src="<?= e($photoUrl) ?>" alt="<?= e($fullName) ?>" class="tp-user-avatar" style="width:64px;height:64px;font-size:1.4rem;">
            <?php else: ?>
                <span class="tp-user-avatar" style="width:64px;height:64px;font-size:1.4rem;"><?= e(userInitial((string) $teacher['first_name'])) ?></span>
            <?php endif; ?>
            <div>
                <h2 style="margin:0 0 4px;font-size:1.2rem;"><?= e($fullName) ?></h2>
                <p class="tp-page-subtitle" style="margin:0;"><?= e((string) ($teacher['role_title'] ?? '') !== '' ? (string) $teacher['role_title'] : 'مربی مهد') ?></p>
            </div>
        </div>

        <div class="tp-detail-grid" style="margin-top:16px;">
            <div class="tp-child-status-box">
                <span class="tp-child-status-label">ایمیل</span>
                <strong style="font-size:0.85rem;"><?= e((string) $teacher['email']) ?></strong>
            </div>
            <div class="tp-child-status-box">
                <span class="tp-child-status-label">تلفن</span>
                <strong style="font-size:0.85rem;"><?= !empty($teacher['phone']) ? e(persianNumber((string) $teacher['phone'])) : '—' ?></strong>
            </div>
            <div class="tp-child-status-box">
                <span class="tp-child-status-label">تحصیلات</span>
                <strong style="font-size:0.85rem;"><?= e(trim((string) ($teacher['education_level'] ?? '') . (((string) ($teacher['major'] ?? '')) !== '' ? ' · ' . (string) $teacher['major'] : ''))) ?: '—' ?></strong>
            </div>
            <div class="tp-child-status-box">
                <span class="tp-child-status-label">تاریخ شروع همکاری</span>
                <strong style="font-size:0.85rem;"><?= !empty($teacher['hire_date']) ? e(shamsiDate((string) $teacher['hire_date'])) : '—' ?></strong>
            </div>
            <div class="tp-child-status-box">
                <span class="tp-child-status-label">حقوق پایه ثبت‌شده</span>
                <strong style="font-size:0.85rem;"><?= ($teacher['salary'] !== null && (float) $teacher['salary'] > 0) ? e(persianNumber(number_format((float) $teacher['salary']))) . ' تومان' : '—' ?></strong>
            </div>
            <div class="tp-child-status-box">
                <span class="tp-child-status-label">کلاس‌های من</span>
                <strong style="font-size:0.85rem;">
                    <?= $classrooms === [] ? 'هنوز کلاسی اختصاص داده نشده است' : e(implode('، ', array_map(static fn (array $c): string => (string) $c['name'], $classrooms))) ?>
                </strong>
            </div>
        </div>

        <?php if (trim((string) ($teacher['bio'] ?? '')) !== ''): ?>
            <p style="line-height:2;margin:14px 0 0;"><?= e((string) $teacher['bio']) ?></p>
        <?php endif; ?>
    </section>

    <section class="tp-card">
        <div class="tp-card-header"><h2>💰 پرداخت‌های حقوق اخیر</h2></div>
        <?php if ($payments === []): ?>
            <p style="color:var(--tp-text-muted);margin:0;">هنوز پرداختی برای شما ثبت نشده است.</p>
        <?php else: ?>
            <div class="tp-msg-list">
                <?php foreach ($payments as $pay): ?>
                    <div class="tp-msg-item" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                        <div>
                            <strong><?= e(persianNumber(number_format((float) $pay['amount']))) ?> تومان</strong>
                            <div style="font-size:0.8rem;color:var(--tp-text-secondary);">
                                بابت <?= e(persianNumber((string) $pay['month_year'])) ?> ·
                                <?= e($methodLabels[(string) ($pay['payment_method'] ?? '')] ?? 'واریز بانکی') ?>
                            </div>
                        </div>
                        <span class="tp-badge tp-badge-present"><?= e(shamsiDate((string) $pay['payment_date'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
