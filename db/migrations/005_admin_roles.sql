-- ROMA Database Migration
-- Migration: 005_admin_roles.sql
-- Description: Add role column to admins table with default 'owner'.

SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
DROP PROCEDURE IF EXISTS add_admin_role_column_if_missing//
CREATE PROCEDURE add_admin_role_column_if_missing()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE table_schema = DATABASE()
          AND table_name = 'admins'
          AND column_name = 'role'
    ) THEN
        ALTER TABLE admins ADD COLUMN role ENUM('owner','manager','accountant','receptionist') NOT NULL DEFAULT 'owner' AFTER password;
    END IF;
END//
DELIMITER ;
CALL add_admin_role_column_if_missing();
DROP PROCEDURE IF EXISTS add_admin_role_column_if_missing;
