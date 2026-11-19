-- Weekdays use ISO order, with Monday = 0 and Sunday = 6.
CREATE TABLE IF NOT EXISTS classroom_workdays (
    classroom_id INT NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL CHECK (weekday <= 6),
    PRIMARY KEY (classroom_id, weekday),
    CONSTRAINT fk_workdays_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_holidays (
    event_id INT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    INDEX idx_school_holidays_date (holiday_date),
    CONSTRAINT fk_school_holiday_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_notices (
    child_id INT NOT NULL,
    notice_date DATE NOT NULL,
    parent_id INT NOT NULL,
    notice_type ENUM('absent','late') NOT NULL,
    expected_arrival TIME DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (child_id, notice_date),
    INDEX idx_attendance_notices_date (notice_date),
    CONSTRAINT fk_attendance_notice_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_notice_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO school_holidays (event_id, holiday_date)
SELECT id, event_date FROM events WHERE category = 'holiday' AND status IN ('scheduled', 'completed')
ON DUPLICATE KEY UPDATE holiday_date = VALUES(holiday_date);
