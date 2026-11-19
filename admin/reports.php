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
        'sms'         => 'پیامک',
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
$trendRows = [];

// Receivables position for the selected month. Reported alongside cash-in so an
// owner can tell the difference between "we earned less" and "we simply have not
// collected yet" — two very different problems that the old report conflated.
$expectedTuition = 0.0;
$collectedTuition = 0.0;
$outstandingTuition = 0.0;
$collectionRate = 0.0;
$activeChildCount = 0;
$settledCount = 0;
$smsCost = 0.0;

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
        if ($cat === 'sms') {
            $smsCost += $amt;
        }
    }

    $totalExpenses = $totalSalaries + $totalGeneralExpenses;
    $netProfitLoss = $totalTuition - $totalExpenses;

    // 4. Receivables + overdue children for the selected month.
    //
    // Performance: this used to run three queries per active child (default
    // tuition, plan, payments) inside a loop — ~900 round-trips for a 300-child
    // daycare, which made the page unusable at scale. `activeChildrenTuitionBreakdown()`
    // answers the same question with a single JOINed query.
    $breakdowns = activeChildrenTuitionBreakdown($pdo, $selectedMonthYear);
    $activeChildCount = count($breakdowns);

    foreach ($breakdowns as $childId => $row) {
        $expectedTuition  += (float) $row['expected'];
        $collectedTuition += (float) $row['paid'];

        $balance = (float) $row['balance'];

        // Anything below the shared settle tolerance is rounding dust, not a
        // debt worth chasing (same threshold as the tuition page and parent portal).
        if ($balance >= tuitionSettleTolerance()) {
            $outstandingTuition += $balance;
            $overdueChildren[] = [
                'child_id'     => $childId,
                'child_name'   => (string) $row['child_name'],
                'parent_name'  => (string) $row['parent_name'],
                'parent_phone' => (string) $row['parent_phone'],
                'balance'      => $balance,
                'expected'     => (float) $row['expected'],
                'paid'         => (float) $row['paid'],
                'has_plan'     => (bool) $row['has_plan'],
            ];
        } else {
            $settledCount++;
        }
    }

    $collectionRate = $expectedTuition > 0
        ? round(($collectedTuition / $expectedTuition) * 100, 1)
        : 0.0;

    // Biggest debts first — that is the order an owner wants to make calls in.
    usort($overdueChildren, static fn($a, $b) => $b['balance'] <=> $a['balance']);

    // ── 6-month trend: cash received vs expenses, one bar per Jalali month ──
    // A single month tells an owner where they stand; the trend tells them
    // whether the daycare is getting healthier or quietly bleeding.
    $trendRows = [];
    $trendAnchor = strtotime($gregStart);
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = strtotime('-' . $i . ' months', $trendAnchor);
        [$tJy, $tJm] = gregorianToJalali((int) date('Y', $monthStart), (int) date('n', $monthStart), 15);
        $trendMonthYear = sprintf('%04d-%02d', $tJy, $tJm);
        [$tStart, $tEnd] = jalaliMonthToGregorianRange($trendMonthYear);

        $inStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM tuition_payments WHERE month_year = :myear');
        $inStmt->execute([':myear' => $trendMonthYear]);
        $in = (float) $inStmt->fetchColumn();

        $salStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM salary_payments WHERE month_year = :myear');
        $salStmt->execute([':myear' => $trendMonthYear]);
        $tSalaries = (float) $salStmt->fetchColumn();

        $gStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN :gs AND :ge');
        $gStmt->execute([':gs' => $tStart, ':ge' => $tEnd]);
        $tGeneral = (float) $gStmt->fetchColumn();

        $trendRows[] = [
            'label'    => formatShamsiMonthYear($trendMonthYear),
            'in'       => $in,
            'out'      => $tSalaries + $tGeneral,
            'net'      => $in - ($tSalaries + $tGeneral),
            'is_current' => $trendMonthYear === $selectedMonthYear,
        ];
    }

} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'بارگذاری اطلاعات گزارش مالی با مشکل مواجه شد.');
}

