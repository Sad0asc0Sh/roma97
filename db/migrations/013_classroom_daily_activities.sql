CREATE TABLE IF NOT EXISTS classroom_daily_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT NOT NULL,
    activity_date DATE NOT NULL,
    activity TEXT NOT NULL,
    UNIQUE KEY unique_classroom_activity_day (classroom_id, activity_date),
    CONSTRAINT fk_cda_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_activity_attendance (
    activity_id INT NOT NULL,
    attendance_id INT NOT NULL,
    PRIMARY KEY (activity_id, attendance_id),
    INDEX idx_caa_attendance (attendance_id),
    CONSTRAINT fk_caa_activity FOREIGN KEY (activity_id) REFERENCES classroom_daily_activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_caa_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
