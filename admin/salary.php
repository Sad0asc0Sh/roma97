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
        $paymentDate   = (string) ($_POST['payment_date'] ?? date('Y-m-d'));
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'bank_transfer');
        $monthYear     = (string) ($_POST['month_year'] ?? date('Y-m'));
        $notes         = trim((string) ($_POST['notes'] ?? ''));

        if ($teacherId === 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}$/', $monthYear)) {
            setFlash('error', 'لطفاً همه فیلدهای الزامی را به‌درستی پر کنید (مبلغ > ۰، ماه معتبر).');
            redirect(url('admin/salary.php'));
        }

        $sql = <<<SQL
INSERT INTO salary_payments (teacher_id, amount, payment_date, payment_method, month_year, notes)
VALUES (:tid, :amount, :pdate, :pmeth, :myear, :notes) AS new
ON DUPLICATE KEY UPDATE
    amount         = new.amount,
    payment_date   = new.payment_date,
    payment_method = new.payment_method,
    notes          = new.notes
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid'    => $teacherId,
            ':amount' => $amount,
            ':pdate'  => $paymentDate,
            ':pmeth'  => $paymentMethod,
            ':myear'  => $monthYear,
            ':notes'  => $notes === '' ? null : $notes,
        ]);

        recordAudit('salary.payment', 'salary_payment', (int) $pdo->lastInsertId(), ['teacher_id' => $teacherId, 'month' => $monthYear]);
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
    <h1>&#128176; مدیریت حقوق</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status">&#9989; <?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert">&#10060; <?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-two-column">
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">&#10010; ثبت پرداخت حقوق</h2>
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
                                (پایه: <?= e(number_format((float) ($t['salary'] ?? 0), 2)) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="month_year" class="form-label">ماه پرداخت</label>
                    <input type="month" name="month_year" id="month_year" class="form-control" value="<?= e(date('Y-m')) ?>" required>
                </div>

                <div class="form-group">
                    <label for="amount" class="form-label">مبلغ (تومان)</label>
                    <input type="number" step="0.01" name="amount" id="amount" class="form-control" placeholder="۰" required>
                </div>

                <div class="form-group">
                    <label for="payment_date" class="form-label">تاریخ پرداخت</label>
                    <input type="date" name="payment_date" id="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
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
                    <button type="submit" class="btn btn-primary">&#128190; ثبت پرداخت</button>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">&#128203; پرداختهای اخیر</h2>
            </div>
            <?php if (empty($recentPayments)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">&#128176;</div>
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
                                    <td><?= e($pay['month_year']) ?></td>
                                    <td class="amount-highlight"><?= e(number_format((float) $pay['amount'], 2)) ?> ت</td>
                                    <td><?= e(ucwords(str_replace('_', ' ', $pay['payment_method']))) ?></td>
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
