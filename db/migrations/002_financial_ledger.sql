-- ROMA Database Migration
-- Migration: 002_financial_ledger.sql
-- Description: Convert single-record monthly tuition and salary payments to multi-payment ledger, add tuition_plans and expenses tables, add default_tuition_amount setting.
-- IMPORTANT: Before executing ALTER statements on tables with existing data, please take a full database backup (e.g. using mysqldump or cPanel).

SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Modify tuition_payments UNIQUE constraint to non-unique INDEX
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_tuition_payments_index//
CREATE PROCEDURE migrate_tuition_payments_index()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE table_schema = DATABASE()
          AND table_name = 'tuition_payments'
          AND constraint_name = 'unique_child_month'
    ) THEN
        ALTER TABLE tuition_payments DROP KEY unique_child_month;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE table_schema = DATABASE()
          AND table_name = 'tuition_payments'
          AND index_name = 'idx_tuition_child_month'
    ) THEN
        ALTER TABLE tuition_payments ADD INDEX idx_tuition_child_month (child_id, month_year);
    END IF;
END//
DELIMITER ;
CALL migrate_tuition_payments_index();
DROP PROCEDURE IF EXISTS migrate_tuition_payments_index;

-- 2. Modify salary_payments UNIQUE constraint to non-unique INDEX
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_salary_payments_index//
CREATE PROCEDURE migrate_salary_payments_index()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE table_schema = DATABASE()
          AND table_name = 'salary_payments'
          AND constraint_name = 'unique_teacher_month'
    ) THEN
        ALTER TABLE salary_payments DROP KEY unique_teacher_month;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE table_schema = DATABASE()
          AND table_name = 'salary_payments'
          AND index_name = 'idx_salary_teacher_month'
    ) THEN
        ALTER TABLE salary_payments ADD INDEX idx_salary_teacher_month (teacher_id, month_year);
    END IF;
END//
DELIMITER ;
CALL migrate_salary_payments_index();
DROP PROCEDURE IF EXISTS migrate_salary_payments_index;

-- 3. Create tuition_plans table
CREATE TABLE IF NOT EXISTS tuition_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_id INT NOT NULL,
    month_year VARCHAR(7) NOT NULL,
    expected_amount DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_plan_child_month (child_id, month_year),
    CONSTRAINT fk_plan_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create expenses table
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
    CONSTRAINT fk_expense_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seed default_tuition_amount setting if missing
INSERT INTO settings (meta_key, meta_value) VALUES
    ('default_tuition_amount', '0')
ON DUPLICATE KEY UPDATE meta_value = meta_value;
