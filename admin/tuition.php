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

$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$selectedChildId = max(0, (int) ($_GET['child_id'] ?? 0));

// Month shown in the status table (defaults to the current Shamsi month).
$viewMonthYear = (string) ($_GET['month_year'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $viewMonthYear)) {
    [$vJy, $vJm] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
    $viewMonthYear = sprintf('%04d-%02d', $vJy, $vJm);
}

try {
    initializeFinancialTables();
    $pdo = getDb();

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            redirect(url('admin/tuition.php'));
        }

        $formAction = (string) ($_POST['form_action'] ?? 'record_payment');

        if ($formAction === 'set_plan') {
            $childId        = (int) ($_POST['plan_child_id'] ?? 0);
            $monthYear      = (string) ($_POST['plan_month_year'] ?? date('Y-m'));
            $expectedAmount = (float) ($_POST['expected_amount'] ?? 0);

            if ($childId === 0 || $expectedAmount < 0 || $expectedAmount >= 1000000000 || !preg_match('/^\d{4}-\d{2}$/', $monthYear)) {
                setFlash('error', 'لطفاً مقادیر معتبر برای تعیین شهریه مورد انتظار وارد کنید.');
                redirect(url('admin/tuition.php'));
            }

            $planStmt = $pdo->prepare(
                'INSERT INTO tuition_plans (child_id, month_year, expected_amount)
                 VALUES (:cid, :myear, :exp)
                 ON DUPLICATE KEY UPDATE expected_amount = :exp'
            );
            $planStmt->execute([
                ':cid'   => $childId,
                ':myear' => $monthYear,
                ':exp'   => $expectedAmount,
            ]);

            recordAudit('tuition_plan.set', 'child', $childId, [
                'month_year'      => $monthYear,
                'expected_amount' => $expectedAmount,
            ]);

            setFlash('success', 'مبلغ شهریه مورد انتظار با موفقیت ذخیره شد.');
            redirect(url('admin/tuition.php'));
        }

        $childId       = (int) ($_POST['child_id'] ?? 0);
        $amount        = (float) ($_POST['amount'] ?? 0);
        $paymentDateRaw = (string) ($_POST['payment_date'] ?? date('Y-m-d'));
        $paymentDate   = parseJalaliDate($paymentDateRaw) ?? $paymentDateRaw;
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
        $monthYear     = (string) ($_POST['month_year'] ?? date('Y-m'));
        $notes         = trim((string) ($_POST['notes'] ?? ''));

        $dateTimeCheck = DateTime::createFromFormat('Y-m-d', $paymentDate);
        $isValidDate = $dateTimeCheck && $dateTimeCheck->format('Y-m-d') === $paymentDate;

        $validMethods = ['cash', 'bank_transfer', 'check'];
        if ($childId === 0 || $amount <= 0 || $amount >= 1000000000 || !$isValidDate || !preg_match('/^\d{4}-\d{2}$/', $monthYear) || !in_array($paymentMethod, $validMethods, true)) {
            setFlash('error', 'لطفاً همه فیلدهای الزامی را به‌درستی پر کنید.');
            redirect(url('admin/tuition.php'));
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
    $childrenStmt = $pdo->query("
        SELECT c.id, c.first_name, c.last_name, p.first_name AS p_first, p.last_name AS p_last
        FROM children c
        INNER JOIN parents p ON p.id = c.parent_id
        WHERE c.status = 'active'
        ORDER BY c.last_name, c.first_name
    ");
    $children = $childrenStmt->fetchAll();

    // Whole-month position for every active child, computed in ONE query
    // (previously 3 queries per child inside a render loop).
    $breakdowns = activeChildrenTuitionBreakdown($pdo, $viewMonthYear);

    // Last payment date per child for the selected month, fetched in bulk.
    $lastPayments = [];
    $lastPayStmt = $pdo->prepare(
        'SELECT child_id, MAX(payment_date) AS latest_date
         FROM tuition_payments WHERE month_year = :myear GROUP BY child_id'
    );
    $lastPayStmt->execute([':myear' => $viewMonthYear]);
    foreach ($lastPayStmt->fetchAll() as $lp) {
        $lastPayments[(int) $lp['child_id']] = (string) $lp['latest_date'];
    }

    // Month-level KPIs so the admin sees the collection position at a glance.
    $monthTotals = ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'settled' => 0, 'debtors' => 0];
    foreach ($breakdowns as $b) {
        $monthTotals['expected'] += $b['expected'];
        $monthTotals['paid']     += $b['paid'];

        if ($b['balance'] > 0) {
            $monthTotals['outstanding'] += $b['balance'];
            $monthTotals['debtors']++;
        } else {
            $monthTotals['settled']++;
        }
    }

    $pagination = paginate(count($breakdowns), currentPageNumber(), 20);
    $dashboardStatus = array_slice(array_values($breakdowns), $pagination['offset'], $pagination['perPage']);

} catch (Throwable $e) {
    error_log($e->getMessage());
    if (!$errorMessage) {
        $errorMessage = 'خطایی در بارگذاری اطلاعات شهریه رخ داد.';
    }
    $children = [];
    $dashboardStatus = [];
    $lastPayments = [];
    $monthTotals = ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'settled' => 0, 'debtors' => 0];
    $pagination = paginate(0, 1, 20);
}

