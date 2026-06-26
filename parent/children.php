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
$children = [];
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

try {
    initializeParentTables();
    $pdo = getDb();
    $statement = $pdo->prepare(
        'SELECT id, first_name, last_name, preferred_name, date_of_birth, gender, allergies, photo, status
         FROM children
         WHERE parent_id = :parent_id
         ORDER BY created_at DESC'
    );
    $statement->execute([':parent_id' => $parentId]);
    $children = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $errorMessage = 'اطلاعات کودکان موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.';
}

$pageTitle = 'فرزندان من';
require_once __DIR__ . '/header.php';
?>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>فرزندان من 👨‍👩‍👧‍👦</h1>
    <p class="page-subtitle">تمام کودکان ثبت‌شده در حساب شما</p>
</div>

<div class="page-actions">
    <a class="btn btn-primary" href="<?= e(url('parent/add-child.php')) ?>">+ افزودن کودک</a>
</div>

<?php if ($children === []): ?>
    <div class="parent-empty-state">
        <div class="empty-state-icon">👶</div>
        <h3>هنوز کودکی ثبت نشده است</h3>
        <p>برای شروع، اولین فرزند خود را ثبت کنید</p>
        <a class="btn btn-primary" href="<?= e(url('parent/add-child.php')) ?>">افزودن کودک</a>
  </div>
<?php else: ?>
    <div class="children-list-grid">
        <?php foreach ($children as $child): ?>
            <?php
            $fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
            $preferredName = trim((string) ($child['preferred_name'] ?? ''));
            $initial = strtoupper(substr((string) ($child['first_name'] ?? ''), 0, 1));
            $hasAllergies = trim((string) ($child['allergies'] ?? '')) !== '';
            $cs = (string) ($child['status'] ?? 'pending');
            $enrollClass = parentChildEnrollmentClass($cs);
            ?>
            <div class="child-list-card">
                <div class="child-list-photo">
                    <?php if (!empty($child['photo'])): ?>
                        <img src="<?= e(url($child['photo'])) ?>" alt="<?= e($fullName) ?>">
                    <?php else: ?>
                        <div class="child-photo-placeholder"><?= e($initial)?></div>
                    <?php endif; ?>
              </div>
                <div class="child-list-info">
                    <h3><?= e($fullName) ?></h3>
                    <?php if ($preferredName !== '' && $preferredName !== $child['first_name']): ?>
                        <p class="child-nickname">«<?= e($preferredName) ?>»</p>
                    <?php endif; ?>
                    <p class="child-age"><?= e(parentChildDisplayAge($child['date_of_birth'])) ?> • <?= e(parentChildGenderLabel(($child['gender'] ?? '') !== '' ? (string) $child['gender'] : null)) ?></p>
                    <span class="status-badge status-badge-<?= e($cs) ?>"><?= e(parentChildEnrollmentLabel($cs))?></span>
                    <?php if ($hasAllergies): ?>
                        <p class="child-alert-text">⚠️ حساسیت دارد</p>
                    <?php endif; ?>
              </div>
                <div class="child-list-actions">
                    <a class="btn btn-primary" href="<?= e(url('parent/child-detail.php?id=' . (int) $child['id'])) ?>">مشاهده جزئیات</a>
              </div>
          </div>
        <?php endforeach; ?>
  </div>

    <p class="parent-back-link margin-top-xl">
        <a href="<?= e(url('parent/index.php')) ?>">← بازگشت به داشبورد</a>
  </p>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
