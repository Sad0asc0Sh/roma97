<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$selectedMonthYear = (string) ($_GET['month_year'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonthYear)) {
    // Current Shamsi Month-Year
    [$currentJy, $currentJm] = gregorianToJalali(
        (int) date('Y'),
        (int) date('n'),
        (int) date('j')
    );
    $selectedMonthYear = sprintf('%04d-%02d', $currentJy, $currentJm);
}

function expenseCategoryLabel(string $category): string
{
    return match ($category) {
        'rent'        => 'اجاره',
        'utilities'   => 'قبوض',
        'food'        => 'مواد غذایی',
        'maintenance' => 'تعمیر و نگهداری',
        'supplies'    => 'ملزومات',
        'insurance'   => 'بیمه',
        'other'       => 'سایر',
        default       => $category,
    };
}

$totalTuition = 0.0;
$totalSalaries = 0.0;
$totalExpenses = 0.0;
$totalGeneralExpenses = 0.0;
$netProfitLoss = 0.0;
$overdueChildren = [];
$expensesByCategory = [];

try {
    initializeFinancialTables();
    $pdo = getDb();

    // 1. Total Tuition Received in selected month_year
    $tStmt = $pdo->prepare('SELECT SUM(amount) FROM tuition_payments WHERE month_year = :myear');
    $tStmt->execute([':myear' => $selectedMonthYear]);
    $totalTuition = (float) ($tStmt->fetchColumn() ?: 0);

    // 2. Total Salaries Paid in selected month_year
    $sStmt = $pdo->prepare('SELECT SUM(amount) FROM salary_payments WHERE month_year = :myear');
    $sStmt->execute([':myear' => $selectedMonthYear]);
    $totalSalaries = (float) ($sStmt->fetchColumn() ?: 0);

    // 3. Total General Expenses in selected month_year using Gregorian date range
    [$gregStart, $gregEnd] = jalaliMonthToGregorianRange($selectedMonthYear);

    $expStmt = $pdo->prepare('SELECT category, amount FROM expenses WHERE expense_date BETWEEN :gstart AND :gend');
    $expStmt->execute([':gstart' => $gregStart, ':gend' => $gregEnd]);
    $monthExpenses = $expStmt->fetchAll();

    foreach ($monthExpenses as $exp) {
        $amt = (float) $exp['amount'];
        $totalGeneralExpenses += $amt;
        $cat = (string) $exp['category'];
        if (!isset($expensesByCategory[$cat])) {
            $expensesByCategory[$cat] = 0.0;
        }
        $expensesByCategory[$cat] += $amt;
    }

    $totalExpenses = $totalSalaries + $totalGeneralExpenses;
    $netProfitLoss = $totalTuition - $totalExpenses;

    // 4. Overdue Children in selected month_year
    // Query active children
    $childrenStmt = $pdo->query("
        SELECT c.id, c.first_name, c.last_name, p.first_name AS p_first, p.last_name AS p_last, p.phone AS p_phone
        FROM children c
        INNER JOIN parents p ON p.id = c.parent_id
        WHERE c.status = 'active'
        ORDER BY c.last_name, c.first_name
    ");
    $activeChildren = $childrenStmt ? $childrenStmt->fetchAll() : [];

    foreach ($activeChildren as $child) {
        $cid = (int) $child['id'];
        $balance = childOutstandingBalance($pdo, $cid, $selectedMonthYear);

        if ($balance > 0) {
            $overdueChildren[] = [
                'child_id'   => $cid,
                'child_name' => trim($child['first_name'] . ' ' . $child['last_name']),
                'parent_name'=> trim($child['p_first'] . ' ' . $child['p_last']),
                'parent_phone'=> (string) ($child['p_phone'] ?? ''),
                'balance'    => $balance,
            ];
        }
    }

    // Sort overdue children by balance descending
    usort($overdueChildren, static fn($a, $b) => $b['balance'] <=> $a['balance']);

} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'بارگذاری اطلاعات گزارش مالی با مشکل مواجه شد.');
}

