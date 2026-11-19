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

try {
    initializeFinancialTables();
    $pdo = getDb();

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            redirect(url('admin/salary.php'));
        }

        $teacherId     = (int) ($_POST['teacher_id'] ?? 0);
        $amount        = (float) ($_POST['amount'] ?? 0);
        $paymentDate   = parseIranDate($_POST['payment_date'] ?? date('Y-m-d'));
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'bank_transfer');
        $monthInput    = $_POST['month_year'] ?? shamsiDate(time(), 'Y-m');
        $monthYear     = is_string($monthInput) ? portalMonthYear($monthInput) : null;
        $notes         = trim((string) ($_POST['notes'] ?? ''));

        $validSalaryMethods = ['cash', 'bank_transfer', 'check'];

        if ($teacherId === 0 || $amount <= 0 || $amount >= 1000000000 || $paymentDate === null || $monthYear === null || !in_array($paymentMethod, $validSalaryMethods, true)) {
            setFlash('error', 'مربی، مبلغ، تاریخ شمسی پرداخت و ماه حقوق را بررسی کنید.');
            redirect(url('admin/salary.php'));
        }

        $stmt = $pdo->prepare(
            'INSERT INTO salary_payments (teacher_id, amount, payment_date, payment_method, month_year, notes)
             VALUES (:tid, :amount, :pdate, :pmeth, :myear, :notes)'
        );
        $stmt->execute([
            ':tid'    => $teacherId,
            ':amount' => $amount,
            ':pdate'  => $paymentDate,
            ':pmeth'  => $paymentMethod,
            ':myear'  => $monthYear,
            ':notes'  => $notes === '' ? null : $notes,
        ]);

        $salaryId = (int) $pdo->lastInsertId();

        recordAudit('salary.payment', 'salary_payment', $salaryId, ['teacher_id' => $teacherId, 'month' => $monthYear, 'amount' => $amount]);
        setFlash('success', 'پرداخت حقوق با موفقیت ثبت شد.');
        redirect(url('admin/salary.php'));
    }

    // Fetch teachers for dropdown
    $teachersStmt = $pdo->query("SELECT id, first_name, last_name, salary FROM teachers WHERE status != 'pending' ORDER BY first_name");
    $teachers = $teachersStmt->fetchAll();

    // Fetch recent payments for table (paginated)
    $pagination = paginate(
        (int) $pdo->query('SELECT COUNT(*) FROM salary_payments')->fetchColumn(),
        currentPageNumber(),
        20
    );
    $paymentsStmt = $pdo->query("
        SELECT sp.*, t.first_name, t.last_name 
        FROM salary_payments sp
        INNER JOIN teachers t ON t.id = sp.teacher_id
        ORDER BY sp.payment_date DESC, sp.created_at DESC
        LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}
    ");
    $recentPayments = $paymentsStmt->fetchAll();

} catch (Throwable $e) {
    error_log($e->getMessage());
    if (!$errorMessage) {
        $errorMessage = 'خطایی در بارگذاری اطلاعات حقوق رخ داد.';
    }
    $teachers = [];
    $recentPayments = [];
    $pagination = paginate(0, 1, 20);
}

$pageTitle = 'مدیریت حقوق | مدیریت | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <h1>مدیریت حقوق مربیان</h1>
            <p class="adm-page-subtitle">ثبت پرداخت حقوق ماهانه مربیان، سوابق تسویه و خروجی مالی</p>
        </div>
        <div class="adm-page-actions">
            <a href="<?= e(url('admin/export-csv.php?type=salary')) ?>" class="btn btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span>دریافت خروجی CSV حقوق</span>
            </a>
        </div>
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
                <h2 class="admin-section-title">ثبت پرداخت حقوق</h2>
            </div>
            <form method="post" action="<?= e(url('admin/salary.php')) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="form-group">
                    <label for="teacher_id" class="form-label">معلم</label>
                    <select name="teacher_id" id="teacher_id" class="form-control" required>
                        <option value="">-- انتخاب معلم --</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= e((string) $t['id']) ?>">
                                <?= e(trim($t['first_name'] . ' ' . $t['last_name'])) ?>
                                <?php if ((float) ($t['salary'] ?? 0) > 0): ?>
                                    (پایه: <?= e(persianNumber(number_format((float) $t['salary']))) ?> تومان)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="month_year" class="form-label">ماه حقوق</label>
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
                    <label for="payment_date" class="form-label">تاریخ شمسی پرداخت</label>
                    <input type="text" name="payment_date" id="payment_date" class="form-control shamsi-datepicker" dir="ltr" inputmode="numeric" autocomplete="off" value="<?= e(shamsiDate(date('Y-m-d'), 'numeric')) ?>" placeholder="۱۴۰۵/۰۵/۱۸" required>
                </div>

                <div class="form-group">
                    <label for="payment_method" class="form-label">روش پرداخت</label>
                    <select name="payment_method" id="payment_method" class="form-control" required>
                        <option value="bank_transfer">انتقال بانکی</option>
                        <option value="cash">نقدی</option>
                        <option value="check">چک</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes" class="form-label">یادداشت (اختیاری)</label>
                    <input type="text" name="notes" id="notes" class="form-control" placeholder="شناسه تراکنش، پاداشهای ویژه و ...">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">ثبت پرداخت</button>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">پرداختهای اخیر</h2>
            </div>
            <?php if (empty($recentPayments)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <h3>هنوز پرداخت حقوقی ثبت نشده</h3>
                </div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>تاریخ</th>
                                <th>معلم</th>
                                <th>ماه</th>
                                <th>مبلغ</th>
                                <th>روش</th>
                                <th>یادداشت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPayments as $pay): ?>
                                <tr>
                                    <td><?= e(shamsiDate($pay['payment_date'])) ?></td>
                                    <td style="font-weight:600;"><?= e(trim($pay['first_name'] . ' ' . $pay['last_name'])) ?></td>
                                    <td><?= e(formatShamsiMonthYear($pay['month_year'])) ?></td>
                                    <td class="amount-highlight"><?= e(persianNumber(number_format((float) $pay['amount']))) ?> تومان</td>
                                    <td><?= e(match((string) $pay['payment_method']) { 'cash' => 'نقدی', 'bank_transfer' => 'انتقال بانکی', 'check' => 'چک', default => $pay['payment_method'] }) ?></td>
                                    <td class="notes-ellipsis"><?= e($pay['notes'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pagination['total'] > $pagination['perPage']): ?>
                    <p class="pagination-summary">
                        نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> پرداخت
                    </p>
                    <?= renderPagination($pagination, url('admin/salary.php')) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
