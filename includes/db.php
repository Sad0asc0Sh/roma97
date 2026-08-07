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
                UNIQUE KEY unique_teacher_month (teacher_id, month_year),
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
                UNIQUE KEY unique_child_month (child_id, month_year),
                CONSTRAINT fk_tuition_parent
                    FOREIGN KEY (parent_id) REFERENCES parents(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_tuition_child
                    FOREIGN KEY (child_id) REFERENCES children(id)
                    ON DELETE CASCADE
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
