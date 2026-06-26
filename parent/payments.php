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
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$payments = [];
$totalPaid = 0.0;

try {
    initializeFinancialTables();
    $pdo = getDb();

    $stmt = $pdo->prepare("
        SELECT tp.amount, tp.payment_date, tp.payment_method, tp.month_year, tp.notes,
               c.first_name, c.last_name
        FROM tuition_payments tp
        INNER JOIN children c ON c.id = tp.child_id
        WHERE tp.parent_id = :pid
        ORDER BY tp.payment_date DESC, tp.created_at DESC
    ");
    $stmt->execute([':pid' => $parentId]);
    $payments = $stmt->fetchAll();

    foreach ($payments as $p) {
        $totalPaid += (float) $p['amount'];
    }

} catch (Throwable $e) {
    error_log($e->getMessage());
    if (!$errorMessage) {
        $errorMessage = 'خطایی در بارگذاری تاریخچه پرداخت‌های شما رخ داد.';
    }
}

$pageTitle = 'تاریخچه پرداخت‌ها';
require_once __DIR__ . '/header.php';
?>

<?php if ($successMessage !== null): ?>
    <div class="notice" role="status"><?= e($successMessage)?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert" role="alert"><?= e($errorMessage)?></div>
<?php endif; ?>

<div class="page-header">
    <h1>تاریخچه پرداخت‌ها</h1>
    <p class="page-subtitle">پرداخت‌های شهریه و سوابق مالی گذشته خود را مرور کنید</p>
</div>

<!-- Total Paid Summary -->
<div class="payment-summary-card">
    <div class="summary-icon">💰</div>
    <div class="summary-content">
        <span class="summary-label">مجموع پرداخت‌ها تا تاریخ</span>
        <span class="summary-amount"><?= e(persianNumber(number_format($totalPaid, 2))) ?> <span class="currency-unit">تومان</span></span>
   </div>
</div>

<!-- Payments List -->
<?php if ($payments === []): ?>
    <div class="parent-empty-state">
        <div class="empty-state-icon">💳</div>
        <h3>هنوز سابقه پرداختی ثبت نشده است</h3>
        <p>به محض ثبت پرداخت‌ها، تاریخچه آنها در اینجا نمایش داده می‌شود.</p>
    </div>
<?php else: ?>
    <!-- Desktop Table View -->
    <div class="payments-table-container">
        <table class="payments-table">
            <thead>
                <tr>
                    <th>کودک</th>
                    <th>ماه</th>
                    <th>مبلغ</th>
                    <th>تاریخ</th>
                    <th>روش پرداخت</th>
                    <th>یادداشت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <?php
                    $paymentMethod = (string) $p['payment_method'];
                    $paymentMethodLabel = match (strtolower($paymentMethod)) {
                        'cash' => 'نقدی',
                        'card', 'bank_card', 'card_to_card' => 'کارت به کارت',
                        'transfer', 'bank_transfer' => 'انتقال بانکی',
                        'online' => 'آنلاین',
                        'check' => 'چک',
                        default => ucfirst($paymentMethod),
                    };
                    ?>
                    <tr>
                        <td><?= e(trim($p['first_name'] . ' ' . $p['last_name']))?></td>
                        <td><?= e(persianNumber((string) $p['month_year']))?></td>
                        <td class="amount-cell"><?= e(persianNumber(number_format((float) $p['amount'], 2))) ?> <span class="currency-unit-sm">تومان</span></td>
                        <td><?= e(shamsiDate((string) $p['payment_date']))?></td>
                        <td><?= e($paymentMethodLabel) ?></td>
                        <td><?= e(trim((string) ($p['notes'] ?? '')) !== '' ? (string) $p['notes'] : '—') ?></td>
                   </tr>
                <?php endforeach; ?>
           </tbody>
       </table>
   </div>

    <!-- Mobile Card View -->
    <div class="payments-cards-mobile">
        <?php foreach ($payments as $p): ?>
            <?php
            $paymentMethod = (string) $p['payment_method'];
            $paymentMethodLabel = match (strtolower($paymentMethod)) {
                'cash' => 'نقدی',
                'card', 'bank_card', 'card_to_card' => 'کارت به کارت',
                'transfer', 'bank_transfer' => 'انتقال بانکی',
                'online' => 'آنلاین',
                'check' => 'چک',
                default => $paymentMethod,
            };
            ?>
            <div class="payment-card">
                <div class="payment-card-header">
                    <span class="payment-child"><?= e(trim($p['first_name'] . ' ' . $p['last_name']))?></span>
                    <span class="payment-amount"><?= e(persianNumber(number_format((float) $p['amount'], 2))) ?> <span class="currency-unit-sm">تومان</span></span>
               </div>
                <div class="payment-card-body">
                    <div class="payment-detail">
                        <span class="detail-label">ماه</span>
                        <span class="detail-value"><?= e(persianNumber((string) $p['month_year'])) ?></span>
                    </div>
                    <div class="payment-detail">
                        <span class="detail-label">تاریخ:</span>
                        <span class="detail-value"><?= e(shamsiDate((string) $p['payment_date']))?></span>
                   </div>
                    <div class="payment-detail">
                        <span class="detail-label">روش پرداخت</span>
                        <span class="detail-value"><?= e($paymentMethodLabel)?></span>
                   </div>
                    <?php if (trim((string) ($p['notes'] ?? '')) !== ''): ?>
                        <div class="payment-detail">
                            <span class="detail-label">یادداشت:</span>
                            <span class="detail-value"><?= e((string) $p['notes']) ?></span>
                       </div>
                    <?php endif; ?>
               </div>
           </div>
        <?php endforeach; ?>
   </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
