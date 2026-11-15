<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';

requireParentLogin();
require_once __DIR__ . '/../includes/portal_rules.php';

$parentId = (int) $_SESSION['parent_id'];
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$payments = [];
$totalPaid = 0.0;
$paymentsPagination = null;

// Current-month position per child. Parents previously only saw a list of past
// payments, with no way to answer "do I still owe anything this month?" — the
// single most common question a family has, and the reason they phoned the
// office. Showing it here removes that call entirely.
$currentMonthYear = '';
$monthBreakdowns = [];
$monthTotals = ['expected' => 0.0, 'paid' => 0.0, 'balance' => 0.0];

try {
    initializeFinancialTables();
    $pdo = getDb();

    [$jy, $jm] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
    $currentMonthYear = sprintf('%04d-%02d', $jy, $jm);

    // Active children of this parent, with their current-month tuition position.
    $kidsStmt = $pdo->prepare(
        "SELECT id, first_name, last_name
         FROM children
         WHERE parent_id = :pid AND status = 'active'
         ORDER BY first_name, last_name"
    );
    $kidsStmt->execute([':pid' => $parentId]);

    foreach ($kidsStmt->fetchAll() as $kid) {
        $breakdown = childTuitionBreakdown($pdo, (int) $kid['id'], $currentMonthYear);
        $breakdown['child_name'] = trim((string) $kid['first_name'] . ' ' . (string) $kid['last_name']);

        $monthBreakdowns[] = $breakdown;
        $monthTotals['expected'] += (float) $breakdown['expected'];
        $monthTotals['paid']     += (float) $breakdown['paid'];
        $monthTotals['balance']  += (float) $breakdown['balance'];
    }

    $monthTotals = portalTuitionTotals($monthBreakdowns);
    $summaryStmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS paid FROM tuition_payments WHERE parent_id = ?');
    $summaryStmt->execute([$parentId]);
    $summary = $summaryStmt->fetch();
    $totalPaid = (float) $summary['paid'];
    $paymentsPagination = paginate((int) $summary['total'], currentPageNumber(), 24);
    $stmt = $pdo->prepare("
        SELECT tp.amount, tp.payment_date, tp.payment_method, tp.month_year, tp.notes,
               c.first_name, c.last_name
        FROM tuition_payments tp
        INNER JOIN children c ON c.id = tp.child_id
        WHERE tp.parent_id = :pid
        ORDER BY tp.payment_date DESC, tp.created_at DESC, tp.id DESC
        LIMIT " . $paymentsPagination['perPage'] . " OFFSET " . $paymentsPagination['offset'] . "
    ");
    $stmt->execute([':pid' => $parentId]);
    $payments = $stmt->fetchAll();


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

<!-- Current-month status: the "do I owe anything?" answer -->
<?php if ($monthBreakdowns !== []): ?>
    <?php
    // Shared settle tolerance so the portal never disagrees with the office.
    $isSettled = $monthTotals['due'] < tuitionSettleTolerance();
    $hasCredit = $isSettled && $monthTotals['credit'] > tuitionSettleTolerance();
    ?>
    <div class="card" style="padding:18px;margin-bottom:20px;border-inline-start:5px solid <?= $isSettled ? '#2e8540' : '#c8860d' ?>;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <h2 style="margin:0;font-size:1.05rem;">وضعیت شهریه ماه <?= e(formatShamsiMonthYear($currentMonthYear)) ?></h2>
            <span style="font-weight:800;font-size:0.88rem;color:<?= $isSettled ? '#1e632b' : '#7a5205' ?>;">
                <?php if ($hasCredit): ?>
                    ✅ تسویه‌شده (دارای اعتبار)
                <?php elseif ($isSettled): ?>
                    ✅ تسویه‌شده
                <?php else: ?>
                    ⏳ در انتظار پرداخت
                <?php endif; ?>
            </span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px;">
            <div>
                <div style="font-size:0.78rem;color:var(--muted);font-weight:600;">مبلغ این ماه</div>
                <div style="font-size:1.1rem;font-weight:800;"><?= e(persianNumber(number_format($monthTotals['expected']))) ?> <span style="font-size:0.72rem;font-weight:normal;">تومان</span></div>
            </div>
            <div>
                <div style="font-size:0.78rem;color:var(--muted);font-weight:600;">پرداخت‌شده</div>
                <div style="font-size:1.1rem;font-weight:800;color:#1e632b;"><?= e(persianNumber(number_format($monthTotals['paid']))) ?> <span style="font-size:0.72rem;font-weight:normal;">تومان</span></div>
            </div>
            <div>
                <div style="font-size:0.78rem;color:var(--muted);font-weight:600;">
                    <?= $hasCredit ? 'اعتبار شما' : 'مانده' ?>
                </div>
                <div style="font-size:1.1rem;font-weight:800;color:<?= $isSettled ? '#1e632b' : '#c62828' ?>;">
                    <?= e(persianNumber(number_format($hasCredit ? $monthTotals['credit'] : $monthTotals['due']))) ?>
                    <span style="font-size:0.72rem;font-weight:normal;">تومان</span>
                </div>
            </div>
        </div>

        <?php if (count($monthBreakdowns) > 1): ?>
            <div style="border-top:1px dashed var(--border, #ddd);padding-top:12px;">
                <?php foreach ($monthBreakdowns as $item): ?>
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;font-size:0.85rem;padding:5px 0;flex-wrap:wrap;">
                        <div>
                            <strong><?= e((string) $item['child_name']) ?></strong>
                        </div>
                        <div style="text-align:left;">
                            <span style="color:#1e632b;"><?= e(persianNumber(number_format((float) $item['paid']))) ?></span>
                            <span style="color:var(--muted);">از</span>
                            <span><?= e(persianNumber(number_format((float) $item['expected']))) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Total Paid Summary -->
<div class="payment-summary-card">
    <div class="summary-icon"><svg width="24" height="24" aria-hidden="true"><use href="<?= e(asset('assets/img/icons.svg#icon-wallet')) ?>"/></svg></div>
    <div class="summary-content">
        <span class="summary-label">مجموع پرداخت‌ها تا تاریخ</span>
        <span class="summary-amount"><?= e(persianNumber(number_format($totalPaid, 0))) ?> <span class="currency-unit">تومان</span></span>
   </div>
</div>

<!-- Payments List -->
<?php if ($payments === []): ?>
    <div class="parent-empty-state">
        <div class="empty-state-icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
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
                    <th>وضعیت</th>
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
                        'bank_transfer' => 'انتقال بانکی',
                        'check' => 'چک',
                        default => $paymentMethod,
                    };
                    ?>
                    <tr>
                        <td><?= e(trim($p['first_name'] . ' ' . $p['last_name']))?></td>
                        <td><?= e(formatShamsiMonthYear((string) $p['month_year']))?></td>
                        <td class="amount-cell"><?= e(persianNumber(number_format((float) $p['amount'], 0))) ?> <span class="currency-unit-sm">تومان</span></td>
                        <td><span class="badge badge-success">پرداخت شده</span></td>
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
                'bank_transfer' => 'انتقال بانکی',
                'check' => 'چک',
                default => $paymentMethod,
            };
            ?>
            <div class="payment-card">
                <div class="payment-card-header">
                    <span class="payment-child"><?= e(trim($p['first_name'] . ' ' . $p['last_name']))?></span>
                    <span class="payment-amount"><?= e(persianNumber(number_format((float) $p['amount'], 0))) ?> <span class="currency-unit-sm">تومان</span></span>
               </div>
                <div class="payment-card-body">
                    <div class="payment-detail">
                        <span class="detail-label">ماه</span>
                        <span class="detail-value"><?= e(formatShamsiMonthYear((string) $p['month_year'])) ?></span>
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

<?php if ($paymentsPagination !== null && $paymentsPagination['total'] > $paymentsPagination['perPage']): ?>
    <p class="pagination-summary">
        نمایش <?= e(persianNumber($paymentsPagination['from'])) ?> تا <?= e(persianNumber($paymentsPagination['to'])) ?> از <?= e(persianNumber($paymentsPagination['total'])) ?> پرداخت
    </p>
    <?= renderPagination($paymentsPagination, url('parent/payments.php')) ?>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
