CREATE TABLE IF NOT EXISTS operations_pilots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT NOT NULL,
    change_date DATE NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_pilot_class_date (classroom_id, change_date),
    CONSTRAINT fk_pilot_class FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operations_pilot_days (
    pilot_id INT NOT NULL,
    work_date DATE NOT NULL,
    worked TINYINT(1) NOT NULL,
    manager_minutes SMALLINT UNSIGNED NOT NULL,
    open_cases SMALLINT UNSIGNED NOT NULL,
    closed_cases SMALLINT UNSIGNED NOT NULL,
    independent_cases SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (pilot_id, work_date),
    CONSTRAINT fk_pilot_day FOREIGN KEY (pilot_id) REFERENCES operations_pilots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operations_pilot_reports (
    report_id INT PRIMARY KEY,
    pilot_id INT NOT NULL,
    published_at DATETIME NOT NULL,
    work_ms INT UNSIGNED DEFAULT NULL,
    first_viewed_at DATETIME DEFAULT NULL,
    INDEX idx_pilot_published (pilot_id, published_at),
    CONSTRAINT fk_pilot_report_pilot FOREIGN KEY (pilot_id) REFERENCES operations_pilots(id) ON DELETE CASCADE,
    CONSTRAINT fk_pilot_report FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
