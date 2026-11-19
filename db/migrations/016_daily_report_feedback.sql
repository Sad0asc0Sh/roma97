CREATE TABLE IF NOT EXISTS daily_report_feedback (
    report_id INT NOT NULL,
    parent_id INT NOT NULL,
    seen_at DATETIME NOT NULL,
    reaction ENUM('helpful','more_details') DEFAULT NULL,
    reacted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (report_id, parent_id),
    CONSTRAINT fk_feedback_report FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
