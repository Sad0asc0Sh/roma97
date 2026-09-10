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
$vipSmsRevenue = 0.0;

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
        $vipSmsRevenue    += (float) $row['sms_fee'];

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
        <div class="card" style="padding: 20px; border-inline-start: 5px solid var(--app-success, #2E7D5B);">
            <div style="font-size: 0.9rem; color: var(--adm-text-muted); font-weight: 600; margin-bottom: 8px;">مجموع شهریه دریافتی</div>
            <div style="font-size: 1.6rem; font-weight: 800; color: var(--adm-success);"><?= e(persianNumber(number_format($totalTuition))) ?> <span style="font-size: 0.9rem; font-weight: normal;">تومان</span></div>
            <div style="font-size: 0.8rem; color: var(--adm-text-muted); margin-top: 6px;">ورودی مالی ماه <?= e(formatShamsiMonthYear($selectedMonthYear)) ?></div>
        </div>

        <div class="card" style="padding: 20px; border-inline-start: 5px solid var(--adm-danger);">
            <div style="font-size: 0.9rem; color: var(--adm-text-muted); font-weight: 600; margin-bottom: 8px;">مجموع هزینه‌ها</div>
            <div style="font-size: 1.6rem; font-weight: 800; color: var(--adm-danger);"><?= e(persianNumber(number_format($totalExpenses))) ?> <span style="font-size: 0.9rem; font-weight: normal;">تومان</span></div>
            <div style="font-size: 0.8rem; color: var(--adm-text-muted); margin-top: 6px;">حقوق معلمان (<?= e(persianNumber(number_format($totalSalaries))) ?>) + عمومی (<?= e(persianNumber(number_format($totalGeneralExpenses))) ?>)</div>
        </div>

        <div class="card" style="padding: 20px; border-inline-start: 5px solid <?= $netProfitLoss >= 0 ? 'var(--app-success, #2E7D5B)' : 'var(--adm-warning, #B8791A)' ?>;">
            <div style="font-size: 0.9rem; color: var(--adm-text-muted); font-weight: 600; margin-bottom: 8px;">سود / زیان خالص</div>
            <div style="font-size: 1.6rem; font-weight: 800; color: <?= $netProfitLoss >= 0 ? 'var(--adm-success)' : 'var(--adm-warning)' ?>;">
                <?= $netProfitLoss < 0 ? '-' : '' ?><?= e(persianNumber(number_format(abs($netProfitLoss)))) ?> <span style="font-size: 0.9rem; font-weight: normal;">تومان</span>
            </div>
            <div style="font-size: 0.8rem; color: var(--adm-text-muted); margin-top: 6px;"><?= $netProfitLoss >= 0 ? 'تراز مالی مثبت (سود)' : 'تراز مالی منفی (کسری/زیان)' ?></div>
        </div>
    </div>

    <!-- Receivables: separates "earned less" from "not collected yet" -->
    <div class="admin-section" style="margin-bottom:28px;">
        <div class="admin-section-header">
            <h2 class="admin-section-title" style="margin:0;">وضعیت وصول شهریه ماه <?= e(formatShamsiMonthYear($selectedMonthYear)) ?></h2>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:16px;">
            <div class="card" style="padding:14px 16px;">
                <div style="font-size:0.8rem;color:var(--adm-text-muted);font-weight:600;">شهریه قابل وصول</div>
                <div style="font-size:1.15rem;font-weight:800;"><?= e(persianNumber(number_format($expectedTuition))) ?> <span style="font-size:0.75rem;font-weight:normal;">تومان</span></div>
                <div style="font-size:0.75rem;color:var(--adm-text-muted);margin-top:4px;">
                    برای <?= e(persianNumber((string) $activeChildCount)) ?> کودک فعال
                </div>
            </div>

            <div class="card" style="padding:14px 16px;border-inline-start:4px solid var(--app-success, #2E7D5B);">
                <div style="font-size:0.8rem;color:var(--adm-text-muted);font-weight:600;">وصول‌شده</div>
                <div style="font-size:1.15rem;font-weight:800;color:var(--adm-success);"><?= e(persianNumber(number_format($collectedTuition))) ?> <span style="font-size:0.75rem;font-weight:normal;">تومان</span></div>
                <div style="font-size:0.75rem;color:var(--adm-text-muted);margin-top:4px;">
                    <?= e(persianNumber((string) $settledCount)) ?> کودک تسویه‌شده
                </div>
            </div>

            <div class="card" style="padding:14px 16px;border-inline-start:4px solid var(--adm-danger);">
                <div style="font-size:0.8rem;color:var(--adm-text-muted);font-weight:600;">مانده وصول‌نشده</div>
                <div style="font-size:1.15rem;font-weight:800;color:var(--adm-danger);"><?= e(persianNumber(number_format($outstandingTuition))) ?> <span style="font-size:0.75rem;font-weight:normal;">تومان</span></div>
                <div style="font-size:0.75rem;color:var(--adm-text-muted);margin-top:4px;">
                    <?= e(persianNumber((string) count($overdueChildren))) ?> خانواده بدهکار
                </div>
            </div>

            <div class="card" style="padding:14px 16px;">
                <div style="font-size:0.8rem;color:var(--adm-text-muted);font-weight:600;">نرخ وصول</div>
                <div style="font-size:1.15rem;font-weight:800;color:<?= $collectionRate >= 85 ? 'var(--adm-success)' : ($collectionRate >= 60 ? 'var(--adm-warning, #B8791A)' : 'var(--adm-danger)') ?>;">
                    <?= e(persianNumber((string) $collectionRate)) ?>٪
                </div>
                <div style="margin-top:8px;background:var(--adm-bg, #edf2f7);height:7px;border-radius:4px;overflow:hidden;">
                    <div style="height:100%;width:<?= max(0, min(100, (float) $collectionRate)) ?>%;background:<?= $collectionRate >= 85 ? 'var(--adm-primary, #3D8B63)' : ($collectionRate >= 60 ? '#B8791A' : '#c62828') ?>;"></div>
                </div>
            </div>

            <?php if ($vipSmsRevenue > 0): ?>
                <div class="card" style="padding:14px 16px;">
                    <div style="font-size:0.8rem;color:var(--adm-text-muted);font-weight:600;">درآمد بسته پیامک تفصیلی</div>
                    <div style="font-size:1.15rem;font-weight:800;"><?= e(persianNumber(number_format($vipSmsRevenue))) ?> <span style="font-size:0.75rem;font-weight:normal;">تومان</span></div>
                    <div style="font-size:0.75rem;color:var(--adm-text-muted);margin-top:4px;">بخشی از شهریه قابل وصول بالا</div>
                </div>
            <?php endif; ?>
        </div>

        <p style="font-size:0.82rem;color:var(--adm-text-muted);margin:0;line-height:1.8;">
            💡 «شهریه دریافتی» در کارت‌های بالا مبلغی است که واقعاً به حساب رسیده؛ «مانده وصول‌نشده» درآمدی است که هنوز وصول نشده و در سود/زیان لحاظ نشده است.
            <?php if ($outstandingTuition > 0): ?>
                برای ارسال یادآوری گروهی می‌توانید از
                <a href="<?= e(url('admin/sms-center.php')) ?>">مرکز پیامک</a>
                وضعیت اعلان‌ها را بررسی کنید.
            <?php endif; ?>
        </p>
    </div>

    <!-- 6-month cash trend: is the daycare getting healthier or bleeding? -->
    <?php if ($trendRows !== []): ?>
    <div class="admin-section" style="margin-bottom:28px;">
        <div class="admin-section-header">
            <h2 class="admin-section-title" style="margin:0;">روند ۶ ماه اخیر (ورودی نقدی در برابر هزینه‌ها)</h2>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:10px;align-items:end;margin-top:14px;">
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
                <div style="text-align:center;<?= $tr['is_current'] ? 'background:var(--adm-bg,#f7fafc);border-radius:10px;padding:6px 4px;' : 'padding:6px 4px;' ?>">
                    <div style="display:flex;justify-content:center;align-items:flex-end;gap:3px;height:110px;margin-bottom:6px;">
                        <div title="دریافتی: <?= e(persianNumber(number_format((float) $tr['in']))) ?> تومان"
                            style="width:14px;height:<?= max(2, $inPct) ?>%;background:var(--adm-primary,#3D8B63);border-radius:3px 3px 0 0;"></div>
                        <div title="هزینه: <?= e(persianNumber(number_format((float) $tr['out']))) ?> تومان"
                            style="width:14px;height:<?= max(2, $outPct) ?>%;background:#c62828;border-radius:3px 3px 0 0;opacity:0.85;"></div>
                    </div>
                    <div style="font-size:0.7rem;font-weight:700;color:<?= $netPositive ? 'var(--adm-success,#2E7D5B)' : 'var(--adm-danger,#c62828)' ?>;">
                        <?= $netPositive ? '+' : '−' ?><?= e(persianNumber(number_format(abs((float) $tr['net']) / 1000000, 1))) ?>م
                    </div>
                    <div style="font-size:0.68rem;color:var(--adm-text-muted);white-space:nowrap;"><?= e($tr['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <p style="font-size:0.75rem;color:var(--adm-text-muted);margin:10px 0 0;">
            <span style="display:inline-block;width:10px;height:10px;background:var(--adm-primary,#3D8B63);border-radius:2px;"></span> دریافتی شهریه
            &nbsp;&nbsp;<span style="display:inline-block;width:10px;height:10px;background:#c62828;border-radius:2px;opacity:0.85;"></span> هزینه‌ها (حقوق + عمومی)
            · اعداد خالص به میلیون تومان
        </p>
    </div>
    <?php endif; ?>

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
                                    <td style="font-weight:600;">
                                        <?= e($item['child_name']) ?>
                                        <?php if (empty($item['has_plan'])): ?>
                                            <div style="font-size:0.7rem;color:var(--adm-warning, #B8791A);font-weight:600;margin-top:2px;">
                                                ⚠️ مبلغ اختصاصی ثبت نشده (شهریه پیش‌فرض)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= e($item['parent_name']) ?></div>
                                        <?php if (!empty($item['parent_phone'])): ?>
                                            <small class="muted"><a href="tel:<?= e($item['parent_phone']) ?>"><?= e($item['parent_phone']) ?></a></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width:130px;">
                                        <div style="font-size:0.78rem;color:var(--adm-text-muted);margin-bottom:4px;">
                                            <?= e(persianNumber(number_format($itemPaid))) ?> از <?= e(persianNumber(number_format($itemExpected))) ?>
                                        </div>
                                        <div style="background:var(--adm-bg, #edf2f7);height:6px;border-radius:3px;overflow:hidden;">
                                            <div style="height:100%;width:<?= (float) $itemProgress ?>%;background:var(--adm-primary, #3D8B63);"></div>
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
                                    <td style="font-weight:600;"><?= e(expenseCategoryLabel($cat)) ?></td>
                                    <td class="amount-highlight"><?= e(persianNumber(number_format((float) $catAmount))) ?> تومان</td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div style="flex:1; background:var(--adm-bg, #edf2f7); height:8px; border-radius:4px; overflow:hidden;">
                                                <div style="background:var(--adm-primary, #3D8B63); height:100%; width:<?= $percentage ?>%;"></div>
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
