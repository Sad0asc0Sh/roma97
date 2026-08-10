<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csv_export.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

$type = (string) ($_GET['type'] ?? '');

try {
    initializeFinancialTables();
    initializeParentTables();
    $pdo = getDb();

    if ($type === 'tuition') {
        $monthYear = (string) ($_GET['month_year'] ?? '');
        $params = [];
        $whereSql = '';

        if (preg_match('/^\d{4}-\d{2}$/', $monthYear)) {
            $whereSql = ' WHERE tp.month_year = :myear';
            $params[':myear'] = $monthYear;
        }

        $stmt = $pdo->prepare("
            SELECT
                tp.id,
                CONCAT(c.first_name, ' ', c.last_name) AS child_name,
                CONCAT(p.first_name, ' ', p.last_name) AS parent_name,
                p.phone AS parent_phone,
                tp.month_year,
                tp.amount,
                tp.payment_date,
                tp.payment_method,
                tp.notes,
                tp.created_at
            FROM tuition_payments tp
            INNER JOIN children c ON c.id = tp.child_id
            INNER JOIN parents p ON p.id = tp.parent_id
            {$whereSql}
            ORDER BY tp.payment_date DESC, tp.id DESC
        ");
        $stmt->execute($params);

        $headers = [
            'شناسه پرداخت',
            'نام کودک',
            'نام والد',
            'شماره تماس والد',
            'ماه شهریه',
            'مبلغ (تومان)',
            'تاریخ پرداخت',
            'روش پرداخت',
            'یادداشت',
            'زمان ثبت سیستم',
        ];

        $generator = (function () use ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $methodLabel = match ((string) $row['payment_method']) {
                    'cash' => 'نقدی',
                    'bank_transfer' => 'انتقال بانکی',
                    'check' => 'چک',
                    default => (string) $row['payment_method'],
                };

                yield [
                    $row['id'],
                    $row['child_name'],
                    $row['parent_name'],
                    $row['parent_phone'] ?? '—',
                    formatShamsiMonthYear((string) $row['month_year']),
                    $row['amount'],
                    shamsiDate((string) $row['payment_date']),
                    $methodLabel,
                    $row['notes'] ?? '—',
                    shamsiDate((string) $row['created_at'], 'with_time'),
                ];
            }
        })();

        $dateSuffix = $monthYear !== '' ? $monthYear : date('Y-m-d');
        recordAudit('export.tuition_csv', 'tuition_payment', null, ['month' => $monthYear]);
        streamCsvDownload("tuition-export-{$dateSuffix}.csv", $headers, $generator);
    }

    if ($type === 'salary') {
        $stmt = $pdo->query("
            SELECT
                sp.id,
                CONCAT(t.first_name, ' ', t.last_name) AS teacher_name,
                t.national_id,
                sp.month_year,
                sp.amount,
                sp.payment_date,
                sp.payment_method,
                sp.notes,
                sp.created_at
            FROM salary_payments sp
            INNER JOIN teachers t ON t.id = sp.teacher_id
            ORDER BY sp.payment_date DESC, sp.id DESC
        ");

        $headers = [
            'شناسه پرداخت',
            'نام معلم',
            'کد ملی معلم',
            'ماه پرداخت',
            'مبلغ (تومان)',
            'تاریخ پرداخت',
            'روش پرداخت',
            'یادداشت',
            'زمان ثبت سیستم',
        ];

        $generator = (function () use ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $methodLabel = match ((string) $row['payment_method']) {
                    'cash' => 'نقدی',
                    'bank_transfer' => 'انتقال بانکی',
                    'check' => 'چک',
                    default => (string) $row['payment_method'],
                };

                yield [
                    $row['id'],
                    $row['teacher_name'],
                    $row['national_id'] ?? '—',
                    formatShamsiMonthYear((string) $row['month_year']),
                    $row['amount'],
                    shamsiDate((string) $row['payment_date']),
                    $methodLabel,
                    $row['notes'] ?? '—',
                    shamsiDate((string) $row['created_at'], 'with_time'),
                ];
            }
        })();

        $dateSuffix = date('Y-m-d');
        recordAudit('export.salary_csv', 'salary_payment', null);
        streamCsvDownload("salary-export-{$dateSuffix}.csv", $headers, $generator);
    }

    if ($type === 'children') {
        $statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
        $whereSql = '';
        $params = [];

        if (in_array($statusFilter, ['pending', 'active', 'inactive', 'graduated', 'withdrawn'], true)) {
            $whereSql = ' WHERE c.status = :status';
            $params[':status'] = $statusFilter;
        }

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.first_name,
                c.last_name,
                c.preferred_name,
                c.date_of_birth,
                c.gender,
                c.status,
                c.allergies,
                c.medical_notes,
                c.second_guardian_name,
                c.second_guardian_phone,
                c.created_at,
                CONCAT(p.first_name, ' ', p.last_name) AS parent_name,
                p.email AS parent_email,
                p.phone AS parent_phone
            FROM children c
            INNER JOIN parents p ON p.id = c.parent_id
            {$whereSql}
            ORDER BY c.created_at DESC
        ");
        $stmt->execute($params);

        $headers = [
            'شناسه کودک',
            'نام',
            'نام خانوادگی',
            'نام مستعار',
            'تاریخ تولد',
            'جنسیت',
            'وضعیت ثبت‌نام',
            'نام والد اصلی',
            'ایمیل والد',
            'تلفن والد',
            'حساسیت‌ها',
            'نکات پزشکی',
            'نام سرپرست دوم',
            'تلفن سرپرست دوم',
            'تاریخ ثبت‌نام سیستم',
        ];

        $generator = (function () use ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $genderLabel = match ((string) $row['gender']) {
                    'male' => 'پسر',
                    'female' => 'دختر',
                    'other' => 'سایر',
                    default => 'مشخص نشده',
                };
                $statusLabel = match ((string) $row['status']) {
                    'active' => 'فعال',
                    'pending' => 'در انتظار',
                    'inactive' => 'غیرفعال',
                    'graduated' => 'فارغ‌التحصیل',
                    'withdrawn' => 'انصراف داده',
                    default => (string) $row['status'],
                };

                yield [
                    $row['id'],
                    $row['first_name'],
                    $row['last_name'],
                    $row['preferred_name'] ?? '—',
                    shamsiDate((string) $row['date_of_birth']),
                    $genderLabel,
                    $statusLabel,
                    $row['parent_name'],
                    $row['parent_email'] ?? '—',
                    $row['parent_phone'] ?? '—',
                    $row['allergies'] ?? '—',
                    $row['medical_notes'] ?? '—',
                    $row['second_guardian_name'] ?? '—',
                    $row['second_guardian_phone'] ?? '—',
                    shamsiDate((string) $row['created_at'], 'with_time'),
                ];
            }
        })();

        $dateSuffix = date('Y-m-d');
        recordAudit('export.children_csv', 'child', null, ['status' => $statusFilter]);
        streamCsvDownload("children-export-{$dateSuffix}.csv", $headers, $generator);
    }

    setFlash('error', 'نوع خروجی درخواست‌شده نامعتبر است.');
    redirect(url('admin/index.php'));

} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'خطایی در تولید فایل خروجی CSV رخ داد.');
    redirect(url('admin/index.php'));
}
