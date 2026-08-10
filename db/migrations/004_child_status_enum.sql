-- ROMA Database Migration
-- Migration: 004_child_status_enum.sql
-- Description: Expand children.status ENUM to include 'graduated' and 'withdrawn'.

SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE children MODIFY COLUMN status ENUM('pending','active','inactive','graduated','withdrawn') NOT NULL DEFAULT 'pending';
