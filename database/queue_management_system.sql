-- ============================================================
-- Smart Queue Management System (QMS) - Database Schema
-- Database Engine: InnoDB
-- Character Set: utf8mb4 / utf8mb4_unicode_ci
-- ============================================================

CREATE DATABASE IF NOT EXISTS `queue_management_system` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `queue_management_system`;

-- Disable foreign key checks for clean table re-creation if needed
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Roles Table
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_name` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Users Table
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role_id` INT NOT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `last_login` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Services Table
DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_code` VARCHAR(10) NOT NULL UNIQUE,
    `service_name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `default_service_duration` INT NOT NULL DEFAULT 10 COMMENT 'Estimated duration in minutes',
    `maximum_queue_capacity` INT NOT NULL DEFAULT 100 COMMENT 'Daily maximum ticket capacity',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Counters Table
DROP TABLE IF EXISTS `counters`;
CREATE TABLE `counters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `counter_name` VARCHAR(50) NOT NULL,
    `counter_code` VARCHAR(20) NOT NULL UNIQUE,
    `location` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Counter Services (Pivot Table)
DROP TABLE IF EXISTS `counter_services`;
CREATE TABLE `counter_services` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `counter_id` INT NOT NULL,
    `service_id` INT NOT NULL,
    UNIQUE KEY `unique_counter_service` (`counter_id`, `service_id`),
    FOREIGN KEY (`counter_id`) REFERENCES `counters` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Staff Counter Assignments
DROP TABLE IF EXISTS `staff_counter_assignments`;
CREATE TABLE `staff_counter_assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `staff_id` INT NOT NULL,
    `counter_id` INT NOT NULL,
    `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME DEFAULT NULL,
    `status` ENUM('active', 'ended') NOT NULL DEFAULT 'active',
    FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`counter_id`) REFERENCES `counters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Customers Table
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_name` VARCHAR(100) DEFAULT 'Walk-in Customer',
    `phone` VARCHAR(30) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Queue Priorities
DROP TABLE IF EXISTS `queue_priorities`;
CREATE TABLE `queue_priorities` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `priority_name` VARCHAR(50) NOT NULL UNIQUE,
    `priority_level` INT NOT NULL DEFAULT 1 COMMENT 'Higher number indicates higher priority',
    `description` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Queue Sequences (Atomic Sequence Generator)
DROP TABLE IF EXISTS `queue_sequences`;
CREATE TABLE `queue_sequences` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_id` INT NOT NULL,
    `queue_date` DATE NOT NULL,
    `last_number` INT NOT NULL DEFAULT 0,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_service_date` (`service_id`, `queue_date`),
    FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Queues (Core Queue Table)
DROP TABLE IF EXISTS `queues`;
CREATE TABLE `queues` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `queue_number` VARCHAR(20) NOT NULL,
    `service_id` INT NOT NULL,
    `customer_id` INT DEFAULT NULL,
    `tracking_token_hash` VARCHAR(64) NOT NULL,
    `priority_id` INT DEFAULT 1,
    `status` ENUM('Waiting', 'Called', 'Serving', 'Completed', 'No-Show', 'Cancelled', 'Transferred') NOT NULL DEFAULT 'Waiting',
    `queue_date` DATE NOT NULL,
    `registration_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `called_time` DATETIME DEFAULT NULL,
    `service_start_time` DATETIME DEFAULT NULL,
    `completed_time` DATETIME DEFAULT NULL,
    `assigned_counter_id` INT DEFAULT NULL,
    `assigned_staff_id` INT DEFAULT NULL,
    `transfer_from_queue_id` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_queue_lookup` (`status`, `service_id`, `priority_id`, `registration_time`),
    INDEX `idx_token_hash` (`tracking_token_hash`),
    INDEX `idx_queue_date` (`queue_date`),
    UNIQUE KEY `unique_service_number_day` (`service_id`, `queue_date`, `queue_number`),
    FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`priority_id`) REFERENCES `queue_priorities` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`assigned_counter_id`) REFERENCES `counters` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`transfer_from_queue_id`) REFERENCES `queues` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Queue Status History
DROP TABLE IF EXISTS `queue_status_history`;
CREATE TABLE `queue_status_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `queue_id` INT NOT NULL,
    `previous_status` ENUM('Waiting', 'Called', 'Serving', 'Completed', 'No-Show', 'Cancelled', 'Transferred', 'Initiated') NOT NULL,
    `new_status` ENUM('Waiting', 'Called', 'Serving', 'Completed', 'No-Show', 'Cancelled', 'Transferred') NOT NULL,
    `changed_by` INT DEFAULT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_queue_history` (`queue_id`),
    FOREIGN KEY (`queue_id`) REFERENCES `queues` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Queue Calls Table
DROP TABLE IF EXISTS `queue_calls`;
CREATE TABLE `queue_calls` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `queue_id` INT NOT NULL,
    `counter_id` INT NOT NULL,
    `staff_id` INT NOT NULL,
    `call_type` ENUM('Initial', 'Recall') NOT NULL DEFAULT 'Initial',
    `called_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_queue_calls` (`queue_id`, `counter_id`),
    FOREIGN KEY (`queue_id`) REFERENCES `queues` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`counter_id`) REFERENCES `counters` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Counter Sessions
DROP TABLE IF EXISTS `counter_sessions`;
CREATE TABLE `counter_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `counter_id` INT NOT NULL,
    `staff_id` INT NOT NULL,
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME DEFAULT NULL,
    `status` ENUM('active', 'closed') NOT NULL DEFAULT 'active',
    FOREIGN KEY (`counter_id`) REFERENCES `counters` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Queue Transfers
