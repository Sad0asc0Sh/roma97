-- Keep existing attendance times and message read flags. Older replies receive
-- a deadline when a staff member sets up their follow-up.
DELIMITER //
DROP PROCEDURE IF EXISTS roma_daily_operations//
CREATE PROCEDURE roma_daily_operations()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'picked_up_by') THEN
        ALTER TABLE attendance ADD COLUMN picked_up_by VARCHAR(201) DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'pickup_verified_by') THEN
        ALTER TABLE attendance ADD COLUMN pickup_verified_by INT DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'follow_up_status') THEN
        ALTER TABLE messages ADD COLUMN follow_up_status ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'follow_up_admin_id') THEN
        ALTER TABLE messages ADD COLUMN follow_up_admin_id INT DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'response_due_at') THEN
        ALTER TABLE messages ADD COLUMN response_due_at DATETIME DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classroom_waitlist' AND COLUMN_NAME = 'fulfilled_at') THEN
        ALTER TABLE classroom_waitlist ADD COLUMN fulfilled_at DATETIME DEFAULT NULL;
    END IF;
    UPDATE classroom_waitlist cw
    INNER JOIN child_classroom cc ON cc.child_id = cw.child_id AND cc.classroom_id = cw.classroom_id
    SET cw.fulfilled_at = CURRENT_TIMESTAMP WHERE cw.fulfilled_at IS NULL;
END//
DELIMITER ;
CALL roma_daily_operations();
DROP PROCEDURE IF EXISTS roma_daily_operations;
