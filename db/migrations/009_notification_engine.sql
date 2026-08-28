-- ROMA Database Migration
-- Migration: 009_notification_engine.sql
-- Description: Unified notification engine — adds the SMS delivery queue and the
--              idempotency (de-duplication) log that prevent duplicate/spam SMS
--              when a form is saved twice, plus indexes for the SMS Center reports.

SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Outbound SMS delivery queue.
-- Web requests enqueue and flush within a small time budget; the cron worker
-- (bin/process-sms-queue.php) drains the remainder, so a 40-child classroom
-- save never blocks the page on the SMS provider.
CREATE TABLE IF NOT EXISTS sms_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_phone VARCHAR(20) NOT NULL,
    pattern_body_id INT NOT NULL,
    args_json TEXT NULL,
    event_type VARCHAR(50) NOT NULL DEFAULT 'general',
    parent_id INT NULL,
    dedup_key VARCHAR(191) NULL,
    priority TINYINT NOT NULL DEFAULT 5,
    status ENUM('pending','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts TINYINT NOT NULL DEFAULT 0,
    rec_id VARCHAR(100) NULL,
    last_error VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_sms_queue_dedup (dedup_key),
    INDEX idx_sms_queue_status (status, priority, id),
    INDEX idx_sms_queue_parent (parent_id),
    INDEX idx_sms_queue_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification idempotency log.
-- One row per (event, recipient, business key). The UNIQUE constraint is what
-- makes "save the attendance sheet again" a no-op instead of a second SMS.
CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(50) NOT NULL,
    parent_id INT NULL,
    dedup_key VARCHAR(191) NOT NULL,
    channels VARCHAR(50) NOT NULL DEFAULT 'sms',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_notification_dedup (dedup_key),
    INDEX idx_notification_event (event_key, created_at),
    INDEX idx_notification_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reporting indexes for the SMS Center KPIs (sent today / failed today / 30-day volume).
-- Added defensively; ignore duplicate-key errors when re-running the migration.
ALTER TABLE sms_logs ADD INDEX idx_sms_logs_status_created (status, created_at);
ALTER TABLE sms_logs ADD INDEX idx_sms_logs_type (message_type);