$pageTitle = 'گزارش مالی (سود و زیان) | مدیریت | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <h1>گزارش مالی (سود و زیان)</h1>
            <p class="adm-page-subtitle">بررسی تراز درآمدها، هزینه‌ها، نرخ وصول و بدهی‌های معوق مهدکودک</p>
        </div>
        <div class="adm-page-actions">
            <a href="<?= e(url('admin/export-csv.php?' . http_build_query(['type' => 'tuition', 'month_year' => $selectedMonthYear]))) ?>" class="btn btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span>خروجی CSV شهریه این ماه</span>
            </a>
            <form method="get" action="<?= e(url('admin/reports.php')) ?>" class="inline-form" style="display: flex; gap: 8px; align-items: center;">
                <label for="month_year" class="sr-only">انتخاب ماه</label>
                <select name="month_year" id="month_year" class="form-control" onchange="this.form.submit()" style="min-width: 170px;">
                    <?php foreach (getShamsiMonthYearChoices(24, 24) as $choice): ?>
                        <option value="<?= e($choice['value']) ?>" <?= $choice['value'] === $selectedMonthYear ? 'selected' : '' ?>>
                            <?= e($choice['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <nav class="adm-section-nav" aria-label="بخش‌های گزارش مالی">
        <a href="#report-summary" class="adm-section-nav-link is-active">خلاصه تراز</a>
        <a href="#report-collections" class="adm-section-nav-link">وضعیت وصول شهریه</a>
        <?php if ($trendRows !== []): ?>
            <a href="#report-trend" class="adm-section-nav-link">روند ۶ ماه اخیر</a>
        <?php endif; ?>
        <a href="#report-breakdown" class="adm-section-nav-link">معوقات و هزینه‌ها</a>
    </nav>

    <!-- Summary KPI Cards -->
    <div id="report-summary" class="adm-kpi-grid">
        <div class="adm-kpi-card adm-kpi-card--success">
            <div class="adm-kpi-label">مجموع شهریه دریافتی</div>
            <div class="adm-kpi-value"><?= e(persianNumber(number_format($totalTuition))) ?> <span class="adm-kpi-unit">تومان</span></div>
            <div class="adm-kpi-meta">ورودی مالی ماه <?= e(formatShamsiMonthYear($selectedMonthYear)) ?></div>
        </div>

        <div class="adm-kpi-card adm-kpi-card--danger">
            <div class="adm-kpi-label">مجموع هزینه‌ها</div>
            <div class="adm-kpi-value"><?= e(persianNumber(number_format($totalExpenses))) ?> <span class="adm-kpi-unit">تومان</span></div>
            <div class="adm-kpi-meta">حقوق معلمان (<?= e(persianNumber(number_format($totalSalaries))) ?>) + عمومی (<?= e(persianNumber(number_format($totalGeneralExpenses))) ?>)</div>
        </div>

        <div class="adm-kpi-card <?= $netProfitLoss >= 0 ? 'adm-kpi-card--success' : 'adm-kpi-card--warning' ?>">
            <div class="adm-kpi-label">سود / زیان خالص</div>
            <div class="adm-kpi-value">
                <?= $netProfitLoss < 0 ? '-' : '' ?><?= e(persianNumber(number_format(abs($netProfitLoss)))) ?> <span class="adm-kpi-unit">تومان</span>
            </div>
            <div class="adm-kpi-meta"><?= $netProfitLoss >= 0 ? 'تراز مالی مثبت (سود)' : 'تراز مالی منفی (کسری/زیان)' ?></div>
        </div>
    </div>

    <!-- Receivables: separates "earned less" from "not collected yet" -->
    <div id="report-collections" class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">وضعیت وصول شهریه ماه <?= e(formatShamsiMonthYear($selectedMonthYear)) ?></h2>
        </div>

        <div class="adm-kpi-grid">
            <div class="adm-kpi-card adm-kpi-card--primary">
                <div class="adm-kpi-label">شهریه قابل وصول</div>
                <div class="adm-kpi-value"><?= e(persianNumber(number_format($expectedTuition))) ?> <span class="adm-kpi-unit">تومان</span></div>
                <div class="adm-kpi-meta">برای <?= e(persianNumber((string) $activeChildCount)) ?> کودک فعال</div>
            </div>

            <div class="adm-kpi-card adm-kpi-card--success">
                <div class="adm-kpi-label">وصول‌شده</div>
                <div class="adm-kpi-value"><?= e(persianNumber(number_format($collectedTuition))) ?> <span class="adm-kpi-unit">تومان</span></div>
                <div class="adm-kpi-meta"><?= e(persianNumber((string) $settledCount)) ?> کودک تسویه‌شده</div>
            </div>

            <div class="adm-kpi-card adm-kpi-card--danger">
                <div class="adm-kpi-label">مانده وصول‌نشده</div>
                <div class="adm-kpi-value"><?= e(persianNumber(number_format($outstandingTuition))) ?> <span class="adm-kpi-unit">تومان</span></div>
                <div class="adm-kpi-meta"><?= e(persianNumber((string) count($overdueChildren))) ?> خانواده بدهکار</div>
            </div>

            <div class="adm-kpi-card <?= $collectionRate >= 85 ? 'adm-kpi-card--success' : ($collectionRate >= 60 ? 'adm-kpi-card--warning' : 'adm-kpi-card--danger') ?>">
                <div class="adm-kpi-label">نرخ وصول</div>
                <div class="adm-kpi-value"><?= e(persianNumber((string) $collectionRate)) ?>٪</div>
                <div class="adm-progress">
                    <div class="adm-progress-fill <?= $collectionRate >= 85 ? '' : ($collectionRate >= 60 ? 'adm-progress-fill--warning' : 'adm-progress-fill--danger') ?>" style="width:<?= max(0, min(100, (float) $collectionRate)) ?>%;"></div>
                </div>
            </div>

            <div class="adm-kpi-card adm-kpi-card--info">
                <div class="adm-kpi-label">هزینه پیامک ماه (خدمات جانبی)</div>
                <div class="adm-kpi-value"><?= e(persianNumber(number_format($smsCost))) ?> <span class="adm-kpi-unit">تومان</span></div>
                <div class="adm-kpi-meta">جزئی از هزینه‌های عمومی بالا، از جیب مهد</div>
            </div>
        </div>

        <div class="adm-callout adm-callout--info m-0">
            <div class="adm-callout-body">
                «شهریه دریافتی» در کارت‌های بالا مبلغی است که واقعاً به حساب رسیده؛ «مانده وصول‌نشده» درآمدی است که هنوز وصول نشده و در سود/زیان لحاظ نشده است.
                <?php if ($outstandingTuition > 0): ?>
                    برای ارسال یادآوری گروهی می‌توانید از <a href="<?= e(url('admin/sms-center.php')) ?>">مرکز پیامک</a> وضعیت اعلان‌ها را بررسی کنید.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 6-month cash trend: is the daycare getting healthier or bleeding? -->
    <?php if ($trendRows !== []): ?>
    <div id="report-trend" class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">روند ۶ ماه اخیر (ورودی نقدی در برابر هزینه‌ها)</h2>
        </div>
        <div class="adm-trend-grid">
            <?php
            $trendMax = 1.0;
            foreach ($trendRows as $tr) {
                $trendMax = max($trendMax, (float) $tr['in'], (float) $tr['out']);
            }
            ?>
            <?php foreach ($trendRows as $tr): ?>
                <?php
                $inPct = round(((float) $tr['in'] / $trendMax) * 100);
                $outPct = round(((float) $tr['out'] / $trendMax) * 100);
                $netPositive = (float) $tr['net'] >= 0;
                ?>
                <div class="adm-trend-col <?= $tr['is_current'] ? 'is-current' : '' ?>">
                    <div class="adm-trend-bars">
                        <div class="adm-trend-bar-in" title="دریافتی: <?= e(persianNumber(number_format((float) $tr['in']))) ?> تومان" style="height:<?= max(3, $inPct) ?>%;"></div>
                        <div class="adm-trend-bar-out" title="هزینه: <?= e(persianNumber(number_format((float) $tr['out']))) ?> تومان" style="height:<?= max(3, $outPct) ?>%;"></div>
                    </div>
                    <div class="adm-trend-net <?= $netPositive ? 'adm-trend-net--pos' : 'adm-trend-net--neg' ?>">
                        <?= $netPositive ? '+' : '−' ?><?= e(persianNumber(number_format(abs((float) $tr['net']) / 1000000, 1))) ?>م
                    </div>
                    <div class="adm-trend-label"><?= e($tr['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="adm-trend-legend">
            <span><span class="adm-legend-swatch" style="background:var(--adm-primary);"></span> دریافتی شهریه</span>
            <span><span class="adm-legend-swatch" style="background:var(--adm-danger);opacity:0.82;"></span> هزینه‌ها (حقوق + عمومی)</span>
            <span>· اعداد خالص به میلیون تومان</span>
        </div>
    </div>
    <?php endif; ?>

    <div id="report-breakdown" class="admin-two-column">
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
                                <th>وضعیت پرداخت</th>
                                <th>مبلغ بدهی معوق</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueChildren as $item): ?>
                                <?php
                                $itemExpected = (float) ($item['expected'] ?? 0);
                                $itemPaid = (float) ($item['paid'] ?? 0);
                                $itemProgress = $itemExpected > 0 ? min(100, ($itemPaid / $itemExpected) * 100) : 0;
                                ?>
                                <tr>
                                    <td class="font-semibold">
                                        <?= e($item['child_name']) ?>
                                        <?php if (empty($item['has_plan'])): ?>
                                            <div class="muted" style="font-size:0.72rem;color:var(--adm-warning);margin-top:2px;">
                                                مبلغ اختصاصی ثبت نشده (شهریه پیش‌فرض)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= e($item['parent_name']) ?></div>
                                        <?php if (!empty($item['parent_phone'])): ?>
                                            <small class="muted"><a href="tel:<?= e($item['parent_phone']) ?>" dir="ltr"><?= e($item['parent_phone']) ?></a></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width:130px;">
                                        <div class="muted" style="font-size:0.78rem;margin-bottom:4px;">
                                            <?= e(persianNumber(number_format($itemPaid))) ?> از <?= e(persianNumber(number_format($itemExpected))) ?>
                                        </div>
                                        <div class="adm-progress">
                                            <div class="adm-progress-fill" style="width:<?= (float) $itemProgress ?>%;"></div>
                                        </div>
                                    </td>
                                    <td style="color:var(--adm-danger); font-weight:700;"><?= e(persianNumber(number_format((float) $item['balance']))) ?> تومان</td>
                                    <td>
                                        <a href="<?= e(url('admin/tuition.php?child_id=' . $item['child_id'] . '&month_year=' . urlencode($selectedMonthYear) . '#recordForm')) ?>" class="btn btn-sm btn-primary">ثبت پرداخت</a>
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
                                    <td class="font-semibold"><?= e(expenseCategoryLabel($cat)) ?></td>
                                    <td class="amount-highlight"><?= e(persianNumber(number_format((float) $catAmount))) ?> تومان</td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div class="adm-progress" style="flex:1;margin-top:0;">
                                                <div class="adm-progress-fill" style="width:<?= $percentage ?>%;"></div>
                                            </div>
                                            <span class="font-semibold" style="font-size:0.8rem; min-width:40px; text-align:left;"><?= e(persianNumber((string) $percentage)) ?>٪</span>
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
