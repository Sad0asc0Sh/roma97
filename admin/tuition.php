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

        $sql = <<<SQL
INSERT INTO tuition_payments (parent_id, child_id, amount, payment_date, payment_method, month_year, notes)
VALUES (:pid, :cid, :amount, :pdate, :pmeth, :myear, :notes) AS new
ON DUPLICATE KEY UPDATE
    amount         = new.amount,
    payment_date   = new.payment_date,
    payment_method = new.payment_method,
    notes          = new.notes
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':pid'    => $parentId,
            ':cid'    => $childId,
            ':amount' => $amount,
            ':pdate'  => $paymentDate,
            ':pmeth'  => $paymentMethod,
            ':myear'  => $monthYear,
            ':notes'  => $notes === '' ? null : $notes,
        ]);

        // lastInsertId() returns 0 on UPDATE (ON DUPLICATE KEY UPDATE), so fetch the real id
        $idStmt = $pdo->prepare('SELECT id FROM tuition_payments WHERE child_id = :cid AND month_year = :myear LIMIT 1');
        $idStmt->execute([':cid' => $childId, ':myear' => $monthYear]);
        $tuitionId = (int) $idStmt->fetchColumn();

        recordAudit('tuition.payment', 'tuition_payment', $tuitionId);
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
            (SELECT payment_date FROM tuition_payments WHERE child_id = c.id ORDER BY month_year DESC, payment_date DESC, id DESC LIMIT 1) AS latest_date
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
    <h1>مدیریت شهریه</h1>

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
                                <th>تاریخ آخرین پرداخت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboardStatus as $s): ?>
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
