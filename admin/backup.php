<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

if (isPostRequest() && isset($_POST['action']) && $_POST['action'] === 'download_backup') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!validateCsrfToken($csrfToken)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/backup.php'));
    }

    try {
        $pdo = getDb();

        if (headers_sent()) {
            die('تولید پشتیبان به‌دلیل ارسال پیش‌فرض هدرها متوقف شد.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $dateStr = date('Y-m-d_H-i-s');
        $filename = "roma_backup_{$dateStr}.sql";
        $safeFilename = rawurlencode($filename);

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . $safeFilename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            die('امکان ایجاد خروجی پشتیبان وجود ندارد.');
        }

        fwrite($out, "-- ========================================================\n");
        fwrite($out, "-- ROMA Daycare Database Backup\n");
        fwrite($out, "-- Generated at: " . date('Y-m-d H:i:s') . "\n");
        fwrite($out, "-- Database: " . DB_NAME . "\n");
        fwrite($out, "-- ========================================================\n\n");
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($out, "SET NAMES utf8mb4;\n");
        fwrite($out, "SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';\n\n");

        // Fetch all tables in current database
        $tablesStmt = $pdo->query('SHOW TABLES');
        $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($tables as $table) {
            $tableName = (string) $table;

            fwrite($out, "-- --------------------------------------------------------\n");
            fwrite($out, "-- Table structure for `{$tableName}`\n");
            fwrite($out, "-- --------------------------------------------------------\n");
            fwrite($out, "DROP TABLE IF EXISTS `{$tableName}`;\n");

            // Get Create Table SQL
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$tableName}`");
            $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : null;
            $createSql = $createRow['Create Table'] ?? '';

            if ($createSql !== '') {
                fwrite($out, $createSql . ";\n\n");
            }

            // Stream row data for table row-by-row
            fwrite($out, "-- Data dumping for `{$tableName}`\n");
            $dataStmt = $pdo->query("SELECT * FROM `{$tableName}`");

            if ($dataStmt) {
                while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                    $columnNames = array_keys($row);
                    $escapedCols = array_map(static fn($col) => "`" . str_replace("`", "``", (string)$col) . "`", $columnNames);

                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } elseif (is_int($val) || is_float($val)) {
                            $values[] = (string) $val;
                        } else {
                            $values[] = $pdo->quote((string) $val);
                        }
                    }

                    $line = "INSERT INTO `{$tableName}` (" . implode(', ', $escapedCols) . ") VALUES (" . implode(', ', $values) . ");\n";
                    fwrite($out, $line);
                }
            }

            fwrite($out, "\n");
        }

        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($out, "-- End of backup\n");

        fclose($out);
        recordAudit('backup.download', 'database', null, ['filename' => $filename]);
        exit;

    } catch (Throwable $e) {
        error_log($e->getMessage());
        setFlash('error', 'ایجاد نسخه پشتیبان دیتابیس با مشکل مواجه شد: ' . $e->getMessage());
        redirect(url('admin/backup.php'));
    }
}

$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$pageTitle = 'پشتیبان‌گیری دیتابیس | مدیریت | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="admin-page-header" style="margin-bottom: 24px;">
        <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0 0 4px 0;">پشتیبان‌گیری کامل دیتابیس (SQL) 💾</h1>
        <p class="muted" style="margin: 0;">دریافت نسخه پشتیبان کامل از تمامی اطلاعات و جداول سیستم</p>
    </div>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="card" style="padding: 24px; max-width: 800px;">
        <div style="display: flex; gap: 16px; align-items: flex-start; margin-bottom: 20px; padding: 16px; background: rgba(221,107,32,0.08); border-right: 4px solid #dd6b20; border-radius: 8px;">
            <div style="font-size: 1.5rem; line-height: 1;">⚠️</div>
            <div style="font-size: 0.92rem; line-height: 1.6; color: var(--adm-text);">
                <strong>هشدار ایمنی مهم:</strong><br>
                توصیه می‌شود این پشتیبان را حداقل هفته‌ای یک‌بار دانلود کرده و در جایی امن خارج از سرور (مانند گوگل درایو، هارد اکسترنال یا لپ‌تاپ شخصی) ذخیره نمایید تا در صورت بروز هرگونه مشکل برای هاست یا دیتابیس، اطلاعات مهدکودک قابل بازیابی باشد.
            </div>
        </div>

        <form method="post" action="<?= e(url('admin/backup.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="download_backup">

            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 8px;">مشخصات نسخه پشتیبان:</h3>
                <ul style="font-size: 0.9rem; color: var(--adm-text-muted); line-height: 1.8; margin: 0; padding-right: 20px;">
                    <li>فرمت فایل: SQL (شامل ساختار کامل CREATE TABLE و تمام داده‌ها INSERT INTO)</li>
                    <li>روش تولید: Streaming ردیف‌به‌ردیف جهت جلوگیری از کمبود حافظه سرور</li>
                    <li>قابل بازیابی از طریق: cPanel phpMyAdmin یا دستور CLI مای‌اس‌کیوال</li>
                </ul>
            </div>

            <button type="submit" class="btn btn-primary" style="font-size: 1rem; padding: 12px 24px;">
                📥 دانلود نسخه پشتیبان کامل (SQL)
            </button>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
