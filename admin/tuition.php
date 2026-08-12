<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$selectedChildId = max(0, (int) ($_GET['child_id'] ?? 0));

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

        // Get parent ID from child
        $pStmt = $pdo->prepare('SELECT parent_id FROM children WHERE id = :cid LIMIT 1');
        $pStmt->execute([':cid' => $childId]);
        $parentId = (int) $pStmt->fetchColumn();

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
        setFlash('success', 'پرداخت شهریه با موفقیت ثبت شد.');
        redirect(url('admin/tuition.php'));
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

    // Fetch all active children with their latest payment
    $pagination = paginate(
        (int) $pdo->query("SELECT COUNT(*) FROM children WHERE status = 'active'")->fetchColumn(),
        currentPageNumber(),
        20
    );
    $statusStmt = $pdo->query("
        SELECT
            c.id, c.first_name, c.last_name,
            p.first_name AS p_first, p.last_name AS p_last,
            (SELECT month_year FROM tuition_payments WHERE child_id = c.id ORDER BY month_year DESC, payment_date DESC, id DESC LIMIT 1) AS latest_month,
            (SELECT payment_date FROM tuition_payments WHERE child_id = c.id ORDER BY month_year DESC, payment_date DESC, id DESC LIMIT 1) AS latest_date,
            (SELECT SUM(amount) FROM tuition_payments WHERE child_id = c.id AND month_year = (SELECT month_year FROM tuition_payments WHERE child_id = c.id ORDER BY month_year DESC, payment_date DESC, id DESC LIMIT 1)) AS latest_month_total
        FROM children c
        INNER JOIN parents p ON p.id = c.parent_id
        WHERE c.status = 'active'
        ORDER BY c.last_name, c.first_name
        LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}
    ");
    $dashboardStatus = $statusStmt->fetchAll();

} catch (Throwable $e) {
    error_log($e->getMessage());
    if (!$errorMessage) {
        $errorMessage = 'خطایی در بارگذاری اطلاعات شهریه رخ داد.';
    }
    $children = [];
    $dashboardStatus = [];
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
            <div class="admin-section-header">
                <h2 class="admin-section-title">وضعیت پرداخت کودکان فعال</h2>
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
                                <th>آخرین ماه پرداخت</th>
                                <th>مجموع پرداختی ماه</th>
                                <th>مبلغ مورد انتظار</th>
                                <th>مانده بدهی / تسویه</th>
                                <th>تاریخ آخرین پرداخت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            [$curJy, $curJm] = gregorianToJalali((int)date('Y'), (int)date('n'), (int)date('j'));
                            $currentMonthYearStr = sprintf('%04d-%02d', $curJy, $curJm);
                            ?>
                            <?php foreach ($dashboardStatus as $s): ?>
                                <?php
                                $sCid = (int) $s['id'];
                                $sMonth = $currentMonthYearStr;
                                $balance = childOutstandingBalance($pdo, $sCid, $sMonth);

                                // Expected amount for this child & current month
                                $planStmt = $pdo->prepare('SELECT expected_amount FROM tuition_plans WHERE child_id = :cid AND month_year = :myear LIMIT 1');
                                $planStmt->execute([':cid' => $sCid, ':myear' => $sMonth]);
                                $expVal = $planStmt->fetchColumn();
                                $expectedAmount = ($expVal !== false && $expVal !== null) ? (float)$expVal : (float)getSetting('default_tuition_amount', '0');
                                ?>
                                <tr>
                                    <td style="font-weight:600;"><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></td>
                                    <td><?= e(trim($s['p_first'] . ' ' . $s['p_last'])) ?></td>
                                    <td>
                                        <?php if ($s['latest_month']): ?>
                                            <span class="badge badge-success"><?= e(formatShamsiMonthYear($s['latest_month'])) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">ندارد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s['latest_month_total'] !== null): ?>
                                            <strong><?= e(persianNumber(number_format((float) $s['latest_month_total']))) ?> تومان</strong>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e(persianNumber(number_format($expectedAmount))) ?> تومان</td>
                                    <td>
                                        <?php if ($balance > 0): ?>
                                            <span class="badge badge-danger" style="font-weight:700;">بدهکار: <?= e(persianNumber(number_format($balance))) ?> تومان</span>
                                        <?php elseif ($balance < 0): ?>
                                            <span class="badge badge-info" style="font-weight:700;">بستانکار: <?= e(persianNumber(number_format(abs($balance)))) ?> تومان</span>
                                        <?php else: ?>
                                            <span class="badge badge-success" style="font-weight:700;">تسویه کامل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $s['latest_date'] ? e(shamsiDate($s['latest_date'])) : '—' ?></td>
                                    <td>
                                        <a href="<?= e(url('admin/tuition.php?child_id=' . $s['id'] . '#recordForm')) ?>" class="btn btn-sm btn-secondary">پرداخت</a>
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
                    <?= renderPagination($pagination, url('admin/tuition.php')) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
