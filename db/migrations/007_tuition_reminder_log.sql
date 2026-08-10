-- ROMA Database Migration
-- Migration: 007_tuition_reminder_log.sql
-- Description: Add tuition_reminder_log table to track automatic overdue tuition reminders.

SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

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
