<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin();
require_once __DIR__ . '/../includes/portal_rules.php';
require_once __DIR__ . '/../includes/tuition_invoices.php';

$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$selectedChildId = filter_var($_GET['child_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$invoiceFilters = ['review' => 'نیازمند پیگیری', 'needs_due_date' => 'بدون سررسید', 'all' => 'همهٔ وضعیت‌های ماه'];
$filterInput = $_GET['filter'] ?? null;
$invoiceFilter = is_string($filterInput) && isset($invoiceFilters[$filterInput]) ? $filterInput : 'review';
$monthInput = $_GET['month_year'] ?? null;
$filterMonth = is_string($monthInput) ? portalMonthYear($monthInput) ?? legacyGregorianMonth($monthInput) : null;
$today = date('Y-m-d');

// Month shown in the status table (defaults to the current Shamsi month).
$viewMonthYear = $filterMonth ?? shamsiDate($today, 'Y-m');
$isLegacyPeriod = legacyGregorianMonth($viewMonthYear) !== null;
$monthChoices = getShamsiMonthYearChoices(24, 24);
if (!in_array($viewMonthYear, array_column($monthChoices, 'value'), true)) {
    $monthChoices[] = ['value' => $viewMonthYear, 'label' => formatShamsiMonthYear($viewMonthYear)];
}
$selectedInvoice = null;
$selectedBreakdown = null;
$selectedChild = null;

try {
    initializeFinancialTables();
    $pdo = getDb();
    ensureTuitionInvoiceColumns($pdo);

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            redirect(url('admin/tuition.php'));
        }

        $formAction = (string) ($_POST['form_action'] ?? 'record_payment');

        if ($formAction === 'set_plan' || $formAction === 'set_missing_due_dates') {
            $monthValue = $_POST['plan_month_year'] ?? null;
            $monthYear = is_string($monthValue) ? portalMonthYear($monthValue) ?? legacyGregorianMonth($monthValue) : null;
            $dateIncluded = array_key_exists('due_date', $_POST);
            $dateValue = $_POST['due_date'] ?? null;
            $dueDate = parseIranDate($dateValue);
            if ($monthYear === null || (($dateIncluded || $formAction === 'set_missing_due_dates') && $dueDate === null)) {
                setFlash('error', 'ماه شهریه و تاریخ سررسید معتبر را انتخاب کنید.');
                redirect(url('admin/tuition.php#invoicePlan'));
            }

            if ($formAction === 'set_missing_due_dates') {
                $update = $pdo->prepare(
                    "UPDATE tuition_plans tp INNER JOIN children c ON c.id = tp.child_id AND c.status = 'active'
                     SET tp.due_date = ? WHERE tp.month_year = ? AND tp.due_date IS NULL"
                );
                $update->execute([$dueDate, $monthYear]);
                $updatedCount = $update->rowCount();
                recordAudit('tuition_plan.set_due_dates', 'tuition_plan', null, [
                    'month_year' => $monthYear, 'due_date' => $dueDate, 'updated_count' => $updatedCount,
                ]);
                setFlash('success', 'سررسید ' . persianNumber($updatedCount) . ' صورتحساب ذخیره شد.');
                redirect(url('admin/tuition.php?filter=needs_due_date&month_year=' . $monthYear . '#invoiceQueue'));
            }

            $childId = filter_var($_POST['plan_child_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $expectedAmount = filter_var($_POST['expected_amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $noteIncluded = array_key_exists('discrepancy_note', $_POST);
            $noteInput = $_POST['discrepancy_note'] ?? '';
            $note = is_string($noteInput) ? trim($noteInput) : null;
            $editUrl = url('admin/tuition.php?' . http_build_query(['child_id' => $childId ?: null, 'month_year' => $monthYear]) . '#invoicePlan');

            if ($childId === false || $expectedAmount === false || $expectedAmount < 0 || $expectedAmount >= 1000000000
                || $note === null || mb_strlen($note, 'UTF-8') > 500) {
                setFlash('error', 'کودک، مبلغ و یادداشت مغایرت را بررسی کنید.');
                redirect($editUrl);
            }
            if (legacyGregorianMonth($monthYear) !== null && !tuitionPeriodExists($pdo, $childId, $monthYear)) {
                setFlash('error', 'برای این کودک در دورهٔ قدیمی سابقهٔ شهریه‌ای ثبت نشده است. یک ماه شمسی انتخاب کنید.');
                redirect($editUrl);
            }
            $planStmt = $pdo->prepare(
                'INSERT INTO tuition_plans (child_id, month_year, expected_amount, due_date, discrepancy_note)
                 VALUES (:cid, :myear, :exp, :due_date, :discrepancy)
                 ON DUPLICATE KEY UPDATE expected_amount = VALUES(expected_amount),
                    due_date = COALESCE(VALUES(due_date), due_date),
                    discrepancy_note = IF(:update_discrepancy, VALUES(discrepancy_note), discrepancy_note)'
            );
            $planStmt->execute([
                ':cid'   => $childId,
                ':myear' => $monthYear,
                ':exp'   => $expectedAmount,
                ':due_date' => $dueDate,
                ':discrepancy' => $note === '' ? null : $note,
                ':update_discrepancy' => (int) $noteIncluded,
            ]);

            recordAudit('tuition_plan.set', 'child', $childId, [
                'month_year'      => $monthYear,
                'expected_amount' => $expectedAmount,
                'due_date'        => $dueDate,
                'has_discrepancy' => $note !== '',
            ]);

            setFlash('success', 'صورتحساب ماهانه ذخیره شد.');
            redirect(url('admin/tuition.php?month_year=' . $monthYear . '#invoiceQueue'));
        }

        $childId       = (int) ($_POST['child_id'] ?? 0);
        $amount        = (float) ($_POST['amount'] ?? 0);
        $paymentDate   = parseIranDate($_POST['payment_date'] ?? $today);
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
        $monthValue    = $_POST['month_year'] ?? null;
        $monthYear     = is_string($monthValue) ? portalMonthYear($monthValue) ?? legacyGregorianMonth($monthValue) : null;
        $notes         = trim((string) ($_POST['notes'] ?? ''));

        $validMethods = ['cash', 'bank_transfer', 'check'];
        if ($childId <= 0 || $amount <= 0 || $amount >= 1000000000 || $paymentDate === null || $monthYear === null || !in_array($paymentMethod, $validMethods, true)) {
            setFlash('error', 'کودک، مبلغ، تاریخ شمسی پرداخت و ماه شهریه را بررسی کنید.');
            redirect(url('admin/tuition.php'));
        }
        if (legacyGregorianMonth($monthYear) !== null && !tuitionPeriodExists($pdo, $childId, $monthYear)) {
            setFlash('error', 'برای این کودک در دورهٔ قدیمی سابقهٔ شهریه‌ای ثبت نشده است. یک ماه شمسی انتخاب کنید.');
            redirect(url('admin/tuition.php#recordForm'));
        }

        // Resolve the owning parent and the names used in the receipt.
        $pStmt = $pdo->prepare(
            'SELECT c.parent_id, c.first_name AS c_first, c.last_name AS c_last,
                    p.first_name AS p_first, p.last_name AS p_last
             FROM children c
             INNER JOIN parents p ON p.id = c.parent_id
             WHERE c.id = :cid LIMIT 1'
        );
        $pStmt->execute([':cid' => $childId]);
        $childRow = $pStmt->fetch();
        $parentId = (int) ($childRow['parent_id'] ?? 0);

        if ($parentId === 0) {
            setFlash('error', 'کودک انتخاب‌شده نامعتبر است.');
            redirect(url('admin/tuition.php'));
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tuition_payments (parent_id, child_id, amount, payment_date, payment_method, month_year, notes)
             VALUES (:pid, :cid, :amount, :pdate, :pmeth, :myear, :notes)'
        );
        $stmt->execute([
            ':pid'    => $parentId,
            ':cid'    => $childId,
            ':amount' => $amount,
            ':pdate'  => $paymentDate,
            ':pmeth'  => $paymentMethod,
            ':myear'  => $monthYear,
            ':notes'  => $notes === '' ? null : $notes,
        ]);

        $tuitionId = (int) $pdo->lastInsertId();

        recordAudit('tuition.payment', 'tuition_payment', $tuitionId, [
            'child_id' => $childId,
            'amount' => $amount,
            'month' => $monthYear,
        ]);

        // ── Send the parent a receipt (portal + SMS) ─────────────────────────
        // Closes the biggest trust gap in the finance flow: money was recorded
        // in the office and the family was never told.
        $childName  = trim((string) ($childRow['c_first'] ?? '') . ' ' . (string) ($childRow['c_last'] ?? ''));
        $parentName = trim((string) ($childRow['p_first'] ?? '') . ' ' . (string) ($childRow['p_last'] ?? ''));
        $monthLabel = formatShamsiMonthYear($monthYear);
        $amountFa   = persianNumber(number_format($amount));

        $breakdown = childTuitionBreakdown($pdo, $childId, $monthYear);
        $remaining = $breakdown['balance'];

        $receiptBody = "{$parentName} عزیز؛\n\n"
            . "پرداخت شهریه «{$childName}» با موفقیت در سیستم ثبت شد. 🌸\n\n"
            . "• مبلغ ثبت‌شده: {$amountFa} تومان\n"
            . "• ماه شهریه: {$monthLabel}\n"
            . '• تاریخ پرداخت: ' . shamsiDate($paymentDate) . "\n"
            . '• روش پرداخت: ' . match ($paymentMethod) {
                'cash' => 'نقدی',
                'bank_transfer' => 'انتقال بانکی',
                'check' => 'چک',
                default => $paymentMethod,
            } . "\n";

        if ($remaining > 0) {
            $receiptBody .= '• مانده بدهی این ماه: ' . persianNumber(number_format($remaining)) . " تومان\n";
        } elseif ($remaining < 0) {
            $receiptBody .= '• اعتبار باقی‌مانده شما: ' . persianNumber(number_format(abs($remaining))) . " تومان\n";
        } else {
            $receiptBody .= "• وضعیت: شهریه این ماه به‌طور کامل تسویه شد ✅\n";
        }

        $receiptBody .= "\nاز همکاری و اعتماد شما سپاسگزاریم.\nمدیریت مهدکودک " . siteName() . ' 💖';

        $notifyResult = notifyParent($parentId, 'payment_receipt', [
            'args' => [$parentName, $childName, $amountFa, $monthLabel],
            'portal_subject' => "رسید پرداخت شهریه {$monthLabel} — {$childName}",
            'portal_body'    => $receiptBody,
            // Unique per payment row: recording two real payments sends two receipts.
            'dedup_key' => "payment_receipt:{$tuitionId}",
            'sender_id' => (int) ($_SESSION['admin_id'] ?? 0),
            'priority'  => 3,
        ]);

        smsQueueFlushInline($pdo);

        $flash = 'پرداخت شهریه با موفقیت ثبت شد و رسید برای والد ارسال گردید.';
        $summary = summarizeNotificationResults([$notifyResult]);
        if ($summary !== '') {
            $flash .= ' ' . $summary;
        }

        setFlash('success', $flash);
        redirect(url('admin/tuition.php?month_year=' . rawurlencode($monthYear)));
    }

    // Fetch children for dropdown
    $childrenStmt = $pdo->prepare("
        SELECT c.id, c.first_name, c.last_name, p.first_name AS p_first, p.last_name AS p_last
        FROM children c
        INNER JOIN parents p ON p.id = c.parent_id
        WHERE c.status = 'active' OR c.id = :selected_child
        ORDER BY c.last_name, c.first_name
    ");
    $childrenStmt->execute([':selected_child' => $selectedChildId]);
    $children = $childrenStmt->fetchAll();
    $selectedChild = array_column($children, null, 'id')[$selectedChildId] ?? null;

    $offeredMonths = array_fill_keys(array_column($monthChoices, 'value'), true);
    $storedMonths = $pdo->query('SELECT month_year FROM tuition_plans UNION SELECT month_year FROM tuition_payments ORDER BY month_year DESC');
    foreach ($storedMonths->fetchAll(PDO::FETCH_COLUMN) as $storedMonth) {
        if (!isset($offeredMonths[$storedMonth])
            && (portalMonthYear($storedMonth) === $storedMonth || legacyGregorianMonth($storedMonth) !== null)) {
            $monthChoices[] = ['value' => $storedMonth, 'label' => formatShamsiMonthYear($storedMonth)];
        }
    }

    $bills = tuitionInvoiceRows($pdo, $invoiceFilter === 'all' ? $viewMonthYear : $filterMonth);
    $billingRows = [];
    foreach ($bills as $bill) {
        if ((int) $bill['child_id'] === $selectedChildId && $bill['month_year'] === $viewMonthYear) {
            $selectedInvoice = $bill;
        }
        $bill['state'] = tuitionInvoiceState($bill, $today);
        $billingRows[] = $bill;
    }
    if ($selectedChild !== null && (!$isLegacyPeriod || $selectedInvoice !== null)) {
        $selectedBreakdown = $selectedInvoice ?? childTuitionBreakdown($pdo, $selectedChildId, $viewMonthYear);
    }

    $monthTotals = [];
    if ($invoiceFilter === 'all') {
        $actual = array_column($billingRows, null, 'child_id');
        $active = $isLegacyPeriod ? [] : activeChildrenTuitionBreakdown($pdo, $viewMonthYear);
        foreach (array_diff_key($active, $actual) as $projection) {
            $projection += ['month_year' => $viewMonthYear, 'due_date' => null, 'discrepancy_note' => null];
            $projection['state'] = tuitionInvoiceState($projection, $today);
            $billingRows[] = $projection;
        }
        $monthTotals = portalTuitionTotals($billingRows);
    } else {
        $states = $invoiceFilter === 'review' ? ['overdue', 'discrepancy'] : ['needs_due_date'];
        $billingRows = array_values(array_filter($billingRows, static fn (array $bill): bool => in_array($bill['state'], $states, true)));
    }
    $pagination = paginate(count($billingRows), currentPageNumber(), 20);
    $dashboardStatus = array_slice($billingRows, $pagination['offset'], $pagination['perPage']);

} catch (Throwable $e) {
    error_log($e->getMessage());
    if (!$errorMessage) {
        $errorMessage = 'خطایی در بارگذاری اطلاعات شهریه رخ داد.';
    }
    $children = [];
    $selectedChild = null;
    $dashboardStatus = [];
    $monthTotals = [];
    $pagination = paginate(0, 1, 20);
}

$pageTitle = 'مدیریت شهریه | مدیریت | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <h1>مدیریت شهریه و صورتحساب‌ها</h1>
            <p class="adm-page-subtitle">پیگیری بدهی‌های سررسیدگذشته، ثبت پرداخت‌ها، صدور صورتحساب ماهانه و تعیین سررسید</p>
        </div>
        <div class="adm-page-actions">
            <a href="<?= e(url('admin/export-csv.php?type=tuition')) ?>" class="btn btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span>دریافت خروجی CSV شهریه</span>
            </a>
        </div>
    </div>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/tuition-invoices.php'; ?>

    <div class="admin-two-column">
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title" id="recordForm">ثبت پرداخت</h2>
            </div>
            <form method="post" action="<?= e(url('admin/tuition.php')) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="form_action" value="record_payment">

                <div class="form-group">
                    <label for="child_id" class="form-label">کودک / والدین</label>
                    <select name="child_id" id="child_id" class="form-control" required>
                        <option value="">-- انتخاب کودک --</option>
                        <?php foreach ($children as $c): ?>
                            <option value="<?= e((string) $c['id']) ?>" <?= $selectedChildId === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
                                (والدین: <?= e(trim($c['p_first'] . ' ' . $c['p_last'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="month_year" class="form-label">ماه شهریه</label>
                    <select name="month_year" id="month_year" class="form-control" required>
                        <?php foreach ($monthChoices as $choice): ?>
                            <option value="<?= e($choice['value']) ?>" <?= $choice['value'] === $viewMonthYear ? 'selected' : '' ?>>
                                <?= e($choice['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount" class="form-label">مبلغ (تومان)</label>
                    <input type="number" step="0.01" name="amount" id="amount" class="form-control" placeholder="۰" required>
                    <?php
                    // Prefill the exact remaining balance of the selected child so
                    // the accountant can settle the month in one click instead of
                    // reading the number off the status table.
                    if ($selectedBreakdown !== null && (float) $selectedBreakdown['balance'] > 0):
                    ?>
                    <div style="margin-top:6px;font-size:0.78rem;color:var(--adm-text-muted);">
                        ماندهٔ این کودک برای ماه انتخاب‌شده
                        <strong style="color:var(--adm-danger);"><?= e(persianNumber(number_format((float) $selectedBreakdown['balance']))) ?> تومان</strong>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="payment_date" class="form-label">تاریخ شمسی پرداخت</label>
                    <input type="text" name="payment_date" id="payment_date" class="form-control shamsi-datepicker" <?= iranPickerAttributes() ?> value="<?= e(shamsiDate(date('Y-m-d'), 'numeric')) ?>" placeholder="انتخاب تاریخ" required>
                </div>

                <div class="form-group">
                    <label for="payment_method" class="form-label">روش پرداخت</label>
                    <select name="payment_method" id="payment_method" class="form-control" required>
                        <option value="cash">نقدی</option>
                        <option value="bank_transfer">انتقال بانکی</option>
                        <option value="check">چک</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes" class="form-label">یادداشت (اختیاری)</label>
                    <input type="text" name="notes" id="notes" class="form-control" placeholder="شماره رسید، دیرکرد و ...">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">ذخیره پرداخت شهریه</button>
                </div>
            </form>

        </div>
        <div class="admin-section" id="invoicePlan">
                <div class="admin-section-header">
                    <h2 class="admin-section-title">صورتحساب ماهانه</h2>
                </div>
                <form method="get" action="<?= e(url('admin/tuition.php#invoicePlan')) ?>" class="invoice-toolbar">
                    <input type="hidden" name="filter" value="<?= e($invoiceFilter) ?>">
                    <label for="plan_child_id">کودک
                        <select name="child_id" id="plan_child_id" class="form-control" required>
                            <option value="">-- انتخاب کودک --</option>
                            <?php foreach ($children as $c): ?>
                                <option value="<?= e((string) $c['id']) ?>" <?= $selectedChildId === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label for="plan_month_year">ماه شهریه
                        <select name="month_year" id="plan_month_year" class="form-control" required>
                            <?php foreach ($monthChoices as $choice): ?>
                                <option value="<?= e($choice['value']) ?>" <?= $choice['value'] === $viewMonthYear ? 'selected' : '' ?>>
                                    <?= e($choice['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-secondary">نمایش صورتحساب</button>
                </form>

                <?php if ($selectedBreakdown !== null): ?>
                <form method="post" action="<?= e(url('admin/tuition.php')) ?>" class="invoice-edit">
                    <h3><?= e(trim($selectedChild['first_name'] . ' ' . $selectedChild['last_name']) . ' · ' . formatShamsiMonthYear($viewMonthYear)) ?></h3>
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="form_action" value="set_plan">
                    <input type="hidden" name="plan_child_id" value="<?= e($selectedChildId) ?>">
                    <input type="hidden" name="plan_month_year" value="<?= e($viewMonthYear) ?>">
                    <div class="form-group">
                        <label for="expected_amount" class="form-label">مبلغ مورد انتظار (تومان)</label>
                        <input type="number" step="0.01" name="expected_amount" id="expected_amount" class="form-control" placeholder="۰" required min="0"
                               value="<?= e($selectedInvoice['expected_amount'] ?? $selectedBreakdown['expected'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="invoice_due_date" class="form-label">سررسید پرداخت (شمسی)</label>
                        <input type="text" name="due_date" id="invoice_due_date" class="form-control shamsi-datepicker" <?= iranPickerAttributes() ?> placeholder="انتخاب تاریخ" required
                               value="<?= e(empty($selectedInvoice['due_date']) ? '' : shamsiDate($selectedInvoice['due_date'], 'numeric')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="discrepancy_note" class="form-label">یادداشت مغایرت برای حسابدار</label>
                        <textarea name="discrepancy_note" id="discrepancy_note" class="form-control" maxlength="500" rows="2" aria-describedby="invoiceDiscrepancyHelp"><?= e($selectedInvoice['discrepancy_note'] ?? '') ?></textarea>
                        <small class="muted" id="invoiceDiscrepancyHelp">وجود یادداشت، یادآوری خودکار این صورتحساب را متوقف می‌کند.</small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">ذخیره صورتحساب</button>
                    </div>
                </form>
                <?php endif; ?>
                <details class="invoice-bulk">
                    <summary>تعیین سررسید گروهی</summary>
                    <p class="muted">سررسید صورتحساب‌های بدون تاریخِ کودکان فعال را برای ماه انتخاب‌شده تعیین کنید.</p>
                    <form method="post" action="<?= e(url('admin/tuition.php#invoiceQueue')) ?>" class="invoice-toolbar">
                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                        <input type="hidden" name="form_action" value="set_missing_due_dates">
                        <label for="bulk_plan_month">ماه شهریه
                            <select name="plan_month_year" id="bulk_plan_month" class="form-control">
                                <?php foreach ($monthChoices as $choice): ?>
                                    <option value="<?= e($choice['value']) ?>" <?= $choice['value'] === $viewMonthYear ? 'selected' : '' ?>><?= e($choice['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label for="bulk_due_date">سررسید (شمسی)
                            <input type="text" name="due_date" id="bulk_due_date" class="form-control shamsi-datepicker" <?= iranPickerAttributes() ?> placeholder="انتخاب تاریخ" required>
                        </label>
                        <button type="submit" class="btn btn-secondary">ثبت سررسید گروهی</button>
                    </form>
                </details>
        </div>

    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
