-- ROMA Database Migration
-- Migration: 008_classroom_waitlist.sql
-- Description: Add classroom_waitlist table for managing classroom waiting lists when capacity is full.

SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_id INT NOT NULL,
    classroom_id INT NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    UNIQUE KEY unique_child_waitlist (child_id, classroom_id),
    CONSTRAINT fk_cw_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    CONSTRAINT fk_cw_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