DROP TABLE IF EXISTS `queue_transfers`;
CREATE TABLE `queue_transfers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `original_queue_id` INT NOT NULL,
    `destination_queue_id` INT NOT NULL,
    `source_service_id` INT NOT NULL,
    `destination_service_id` INT NOT NULL,
    `transferred_by` INT NOT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`original_queue_id`) REFERENCES `queues` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`destination_queue_id`) REFERENCES `queues` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`source_service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`destination_service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. System Settings
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `updated_by` INT DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Operating Hours
DROP TABLE IF EXISTS `operating_hours`;
CREATE TABLE `operating_hours` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `day_of_week` TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
    `opening_time` TIME NOT NULL DEFAULT '08:30:00',
    `closing_time` TIME NOT NULL DEFAULT '17:30:00',
    `registration_start_time` TIME NOT NULL DEFAULT '08:30:00',
    `registration_end_time` TIME NOT NULL DEFAULT '17:00:00',
    `is_open` TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY `unique_day_of_week` (`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Holidays
DROP TABLE IF EXISTS `holidays`;
CREATE TABLE `holidays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `holiday_date` DATE NOT NULL UNIQUE,
    `holiday_name` VARCHAR(100) NOT NULL,
    `is_closed` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Activity Logs
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Seed Initial Base Data
-- ============================================================

-- Roles
INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES
(1, 'Admin', 'Full administrative access and system configuration'),
(2, 'Staff', 'Counter operator access to manage and call queues');

-- Initial Users (Password is 'password123' hashed with BCRYPT)
INSERT INTO `users` (`id`, `name`, `username`, `email`, `password`, `role_id`, `status`) VALUES
(1, 'System Administrator', 'admin', 'admin@qms.local', '$2y$10$w.iyYzh8thttjnncIqASKOgJ480SJhM2BGGO/nYS.8pu.yf5ydtsa', 1, 'active'),
(2, 'Alice Johnson', 'staff1', 'staff1@qms.local', '$2y$10$w.iyYzh8thttjnncIqASKOgJ480SJhM2BGGO/nYS.8pu.yf5ydtsa', 2, 'active'),
(3, 'Bob Smith', 'staff2', 'staff2@qms.local', '$2y$10$w.iyYzh8thttjnncIqASKOgJ480SJhM2BGGO/nYS.8pu.yf5ydtsa', 2, 'active');

-- Services
INSERT INTO `services` (`id`, `service_code`, `service_name`, `description`, `default_service_duration`, `maximum_queue_capacity`, `status`) VALUES
(1, 'A', 'General Enquiry', 'General customer inquiries, guidance, and basic requests', 8, 150, 'active'),
(2, 'B', 'Payment & Billing', 'Cashier, payments, invoices, and fee settlement', 5, 200, 'active'),
(3, 'C', 'Account Services', 'Account opening, profile updates, and authentication', 15, 80, 'active'),
(4, 'D', 'Technical Support', 'Technical diagnosis, troubleshooting, and support', 20, 50, 'active');

-- Counters
INSERT INTO `counters` (`id`, `counter_name`, `counter_code`, `location`, `status`) VALUES
(1, 'Counter 1', 'C-01', 'Ground Floor - Main Hall', 'active'),
(2, 'Counter 2', 'C-02', 'Ground Floor - Cashier Area', 'active'),
(3, 'Counter 3', 'C-03', 'Ground Floor - Service Desk', 'active'),
(4, 'Counter 4', 'C-04', 'First Floor - Support Desk', 'active');

-- Counter Services Mapping
INSERT INTO `counter_services` (`counter_id`, `service_id`) VALUES
(1, 1), -- Counter 1 handles General Enquiry
(1, 2), -- Counter 1 also handles Payment
(2, 2), -- Counter 2 handles Payment
(3, 1), -- Counter 3 handles General Enquiry
(3, 3), -- Counter 3 handles Account Services
(4, 4); -- Counter 4 handles Technical Support

-- Initial Staff Counter Assignment
INSERT INTO `staff_counter_assignments` (`staff_id`, `counter_id`, `assigned_at`, `status`) VALUES
(2, 1, NOW(), 'active'),
(3, 2, NOW(), 'active');

-- Queue Priorities
INSERT INTO `queue_priorities` (`id`, `priority_name`, `priority_level`, `description`, `status`) VALUES
(1, 'Normal', 1, 'Standard walk-in customer', 'active'),
(2, 'Senior Citizen', 2, 'Elderly customers (60+)', 'active'),
(3, 'Person with Disability', 3, 'Customers requiring accessibility accommodations', 'active'),
(4, 'Urgent / Emergency', 4, 'Time-critical or urgent cases', 'active');

-- Operating Hours (Monday through Sunday)
INSERT INTO `operating_hours` (`day_of_week`, `opening_time`, `closing_time`, `registration_start_time`, `registration_end_time`, `is_open`) VALUES
(0, '09:00:00', '14:00:00', '09:00:00', '13:30:00', 0), -- Sunday Closed
(1, '08:30:00', '17:30:00', '08:30:00', '17:00:00', 1), -- Monday
(2, '08:30:00', '17:30:00', '08:30:00', '17:00:00', 1), -- Tuesday
(3, '08:30:00', '17:30:00', '08:30:00', '17:00:00', 1), -- Wednesday
(4, '08:30:00', '17:30:00', '08:30:00', '17:00:00', 1), -- Thursday
(5, '08:30:00', '17:30:00', '08:30:00', '16:30:00', 1), -- Friday
(6, '09:00:00', '13:00:00', '09:00:00', '12:30:00', 1); -- Saturday Half-day

-- System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_by`) VALUES
('business_name', 'Smart City Service Center', 1),
('business_address', '100 Innovation Avenue, Tech District', 1),
('business_phone', '+60 3-8888 9999', 1),
('max_recall_attempts', '3', 1),
('recall_wait_seconds', '30', 1),
('no_show_wait_minutes', '5', 1),
('allow_anonymous_registration', '1', 1),
('enable_audio_announcement', '1', 1),
('announcement_voice_lang', 'en-US', 1),
('display_refresh_interval_seconds', '3', 1),
('priority_fairness_ratio', '3', 1); -- After 3 priority calls, at least 1 normal customer is served if waiting