$pageTitle = 'گزارش مالی (سود و زیان) | مدیریت | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0 0 4px 0;">گزارش مالی (سود / زیان) 📊</h1>
            <p class="muted" style="margin:0;">بررسی تراز درآمدها، هزینه‌ها و بدهی‌های معوق مهدکودک</p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="<?= e(url('admin/export-csv.php?' . http_build_query(['type' => 'tuition', 'month_year' => $selectedMonthYear]))) ?>" class="btn btn-secondary">📥 دریافت خروجی CSV شهریه این ماه</a>
            <form method="get" action="<?= e(url('admin/reports.php')) ?>" class="inline-form" style="display: flex; gap: 10px; align-items: center;">
                <label for="month_year" style="font-weight: 600;">انتخاب ماه:</label>
                <select name="month_year" id="month_year" class="form-control" onchange="this.form.submit()" style="min-width: 180px;">
                    <?php foreach (getShamsiMonthYearChoices(24, 24) as $choice): ?>
                        <option value="<?= e($choice['value']) ?>" <?= $choice['value'] === $selectedMonthYear ? 'selected' : '' ?>>
                            <?= e($choice['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- Summary KPI Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 28px;">
        <div class="card" style="padding: 20px; border-right: 5px solid #38a169;">
            <div style="font-size: 0.9rem; color: var(--adm-text-muted); font-weight: 600; margin-bottom: 8px;">مجموع شهریه دریافتی</div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #276749;"><?= e(persianNumber(number_format($totalTuition))) ?> <span style="font-size: 0.9rem; font-weight: normal;">تومان</span></div>
            <div style="font-size: 0.8rem; color: var(--adm-text-muted); margin-top: 6px;">ورودی مالی ماه <?= e(formatShamsiMonthYear($selectedMonthYear)) ?></div>
        </div>

        <div class="card" style="padding: 20px; border-right: 5px solid #e53e3e;">
            <div style="font-size: 0.9rem; color: var(--adm-text-muted); font-weight: 600; margin-bottom: 8px;">مجموع هزینه‌ها</div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #9b2c2c;"><?= e(persianNumber(number_format($totalExpenses))) ?> <span style="font-size: 0.9rem; font-weight: normal;">تومان</span></div>
            <div style="font-size: 0.8rem; color: var(--adm-text-muted); margin-top: 6px;">حقوق معلمان (<?= e(persianNumber(number_format($totalSalaries))) ?>) + عمومی (<?= e(persianNumber(number_format($totalGeneralExpenses))) ?>)</div>
        </div>

        <div class="card" style="padding: 20px; border-right: 5px solid <?= $netProfitLoss >= 0 ? '#319795' : '#dd6b20' ?>;">
            <div style="font-size: 0.9rem; color: var(--adm-text-muted); font-weight: 600; margin-bottom: 8px;">سود / زیان خالص</div>
            <div style="font-size: 1.6rem; font-weight: 800; color: <?= $netProfitLoss >= 0 ? '#2c7a7b' : '#c05621' ?>;">
                <?= $netProfitLoss < 0 ? '-' : '' ?><?= e(persianNumber(number_format(abs($netProfitLoss)))) ?> <span style="font-size: 0.9rem; font-weight: normal;">تومان</span>
            </div>
            <div style="font-size: 0.8rem; color: var(--adm-text-muted); margin-top: 6px;"><?= $netProfitLoss >= 0 ? 'تراز مالی مثبت (سود)' : 'تراز مالی منفی (کسری/زیان)' ?></div>
        </div>
    </div>

    <div class="admin-two-column">
        <!-- Overdue Children Table -->
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">کودکان دارای شهریه معوق (ماه <?= e(formatShamsiMonthYear($selectedMonthYear)) ?>)</h2>
            </div>
            <?php if (empty($overdueChildren)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <h3>هیچ عدم پرداختی یا بدهی برای این ماه ثبت نشده است</h3>
                </div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>نام کودک</th>
                                <th>والد / تماس</th>
                                <th>مبلغ بدهی معوق</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueChildren as $item): ?>
                                <tr>
                                    <td style="font-weight:600;"><?= e($item['child_name']) ?></td>
                                    <td>
                                        <div><?= e($item['parent_name']) ?></div>
                                        <?php if (!empty($item['parent_phone'])): ?>
                                            <small class="muted"><a href="tel:<?= e($item['parent_phone']) ?>"><?= e($item['parent_phone']) ?></a></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:#e53e3e; font-weight:700;"><?= e(persianNumber(number_format((float) $item['balance']))) ?> تومان</td>
                                    <td>
                                        <a href="<?= e(url('admin/tuition.php?child_id=' . $item['child_id'] . '#recordForm')) ?>" class="btn btn-sm btn-primary">ثبت پرداخت</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Expenses Breakdown Table -->
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">تفکیک هزینه‌های عمومی این ماه</h2>
            </div>
            <?php if (empty($expensesByCategory)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    </div>
                    <h3>هیچ هزینه عمومی برای این ماه ثبت نشده است</h3>
                </div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>دسته‌بندی هزینه</th>
                                <th>مجموع هزینه</th>
                                <th>سهم از کل هزینه‌ها</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expensesByCategory as $cat => $catAmount): ?>
                                <?php $percentage = $totalGeneralExpenses > 0 ? round(($catAmount / $totalGeneralExpenses) * 100, 1) : 0; ?>
                                <tr>
                                    <td style="font-weight:600;"><?= e(expenseCategoryLabel($cat)) ?></td>
                                    <td class="amount-highlight"><?= e(persianNumber(number_format((float) $catAmount))) ?> تومان</td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div style="flex:1; background:var(--adm-bg, #edf2f7); height:8px; border-radius:4px; overflow:hidden;">
                                                <div style="background:var(--adm-primary, #3182ce); height:100%; width:<?= $percentage ?>%;"></div>
                                            </div>
                                            <span style="font-size:0.8rem; font-weight:600; min-width:40px; text-align:left;"><?= e(persianNumber((string) $percentage)) ?>٪</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
