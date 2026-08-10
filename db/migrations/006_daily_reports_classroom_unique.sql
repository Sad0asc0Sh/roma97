-- ROMA Database Migration
-- Migration: 006_daily_reports_classroom_unique.sql
-- Description: Update daily_reports unique key to include classroom_id (classroom_id, child_id, report_date).

SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
DROP PROCEDURE IF EXISTS update_daily_reports_unique_key//
CREATE PROCEDURE update_daily_reports_unique_key()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE table_schema = DATABASE()
          AND table_name = 'daily_reports'
          AND constraint_name = 'unique_child_report'
    ) THEN
        ALTER TABLE daily_reports DROP KEY unique_child_report;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE table_schema = DATABASE()
          AND table_name = 'daily_reports'
          AND constraint_name = 'unique_classroom_child_report'
    ) THEN
        ALTER TABLE daily_reports ADD UNIQUE KEY unique_classroom_child_report (classroom_id, child_id, report_date);
    END IF;
END//
DELIMITER ;
CALL update_daily_reports_unique_key();
DROP PROCEDURE IF EXISTS update_daily_reports_unique_key;
