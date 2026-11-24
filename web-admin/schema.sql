-- FieldPlus Database Schema
CREATE DATABASE IF NOT EXISTS `fieldplus_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fieldplus_db`;

-- Roles & Granular Permissions Table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_system` TINYINT(1) DEFAULT 0,
    `permissions_json` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users & Authenticated Accounts Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` VARCHAR(50) NOT NULL DEFAULT 'field_staff',
    `api_token` VARCHAR(64) NOT NULL UNIQUE,
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_api_token` (`api_token`),
    INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Forms definition table
CREATE TABLE IF NOT EXISTS `forms` (
    `id` VARCHAR(64) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(100) DEFAULT 'General',
    `version` INT NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `archived_at` DATETIME NULL,
    `created_by` INT NULL,
    `access_type` ENUM('all', 'creator_only', 'specific_users', 'specific_roles') NOT NULL DEFAULT 'all',
    `assigned_users_json` LONGTEXT NULL,
    `assigned_roles_json` LONGTEXT NULL,
    `schema_json` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_is_archived` (`is_archived`),
    CONSTRAINT `fk_forms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Submissions table
CREATE TABLE IF NOT EXISTS `submissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `submission_uuid` VARCHAR(64) NOT NULL UNIQUE,
    `form_id` VARCHAR(64) NOT NULL,
    `user_id` INT NULL,
    `device_id` VARCHAR(100) NULL,
    `submitter_name` VARCHAR(150) NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `altitude` DECIMAL(8, 2) NULL,
    `accuracy` DECIMAL(6, 2) NULL,
    `location_label` VARCHAR(255) NULL,
    `journey_path_json` LONGTEXT NULL,
    `journey_start_time` DATETIME NULL,
    `journey_end_time` DATETIME NULL,
    `journey_duration_seconds` INT NULL,
    `journey_distance_meters` DECIMAL(10, 2) NULL,
    `data_json` LONGTEXT NOT NULL,
    `status` VARCHAR(30) DEFAULT 'submitted',
    `review_notes` TEXT NULL,
    `reviewed_at` TIMESTAMP NULL,
    `submitted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_form_id` (`form_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_submission_uuid` (`submission_uuid`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_submissions_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_submissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Submission Attachments (Photos, Signatures, Documents)
CREATE TABLE IF NOT EXISTS `submission_attachments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `submission_uuid` VARCHAR(64) NOT NULL,
    `field_id` VARCHAR(64) NOT NULL,
    `file_type` VARCHAR(50) NOT NULL, -- 'image', 'signature', 'file'
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` INT DEFAULT 0,
    `mime_type` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_attachment_sub` (`submission_uuid`),
    CONSTRAINT `fk_attachment_sub` FOREIGN KEY (`submission_uuid`) REFERENCES `submissions` (`submission_uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting and brute force protection
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `attempt_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_time` (`ip_address`, `attempt_time`),
    INDEX `idx_user_time` (`username`, `attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security audit logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `username` VARCHAR(100) NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