$pageTitle = 'مدیریت شهریه | مدیریت | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
        <h1 style="margin:0;">مدیریت شهریه</h1>
        <a href="<?= e(url('admin/export-csv.php?type=tuition')) ?>" class="btn btn-secondary">📥 دریافت خروجی CSV شهریه</a>
    </div>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

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
                        <?php foreach (getShamsiMonthYearChoices(24, 24) as $choice): ?>
                            <option value="<?= e($choice['value']) ?>" <?= $choice['is_current'] ? 'selected' : '' ?>>
                                <?= e($choice['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount" class="form-label">مبلغ (تومان)</label>
                    <input type="number" step="0.01" name="amount" id="amount" class="form-control" placeholder="۰" required>
                </div>

                <div class="form-group">
                    <label for="payment_date" class="form-label">تاریخ پرداخت</label>
                    <input type="text" name="payment_date" id="payment_date" class="form-control shamsi-datepicker" value="<?= e(shamsiDate(date('Y-m-d'), 'numeric')) ?>" placeholder="۱۴۰۵/۰۵/۱۸" required>
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

            <!-- Dedicated Plan Setting Form -->
            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--adm-border-light, #e2e8f0);">
                <div class="admin-section-header">
                    <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">تعیین مبلغ مورد انتظار این کودک برای این ماه</h3>
                </div>
                <form method="post" action="<?= e(url('admin/tuition.php')) ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="form_action" value="set_plan">

                    <div class="form-group">
                        <label for="plan_child_id" class="form-label">کودک</label>
                        <select name="plan_child_id" id="plan_child_id" class="form-control" required>
                            <option value="">-- انتخاب کودک --</option>
                            <?php foreach ($children as $c): ?>
                                <option value="<?= e((string) $c['id']) ?>" <?= $selectedChildId === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="plan_month_year" class="form-label">ماه شهریه</label>
                        <select name="plan_month_year" id="plan_month_year" class="form-control" required>
                            <?php foreach (getShamsiMonthYearChoices(24, 24) as $choice): ?>
                                <option value="<?= e($choice['value']) ?>" <?= $choice['is_current'] ? 'selected' : '' ?>>
                                    <?= e($choice['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="expected_amount" class="form-label">مبلغ مورد انتظار (تومان)</label>
                        <input type="number" step="0.01" name="expected_amount" id="expected_amount" class="form-control" placeholder="۰" required min="0">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary">ذخیره مبلغ مورد انتظار</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="admin-section">
            <div class="admin-section-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <h2 class="admin-section-title" style="margin:0;">وضعیت شهریه کودکان فعال</h2>
                <form method="get" action="<?= e(url('admin/tuition.php')) ?>" style="display:flex;gap:8px;align-items:center;">
                    <label for="view_month_year" style="font-size:0.85rem;font-weight:600;">ماه:</label>
                    <select name="month_year" id="view_month_year" class="form-control" onchange="this.form.submit()" style="min-width:150px;">
                        <?php foreach (getShamsiMonthYearChoices(24, 12) as $choice): ?>
                            <option value="<?= e($choice['value']) ?>" <?= $choice['value'] === $viewMonthYear ? 'selected' : '' ?>>
                                <?= e($choice['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <!-- Month collection KPIs -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px;">
                <div class="card" style="padding:12px 14px;">
                    <div style="font-size:0.78rem;color:var(--adm-text-muted);font-weight:600;">مبلغ مورد انتظار ماه</div>
                    <div style="font-size:1.1rem;font-weight:800;"><?= e(persianNumber(number_format($monthTotals['expected']))) ?> <span style="font-size:0.75rem;font-weight:normal;">تومان</span></div>
                </div>
                <div class="card" style="padding:12px 14px;border-inline-start:4px solid var(--app-success, #2E7D5B);">
                    <div style="font-size:0.78rem;color:var(--adm-text-muted);font-weight:600;">دریافت‌شده</div>
                    <div style="font-size:1.1rem;font-weight:800;color:var(--adm-success);"><?= e(persianNumber(number_format($monthTotals['paid']))) ?> <span style="font-size:0.75rem;font-weight:normal;">تومان</span></div>
                </div>
                <div class="card" style="padding:12px 14px;border-inline-start:4px solid var(--adm-danger);">
                    <div style="font-size:0.78rem;color:var(--adm-text-muted);font-weight:600;">مانده معوق</div>
                    <div style="font-size:1.1rem;font-weight:800;color:var(--adm-danger);"><?= e(persianNumber(number_format($monthTotals['outstanding']))) ?> <span style="font-size:0.75rem;font-weight:normal;">تومان</span></div>
                </div>
                <div class="card" style="padding:12px 14px;">
                    <div style="font-size:0.78rem;color:var(--adm-text-muted);font-weight:600;">تسویه‌شده / بدهکار</div>
                    <div style="font-size:1.1rem;font-weight:800;">
                        <span style="color:var(--adm-success);"><?= e(persianNumber((string) $monthTotals['settled'])) ?></span>
                        <span style="color:var(--adm-text-muted);font-weight:normal;">/</span>
                        <span style="color:var(--adm-danger);"><?= e(persianNumber((string) $monthTotals['debtors'])) ?></span>
                    </div>
                </div>
            </div>

            <?php if (empty($dashboardStatus)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg>
                    </div>
                    <h3>هیچ ثبت نام فعالی یافت نشد</h3>
                </div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>نام کودک</th>
                                <th>والدین</th>
                                <th>مبلغ مورد انتظار</th>
                                <th>پرداخت‌شده</th>
                                <th>مانده بدهی / تسویه</th>
                                <th>آخرین پرداخت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboardStatus as $s): ?>
                                <?php
                                $sCid = (int) $s['child_id'];
                                $balance = (float) $s['balance'];
                                $latestDate = $lastPayments[$sCid] ?? null;
                                ?>
                                <tr>
                                    <td style="font-weight:600;"><?= e($s['child_name']) ?></td>
                                    <td><?= e($s['parent_name']) ?></td>
                                    <td>
                                        <strong><?= e(persianNumber(number_format((float) $s['expected']))) ?></strong> تومان
                                        <?php if ((float) $s['sms_fee'] > 0): ?>
                                            <br>
                                            <small class="muted" style="font-size:0.75rem;">
                                                شهریه <?= e(persianNumber(number_format((float) $s['base']))) ?>
                                                + بسته پیامک <?= e(persianNumber(number_format((float) $s['sms_fee']))) ?>
                                            </small>
                                        <?php elseif (!$s['has_plan']): ?>
                                            <br><small class="muted" style="font-size:0.75rem;">بر پایه شهریه پیش‌فرض</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((float) $s['paid'] > 0): ?>
                                            <strong><?= e(persianNumber(number_format((float) $s['paid']))) ?></strong> تومان
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($balance > 0): ?>
                                            <span class="badge badge-danger" style="font-weight:700;">بدهکار: <?= e(persianNumber(number_format($balance))) ?> تومان</span>
                                        <?php elseif ($balance < 0): ?>
                                            <span class="badge badge-info" style="font-weight:700;">بستانکار: <?= e(persianNumber(number_format(abs($balance)))) ?> تومان</span>
                                        <?php else: ?>
                                            <span class="badge badge-success" style="font-weight:700;">تسویه کامل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $latestDate !== null ? e(shamsiDate($latestDate)) : '<span class="muted">—</span>' ?></td>
                                    <td>
                                        <a href="<?= e(url('admin/tuition.php?child_id=' . $sCid . '&month_year=' . rawurlencode($viewMonthYear) . '#recordForm')) ?>" class="btn btn-sm btn-secondary">پرداخت</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pagination['total'] > $pagination['perPage']): ?>
                    <p class="pagination-summary">
                        نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> کودک
                    </p>
                    <?= renderPagination($pagination, url('admin/tuition.php'), ['month_year' => $viewMonthYear]) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
