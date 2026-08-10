<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../config.php';

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Set strict SQL mode for data integrity
    $pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

    return $pdo;
}

/**
 * Runtime schema initialisation has been removed from the request path.
 * Tables are created via setup.php (or schema.sql) at install time.
 * These functions are kept as no-ops for backward compatibility with
 * any code that still calls them.
 */
function initializeDatabase(): bool
{
    return false;
}

function initializeCmsTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeParentTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeChildrenTable(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeAttendanceTable(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeEventTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeTeachersTables(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    try {
        $pdo = getDb();
        $cols = $pdo->query("SHOW COLUMNS FROM teachers")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('role_title', $cols, true)) {
            $pdo->exec("ALTER TABLE teachers ADD COLUMN role_title VARCHAR(150) DEFAULT NULL AFTER major");
        }
        if (!in_array('bio', $cols, true)) {
            $pdo->exec("ALTER TABLE teachers ADD COLUMN bio TEXT DEFAULT NULL AFTER role_title");
        }
        if (!in_array('show_in_team', $cols, true)) {
            $pdo->exec("ALTER TABLE teachers ADD COLUMN show_in_team TINYINT(1) DEFAULT 1 AFTER status");
        }
        if (!in_array('sort_order', $cols, true)) {
            $pdo->exec("ALTER TABLE teachers ADD COLUMN sort_order INT DEFAULT 0 AFTER show_in_team");
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

function initializeFinancialTables(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    try {
        $pdo = getDb();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS salary_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                payment_date DATE NOT NULL,
                payment_method ENUM('cash','bank_transfer','check') DEFAULT 'bank_transfer',
                month_year VARCHAR(7) NOT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_salary_teacher_month (teacher_id, month_year),
                CONSTRAINT fk_salary_teacher
                    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tuition_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_id INT NOT NULL,
                child_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                payment_date DATE NOT NULL,
                payment_method ENUM('cash','bank_transfer','check') DEFAULT 'cash',
                month_year VARCHAR(7) NOT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tuition_child_month (child_id, month_year),
                CONSTRAINT fk_tuition_parent
                    FOREIGN KEY (parent_id) REFERENCES parents(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_tuition_child
                    FOREIGN KEY (child_id) REFERENCES children(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tuition_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                child_id INT NOT NULL,
                month_year VARCHAR(7) NOT NULL,
                expected_amount DECIMAL(12,2) NOT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_plan_child_month (child_id, month_year),
                CONSTRAINT fk_plan_child
                    FOREIGN KEY (child_id) REFERENCES children(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category ENUM('rent','utilities','food','maintenance','supplies','insurance','other') NOT NULL DEFAULT 'other',
                title VARCHAR(255) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                expense_date DATE NOT NULL,
                payment_method ENUM('cash','bank_transfer','check') DEFAULT 'cash',
                notes TEXT NULL,
                created_by_admin_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_expense_admin
                    FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(64) UNIQUE NOT NULL,
                account_type ENUM('parent','teacher') NOT NULL,
                account_id INT NOT NULL,
                created_by_admin_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) DEFAULT 0,
                INDEX idx_prt_account (account_type, account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Migrate indexes on existing tables idempotently if unique constraints still exist
        $checkTuition = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE table_schema = DATABASE() AND table_name = 'tuition_payments' AND constraint_name = 'unique_child_month'
        ")->fetchColumn();
        if ((int) $checkTuition > 0) {
            $pdo->exec("ALTER TABLE tuition_payments DROP KEY unique_child_month");
        }
        $checkTuitionIdx = $pdo->query("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE() AND table_name = 'tuition_payments' AND index_name = 'idx_tuition_child_month'
        ")->fetchColumn();
        if ((int) $checkTuitionIdx === 0) {
            $pdo->exec("ALTER TABLE tuition_payments ADD INDEX idx_tuition_child_month (child_id, month_year)");
        }

        $checkSalary = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE table_schema = DATABASE() AND table_name = 'salary_payments' AND constraint_name = 'unique_teacher_month'
        ")->fetchColumn();
        if ((int) $checkSalary > 0) {
            $pdo->exec("ALTER TABLE salary_payments DROP KEY unique_teacher_month");
        }
        $checkSalaryIdx = $pdo->query("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE() AND table_name = 'salary_payments' AND index_name = 'idx_salary_teacher_month'
        ")->fetchColumn();
        if ((int) $checkSalaryIdx === 0) {
            $pdo->exec("ALTER TABLE salary_payments ADD INDEX idx_salary_teacher_month (teacher_id, month_year)");
        }

        // Idempotently modify children.status ENUM
        $pdo->exec("ALTER TABLE children MODIFY COLUMN status ENUM('pending','active','inactive','graduated','withdrawn') NOT NULL DEFAULT 'pending'");

        // Idempotently add role column to admins table if missing
        $adminCols = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('role', $adminCols, true)) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN role ENUM('owner','manager','accountant','receptionist') NOT NULL DEFAULT 'owner' AFTER password");
        }

        // Idempotently update daily_reports unique key
        $checkDrKey = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE table_schema = DATABASE() AND table_name = 'daily_reports' AND constraint_name = 'unique_child_report'
        ")->fetchColumn();
        if ((int) $checkDrKey > 0) {
            $pdo->exec("ALTER TABLE daily_reports DROP KEY unique_child_report");
        }
        $checkDrNewKey = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE table_schema = DATABASE() AND table_name = 'daily_reports' AND constraint_name = 'unique_classroom_child_report'
        ")->fetchColumn();
        if ((int) $checkDrNewKey === 0) {
            $pdo->exec("ALTER TABLE daily_reports ADD UNIQUE KEY unique_classroom_child_report (classroom_id, child_id, report_date)");
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tuition_reminder_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                child_id INT NOT NULL,
                parent_id INT NOT NULL,
                month_year VARCHAR(7) NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_trl_child_sent (child_id, sent_at),
                CONSTRAINT fk_trl_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                CONSTRAINT fk_trl_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS classroom_waitlist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                child_id INT NOT NULL,
                classroom_id INT NOT NULL,
                requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                notes TEXT NULL,
                UNIQUE KEY unique_child_waitlist (child_id, classroom_id),
                CONSTRAINT fk_cw_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                CONSTRAINT fk_cw_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

function initializeMessagingTable(): void
{
    // Schema is created at install time via setup.php / schema.sql
}
