-- Each family reads a broadcast independently. Existing direct-message flags remain valid.
CREATE TABLE IF NOT EXISTS message_reads (
    message_id INT NOT NULL,
    parent_id INT NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, parent_id),
    INDEX idx_message_reads_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
