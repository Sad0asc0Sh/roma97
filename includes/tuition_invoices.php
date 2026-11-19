<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

const TUITION_INVOICE_LABELS = [
    'overdue' => 'سررسیدگذشته',
    'discrepancy' => 'دارای مغایرت',
    'needs_due_date' => 'سررسید تعیین نشده',
    'upcoming' => 'در انتظار سررسید',
    'unissued' => 'صورتحساب صادر نشده',
    'settled' => 'تسویه‌شده',
];

function ensureTuitionInvoiceColumns(PDO $pdo): void
{
    static $ready = false;
    if (!$ready) {
        ensureDatabaseColumns($pdo, 'tuition_plans', [
            'due_date' => 'DATE DEFAULT NULL',
            'discrepancy_note' => 'VARCHAR(500) DEFAULT NULL',
        ]);
        $ready = true;
    }
}

/** The family can pay through the end of the due date in the app's timezone. */
function tuitionInvoiceNeedsReview(array $invoice): ?string
{
    if ($invoice['discrepancy_note'] !== null) {
        return $invoice['discrepancy_note'];
    }
    if (!$invoice['has_plan'] && $invoice['paid'] > 0 && abs($invoice['balance']) >= tuitionSettleTolerance()) {
        return 'پرداختِ بدون صورتحساب با مبلغ برآوردی ماه اختلاف دارد.';
    }
    if ($invoice['balance'] <= -tuitionSettleTolerance()) {
        return 'مبلغ پرداخت‌شده از مبلغ صورتحساب بیشتر است.';
    }
    return null;
}

function tuitionInvoiceState(array $invoice, string $today): string
{
    if (tuitionInvoiceNeedsReview($invoice) !== null) {
        return 'discrepancy';
    }
    if (!$invoice['has_plan']) {
        return 'unissued';
    }
    if ($invoice['balance'] < tuitionSettleTolerance()) {
        return 'settled';
    }
    if ($invoice['due_date'] === null) {
        return 'needs_due_date';
    }
    return $invoice['due_date'] < $today ? 'overdue' : 'upcoming';
}

function tuitionPeriodExists(PDO $pdo, int $childId, string $monthYear): bool
{
    $stmt = $pdo->prepare(
        'SELECT EXISTS (SELECT 1 FROM tuition_plans WHERE child_id = ? AND month_year = ?)
             OR EXISTS (SELECT 1 FROM tuition_payments WHERE child_id = ? AND month_year = ?)'
    );
    $stmt->execute([$childId, $monthYear, $childId, $monthYear]);
    return (bool) $stmt->fetchColumn();
}

/** Actual plan or payment rows. Projected tuition is never treated as an issued invoice. */
function tuitionInvoiceRows(PDO $pdo, ?string $monthYear = null, ?string $dueBefore = null): array
{
    ensureTuitionInvoiceColumns($pdo);
    $params = [];
    $scope = '';
    if ($monthYear !== null) {
        $scope .= ' AND bills.month_year = :month_year';
        $params[':month_year'] = $monthYear;
    }
    if ($dueBefore !== null) {
        $scope .= " AND tp.due_date < :today AND tp.discrepancy_note IS NULL AND c.status = 'active' AND p.status = 'active'";
        $params[':today'] = $dueBefore;
    }
    $stmt = $pdo->prepare(
        "SELECT c.id AS child_id, CONCAT_WS(' ', c.first_name, c.last_name) AS child_name,
                p.id AS parent_id, CONCAT_WS(' ', p.first_name, p.last_name) AS parent_name,
                bills.month_year, tp.expected_amount, tp.due_date, tp.discrepancy_note,
                COALESCE(pay.paid, 0) AS paid
         FROM (SELECT child_id, month_year FROM tuition_plans UNION SELECT child_id, month_year FROM tuition_payments) bills
         INNER JOIN children c ON c.id = bills.child_id INNER JOIN parents p ON p.id = c.parent_id
         LEFT JOIN tuition_plans tp ON tp.child_id = bills.child_id AND tp.month_year = bills.month_year
         LEFT JOIN (SELECT child_id, month_year, SUM(amount) AS paid
                    FROM tuition_payments GROUP BY child_id, month_year) pay
                ON pay.child_id = bills.child_id AND pay.month_year = bills.month_year
         WHERE 1=1{$scope}
         ORDER BY tp.due_date IS NULL, tp.due_date, c.last_name, c.first_name, bills.month_year DESC"
    );
    $stmt->execute($params);
    $defaultAmount = (float) getSetting('default_tuition_amount', '0');
    return array_map(static function (array $row) use ($defaultAmount): array {
        $row['has_plan'] = $row['expected_amount'] !== null;
        $row['expected'] = $row['has_plan'] ? (float) $row['expected_amount'] : $defaultAmount;
        $row['paid'] = (float) $row['paid'];
        $row['balance'] = round($row['expected'] - $row['paid'], 2);
        return $row;
    }, $stmt->fetchAll());
}
