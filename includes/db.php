<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../config.php';

const DEFAULT_ADMIN_USERNAME = 'admin';
// NOTE: First-run auto-creates the admin account with this one-time password.
// The admin MUST change it immediately after first login via admin/settings.php.
// This constant is ONLY used in initializeDatabase() which seeds an empty table.
const DEFAULT_ADMIN_PASSWORD = 'admin123';

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

function initializeDatabase(): bool
{
    $pdo = getDb();

    $createTableSql = <<<SQL
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $createTable = $pdo->prepare($createTableSql);
    $createTable->execute();

    // Initialize messaging table
    initializeMessagingTable();

    // Initialize audit log table
    require_once __DIR__ . '/audit.php';
    initializeAuditTable();

    $countAdmins = $pdo->prepare('SELECT COUNT(*) FROM admins');
    $countAdmins->execute();

    if ((int) $countAdmins->fetchColumn() > 0) {
        return false;
    }

    $insertAdmin = $pdo->prepare(
        'INSERT INTO admins (username, password) VALUES (:username, :password)'
    );

    $insertAdmin->execute([
        ':username' => DEFAULT_ADMIN_USERNAME,
        ':password' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
    ]);

    return true;
}

function initializeCmsTables(): void
{
    $pdo = getDb();

    $tables = [
        <<<SQL
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meta_key VARCHAR(100) UNIQUE NOT NULL,
    meta_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS slides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    image VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];

    foreach ($tables as $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute();
    }

    $defaults = [
        'site_name' => 'Rooma',
        'site_description' => 'Welcome to Rooma Daycare',
        'logo' => '',
        'contact_phone' => '+98 21 1234 5678',
    ];

    $seedSetting = $pdo->prepare(
        'INSERT INTO settings (meta_key, meta_value) VALUES (:meta_key, :meta_value)
         ON DUPLICATE KEY UPDATE meta_value = settings.meta_value'
    );

    foreach ($defaults as $key => $value) {
        $seedSetting->execute([
            ':meta_key' => $key,
            ':meta_value' => $value,
        ]);
    }
}

function initializeParentTables(): void
{
    $pdo = getDb();

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('pending','active','suspended') DEFAULT 'pending',
    email_verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute();

    $checkAvatar = $pdo->prepare("SHOW COLUMNS FROM parents LIKE 'avatar'");
    $checkAvatar->execute();

    if (!$checkAvatar->fetch()) {
        $addAvatar = $pdo->prepare(
            'ALTER TABLE parents ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER phone'
        );
        $addAvatar->execute();
    }

    initializeChildrenTable();
    initializeAttendanceTable();
}

function initializeChildrenTable(): void
{
    $pdo = getDb();

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS children (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    preferred_name VARCHAR(100) DEFAULT NULL,
    date_of_birth DATE NOT NULL,
    gender VARCHAR(10) DEFAULT '',
    allergies TEXT DEFAULT NULL,
    medical_notes TEXT DEFAULT NULL,
    second_guardian_name VARCHAR(200) DEFAULT NULL,
    second_guardian_phone VARCHAR(20) DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','active','inactive') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_children_parent_id (parent_id),
    CONSTRAINT fk_children_parent
        FOREIGN KEY (parent_id) REFERENCES parents(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute();
}

function initializeAttendanceTable(): void
{
    $pdo = getDb();

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
    check_in TIME DEFAULT NULL,
    check_out TIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_child_date (child_id, attendance_date),
    CONSTRAINT fk_attendance_child
        FOREIGN KEY (child_id) REFERENCES children(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute();

    initializeEventTables();
}

function initializeEventTables(): void
{
    $pdo = getDb();

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    event_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    category VARCHAR(50) DEFAULT 'general',
    status ENUM('scheduled','cancelled','completed') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute();
}

function initializeTeachersTables(): void
{
    $pdo = getDb();

    $tables = [
        <<<SQL
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    national_id VARCHAR(20) DEFAULT NULL,
    education_level VARCHAR(100) DEFAULT NULL,
    major VARCHAR(100) DEFAULT NULL,
    certificate_file VARCHAR(255) DEFAULT NULL,
    hire_date DATE DEFAULT NULL,
    salary DECIMAL(12,2) DEFAULT NULL,
    status ENUM('pending','active','inactive') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS classrooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    capacity INT DEFAULT 15,
    schedule TEXT NULL,
    teacher_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_classroom_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS child_classroom (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_id INT NOT NULL,
    classroom_id INT NOT NULL,
    enrollment_date DATE NOT NULL,
    UNIQUE KEY unique_child (child_id),
    CONSTRAINT fk_cc_child
        FOREIGN KEY (child_id) REFERENCES children(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_cc_classroom
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS daily_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    classroom_id INT NOT NULL,
    child_id INT NOT NULL,
    report_date DATE NOT NULL,
    mood VARCHAR(50) DEFAULT NULL,
    activities TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_child_report (child_id, report_date),
    CONSTRAINT fk_dr_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dr_classroom
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dr_child
        FOREIGN KEY (child_id) REFERENCES children(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS login_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,
    teacher_id INT NOT NULL,
    created_by_admin_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    CONSTRAINT fk_lt_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_lt_admin
        FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];

    foreach ($tables as $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute();
    }

    try {
        $pdo->exec('ALTER TABLE daily_reports ADD COLUMN activities TEXT NULL AFTER mood');
    } catch (Throwable) {}

    // Migrate daily_reports from per-classroom to per-child (idempotent).
    // Each statement is a no-op on a fresh schema and harmlessly fails if already applied.
    try {
        $pdo->exec('ALTER TABLE daily_reports ADD COLUMN child_id INT NULL AFTER classroom_id');
    } catch (Throwable) {}
    try {
        $pdo->exec('ALTER TABLE daily_reports DROP INDEX unique_daily_report');
    } catch (Throwable) {}
    try {
        $pdo->exec('ALTER TABLE daily_reports ADD UNIQUE KEY unique_child_report (child_id, report_date)');
    } catch (Throwable) {}
    try {
        $pdo->exec(
            'ALTER TABLE daily_reports ADD CONSTRAINT fk_dr_child '
            . 'FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE'
        );
    } catch (Throwable) {}
}

function initializeFinancialTables(): void
{
    $pdo = getDb();

    $tables = [
        <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];

    foreach ($tables as $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute();
    }
}

function initializeMessagingTable(): void
{
    $pdo = getDb();

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('admin','teacher') NOT NULL,
    sender_id INT NOT NULL,
    parent_id INT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_parent_id (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute();
}
