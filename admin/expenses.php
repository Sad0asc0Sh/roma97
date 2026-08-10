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

try {
    initializeFinancialTables();
    $pdo = getDb();

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            redirect(url('admin/expenses.php'));
        }

        $action = (string) ($_POST['action'] ?? 'create');

        if ($action === 'delete') {
            $expenseId = (int) ($_POST['expense_id'] ?? 0);
            if ($expenseId > 0) {
                $delStmt = $pdo->prepare('DELETE FROM expenses WHERE id = :id');
                $delStmt->execute([':id' => $expenseId]);
                recordAudit('expense.delete', 'expense', $expenseId);
                setFlash('success', 'هزینه مورد نظر با موفقیت حذف شد.');
            } else {
                setFlash('error', 'شناسه هزینه نامعتبر است.');
            }
            redirect(url('admin/expenses.php'));
        }

        $category      = (string) ($_POST['category'] ?? 'other');
        $title         = trim((string) ($_POST['title'] ?? ''));
        $amount        = (float) ($_POST['amount'] ?? 0);
        $expenseDateRaw = (string) ($_POST['expense_date'] ?? date('Y-m-d'));
        $expenseDate   = parseJalaliDate($expenseDateRaw) ?? $expenseDateRaw;
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
        $notes         = trim((string) ($_POST['notes'] ?? ''));

        $dateTimeCheck = DateTime::createFromFormat('Y-m-d', $expenseDate);
        $isValidDate = $dateTimeCheck && $dateTimeCheck->format('Y-m-d') === $expenseDate;

        $allowedCategories = ['rent', 'utilities', 'food', 'maintenance', 'supplies', 'insurance', 'other'];
        $allowedMethods    = ['cash', 'bank_transfer', 'check'];

        if (
            $title === ''
            || $amount <= 0
            || $amount >= 1000000000
            || !$isValidDate
            || !in_array($category, $allowedCategories, true)
            || !in_array($paymentMethod, $allowedMethods, true)
        ) {
            setFlash('error', 'لطفاً همه فیلدهای الزامی را به‌درستی پر کنید (عنوان، مبلغ معتبر، تاریخ معتبر).');
            redirect(url('admin/expenses.php'));
        }

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO expenses (category, title, amount, expense_date, payment_method, notes, created_by_admin_id)
             VALUES (:cat, :title, :amount, :edate, :pmeth, :notes, :admin_id)'
        );
        $stmt->execute([
            ':cat'      => $category,
            ':title'    => $title,
            ':amount'   => $amount,
            ':edate'    => $expenseDate,
            ':pmeth'    => $paymentMethod,
            ':notes'    => $notes === '' ? null : $notes,
            ':admin_id' => $adminId > 0 ? $adminId : null,
        ]);

        $expenseId = (int) $pdo->lastInsertId();

        recordAudit('expense.create', 'expense', $expenseId, [
            'title' => $title,
            'amount' => $amount,
            'category' => $category,
        ]);

        setFlash('success', 'هزینه جدید با موفقیت ثبت شد.');
        redirect(url('admin/expenses.php'));
    }

    $pagination = paginate(
        (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
        currentPageNumber(),
        20
    );
    $expensesStmt = $pdo->query("
        SELECT *
        FROM expenses
        ORDER BY expense_date DESC, created_at DESC
        LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}
    ");
    $recentExpenses = $expensesStmt->fetchAll();

} catch (Throwable $e) {
    error_log($e->getMessage());
    if (!$errorMessage) {
        $errorMessage = 'خطایی در بارگذاری اطلاعات هزینه‌ها رخ داد.';
    }
    $recentExpenses = [];
    $pagination = paginate(0, 1, 20);
}

$pageTitle = 'مدیریت هزینه‌ها | مدیریت | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>مدیریت هزینه‌های عمومی مهد</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-two-column">
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">ثبت هزینه جدید</h2>
            </div>
            <form method="post" action="<?= e(url('admin/expenses.php')) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-group">
                    <label for="category" class="form-label">دسته‌بندی هزینه</label>
                    <select name="category" id="category" class="form-control" required>
                        <option value="rent">اجاره</option>
                        <option value="utilities">قبوض (آب، برق، گاز، تلفن)</option>
                        <option value="food">مواد غذایی</option>
                        <option value="maintenance">تعمیر و نگهداری</option>
                        <option value="supplies">ملزومات و لوازم تحریر</option>
                        <option value="insurance">بیمه</option>
                        <option value="other" selected>سایر</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="title" class="form-label">عنوان هزینه</label>
                    <input type="text" name="title" id="title" class="form-control" placeholder="مثلاً: اجاره ماه مرداد" required maxlength="255">
                </div>

                <div class="form-group">
                    <label for="amount" class="form-label">مبلغ (تومان)</label>
                    <input type="number" step="0.01" name="amount" id="amount" class="form-control" placeholder="۰" required>
                </div>

                <div class="form-group">
                    <label for="expense_date" class="form-label">تاریخ هزینه</label>
                    <input type="text" name="expense_date" id="expense_date" class="form-control shamsi-datepicker" value="<?= e(shamsiDate(date('Y-m-d'), 'numeric')) ?>" placeholder="۱۴۰۵/۰۵/۱۸" required>
                </div>

                <div class="form-group">
                    <label for="payment_method" class="form-label">روش پرداخت</label>
                    <select name="payment_method" id="payment_method" class="form-control" required>
                        <option value="cash">نقدی</option>
                        <option value="bank_transfer" selected>انتقال بانکی</option>
                        <option value="check">چک</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes" class="form-label">یادداشت (اختیاری)</label>
                    <input type="text" name="notes" id="notes" class="form-control" placeholder="شماره فاکتور، توضیحات و ...">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">ثبت هزینه</button>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">لیست هزینه‌ها</h2>
            </div>
            <?php if (empty($recentExpenses)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <h3>هنوز هیچ هزینه‌ای ثبت نشده است</h3>
                </div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>تاریخ</th>
                                <th>عنوان / دسته‌بندی</th>
                                <th>مبلغ</th>
                                <th>روش</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentExpenses as $exp): ?>
                                <tr>
                                    <td><?= e(shamsiDate($exp['expense_date'])) ?></td>
                                    <td>
                                        <div style="font-weight:600;"><?= e((string) $exp['title']) ?></div>
                                        <span class="badge badge-info" style="font-size:0.75rem;"><?= e(expenseCategoryLabel((string) $exp['category'])) ?></span>
                                    </td>
                                    <td class="amount-highlight"><?= e(persianNumber(number_format((float) $exp['amount']))) ?> تومان</td>
                                    <td><?= e(match((string) $exp['payment_method']) { 'cash' => 'نقدی', 'bank_transfer' => 'کارت/حساب', 'check' => 'چک', default => $exp['payment_method'] }) ?></td>
                                    <td>
                                        <form method="post" action="<?= e(url('admin/expenses.php')) ?>" class="inline-form" onsubmit="return confirm('آیا از حذف این هزینه اطمینان دارید؟');">
                                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="expense_id" value="<?= (int) $exp['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pagination['total'] > $pagination['perPage']): ?>
                    <p class="pagination-summary">
                        نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> هزینه
                    </p>
                    <?= renderPagination($pagination, url('admin/expenses.php')) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
