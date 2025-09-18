-- QuailtyMed Healthcare Quality Management System Database Schema
-- Compatible with MySQL 5.7+ and XAMPP
-- Includes NABH and JCI standards compliance features

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Create database
CREATE DATABASE IF NOT EXISTS `quailtymed` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `quailtymed`;

-- --------------------------------------------------------
-- Table structure for table `users`
-- Stores user accounts with role-based access control
-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','auditor','dept_manager','technician','viewer') NOT NULL DEFAULT 'viewer',
  `department_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `departments`
-- Hospital departments like Maintenance, Radiology, etc.
-- --------------------------------------------------------

CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `head_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dept_name` (`name`),
  KEY `idx_dept_head` (`head_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `asset_types`
-- Equipment categories within departments
-- --------------------------------------------------------

CREATE TABLE `asset_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_asset_types_dept` (`department_id`),
  KEY `idx_asset_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `document_types`
-- Types of documents like Daily Checklist, PM, Calibration
-- --------------------------------------------------------

CREATE TABLE `document_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `requires_signature` tinyint(1) NOT NULL DEFAULT 1,
  `validity_days` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `assets`
-- Individual equipment/asset records
-- --------------------------------------------------------

CREATE TABLE `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_type_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `asset_tag` varchar(50) UNIQUE,
  `model` varchar(100),
  `serial_no` varchar(100),
  `location` varchar(200),
  `vendor` varchar(150),
  `installation_date` date,
  `warranty_end` date,
  `next_calibration_date` date,
  `status` enum('active','inactive','maintenance','decommissioned') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_asset_tag` (`asset_tag`),
  KEY `idx_assets_type` (`asset_type_id`),
  KEY `idx_assets_calibration` (`next_calibration_date`),
  KEY `idx_assets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `standards`
-- NABH and JCI standard clauses mapping
-- --------------------------------------------------------

CREATE TABLE `standards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` enum('NABH','JCI') NOT NULL,
  `clause_id` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text,
  `category` varchar(100),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_standards_source` (`source`),
  KEY `idx_standards_clause` (`clause_id`),
  KEY `idx_standards_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `checklists`
-- Main checklist records
-- --------------------------------------------------------

CREATE TABLE `checklists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `checklist_name` varchar(200),
  `created_by` int(11) NOT NULL,
  `assigned_to` int(11),
  `status` enum('draft','in_progress','completed','signed_off','expired') NOT NULL DEFAULT 'draft',
  `due_date` date,
  `completed_at` timestamp NULL DEFAULT NULL,
  `signed_off_by` int(11) DEFAULT NULL,
  `signed_off_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_checklists_asset` (`asset_id`),
  KEY `idx_checklists_doc_type` (`document_type_id`),
  KEY `idx_checklists_status` (`status`),
  KEY `idx_checklists_due_date` (`due_date`),
  KEY `idx_checklists_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `checklist_items`
-- Individual checklist questions and results
-- --------------------------------------------------------

CREATE TABLE `checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checklist_id` int(11) NOT NULL,
  `standard_id` int(11),
  `question` text NOT NULL,
  `result` enum('pass','fail','na','pending') DEFAULT 'pending',
  `remarks` text,
  `attached_file` varchar(255),
  `checked_by` int(11),
  `checked_at` timestamp NULL DEFAULT NULL,
  `order_index` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_checklist_items_checklist` (`checklist_id`),
  KEY `idx_checklist_items_standard` (`standard_id`),
  KEY `idx_checklist_items_result` (`result`),
  KEY `idx_checklist_items_order` (`order_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `maintenance_schedules`
-- Preventive maintenance scheduling
-- --------------------------------------------------------

CREATE TABLE `maintenance_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `frequency` enum('daily','weekly','monthly','quarterly','yearly') NOT NULL,
  `frequency_value` int(11) NOT NULL DEFAULT 1,
  `next_due` date NOT NULL,
  `last_done` date,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_asset` (`asset_id`),
  KEY `idx_maintenance_next_due` (`next_due`),
  KEY `idx_maintenance_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `ncrs`
-- Non-Conformance Reports
-- --------------------------------------------------------

CREATE TABLE `ncrs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ncr_number` varchar(50) NOT NULL UNIQUE,
  `checklist_item_id` int(11),
  `asset_id` int(11),
  `department_id` int(11),
  `description` text NOT NULL,
  `root_cause` text,
  `corrective_action` text,
  `preventive_action` text,
  `raised_by` int(11) NOT NULL,
  `assigned_to` int(11),
  `status` enum('open','in_progress','completed','verified','closed') NOT NULL DEFAULT 'open',
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `due_date` date,
  `completed_date` date,
  `evidence` varchar(255),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ncr_number` (`ncr_number`),
  KEY `idx_ncrs_checklist_item` (`checklist_item_id`),
  KEY `idx_ncrs_asset` (`asset_id`),
  KEY `idx_ncrs_status` (`status`),
  KEY `idx_ncrs_severity` (`severity`),
  KEY `idx_ncrs_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `audit_logs`
-- System audit trail for all critical actions
-- --------------------------------------------------------

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11),
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50),
  `entity_id` int(11),
  `meta_json` text,
  `ip_address` varchar(45),
  `user_agent` varchar(255),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `attachments`
-- File attachment management
-- --------------------------------------------------------

CREATE TABLE `attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(100),
  `file_size` int(11),
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_entity` (`entity_type`, `entity_id`),
  KEY `idx_attachments_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `notifications`
-- System notifications and alerts
-- --------------------------------------------------------

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11),
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text,
  `entity_type` varchar(50),
  `entity_id` int(11),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  KEY `idx_notifications_type` (`type`),
  KEY `idx_notifications_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Foreign Key Constraints
-- --------------------------------------------------------

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

ALTER TABLE `departments`
  ADD CONSTRAINT `fk_departments_head` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `asset_types`
  ADD CONSTRAINT `fk_asset_types_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

ALTER TABLE `assets`
  ADD CONSTRAINT `fk_assets_asset_type` FOREIGN KEY (`asset_type_id`) REFERENCES `asset_types` (`id`) ON DELETE CASCADE;

ALTER TABLE `checklists`
  ADD CONSTRAINT `fk_checklists_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_checklists_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_checklists_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_checklists_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_checklists_signed_off_by` FOREIGN KEY (`signed_off_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `checklist_items`
  ADD CONSTRAINT `fk_checklist_items_checklist` FOREIGN KEY (`checklist_id`) REFERENCES `checklists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_checklist_items_standard` FOREIGN KEY (`standard_id`) REFERENCES `standards` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_checklist_items_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_schedules`
  ADD CONSTRAINT `fk_maintenance_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_maintenance_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE;

ALTER TABLE `ncrs`
  ADD CONSTRAINT `fk_ncrs_checklist_item` FOREIGN KEY (`checklist_item_id`) REFERENCES `checklist_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ncrs_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ncrs_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ncrs_raised_by` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ncrs_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `attachments`
  ADD CONSTRAINT `fk_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------
-- Sample Data - Default Super Admin User
-- Password: 'admin123' (hashed with bcrypt)
-- --------------------------------------------------------

INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('System Administrator', 'admin@quailtymed.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin');

-- --------------------------------------------------------
-- Sample Data - Departments
-- --------------------------------------------------------

INSERT INTO `departments` (`name`, `description`) VALUES
('Maintenance', 'Hospital maintenance department responsible for equipment upkeep'),
('Radiology', 'Medical imaging and diagnostic equipment'),
('Laboratory', 'Clinical laboratory and testing equipment'),
('ICU', 'Intensive Care Unit equipment and monitoring'),
('OT', 'Operation Theatre equipment and systems'),
('Pharmacy', 'Pharmaceutical storage and dispensing equipment');

-- --------------------------------------------------------
-- Sample Data - Asset Types
-- --------------------------------------------------------

INSERT INTO `asset_types` (`department_id`, `name`, `description`) VALUES
(1, 'Transformer', 'Electrical transformers and power equipment'),
(1, 'Diesel Generator', 'Backup power generation systems'),
(1, 'Electric', 'General electrical equipment and panels'),
(1, 'Medical Gas', 'Medical gas pipeline and equipment'),
(1, 'AC', 'Air conditioning and HVAC systems'),
(2, 'X-Ray Machine', 'Radiographic imaging equipment'),
(2, 'CT Scanner', 'Computed tomography scanners'),
(2, 'MRI', 'Magnetic resonance imaging equipment'),
(3, 'Analyzer', 'Laboratory analyzers and testing equipment'),
(3, 'Centrifuge', 'Laboratory centrifuge equipment'),
(4, 'Ventilator', 'Mechanical ventilation equipment'),
(4, 'Monitor', 'Patient monitoring systems');

-- --------------------------------------------------------
-- Sample Data - Document Types
-- --------------------------------------------------------

INSERT INTO `document_types` (`name`, `description`, `requires_signature`, `validity_days`) VALUES
('Daily Checklist', 'Daily equipment inspection and verification', 1, 1),
('Preventive Maintenance', 'Scheduled maintenance activities', 1, 30),
('Calibration', 'Equipment calibration and verification', 1, 365),
('Breakdown Maintenance', 'Corrective maintenance for equipment failures', 1, NULL);

-- --------------------------------------------------------
-- Sample Data - NABH and JCI Standards
-- --------------------------------------------------------

INSERT INTO `standards` (`source`, `clause_id`, `title`, `description`, `category`) VALUES
('NABH', 'FMS.1', 'Facility Management and Safety Program', 'The organization establishes and implements a program to provide a safe, functional, supportive, and effective environment for patients, families, staff, and visitors.', 'Facility Management'),
('NABH', 'FMS.2', 'Safety and Security Plan', 'The organization plans and implements a program to provide security for persons and protection of property from loss or damage.', 'Safety'),
('NABH', 'FMS.3', 'Fire Safety Program', 'The organization establishes and implements a fire safety program to protect individuals and property from fire and smoke.', 'Fire Safety'),
('NABH', 'FMS.4', 'Medical Equipment Management', 'The organization establishes and implements a program for inspection, testing, and maintenance of medical equipment.', 'Equipment Management'),
('NABH', 'FMS.5', 'Utility Systems Management', 'The organization establishes and implements a program for inspection, testing, and maintenance of utility systems.', 'Utilities'),
('JCI', 'FMS.01.01.01', 'Leadership Oversight', 'Hospital leaders establish oversight for the facility management and safety program.', 'Leadership'),
('JCI', 'FMS.02.01.01', 'Safety Management Plan', 'The hospital has a safety management plan that addresses safety and security risks throughout the hospital.', 'Safety Management'),
('JCI', 'FMS.03.01.01', 'Fire Safety Plan', 'The hospital has a fire safety plan that addresses fire prevention, early detection, suppression, and safe evacuation.', 'Fire Safety'),
('JCI', 'FMS.04.01.01', 'Equipment Management Program', 'There is an equipment management program throughout the hospital.', 'Equipment'),
('JCI', 'FMS.05.01.01', 'Utility Systems Program', 'There is a utility systems management program throughout the hospital.', 'Utilities');

-- --------------------------------------------------------
-- Sample Data - Assets
-- --------------------------------------------------------

INSERT INTO `assets` (`asset_type_id`, `name`, `asset_tag`, `model`, `serial_no`, `location`, `vendor`, `installation_date`, `next_calibration_date`, `status`) VALUES
(1, 'Main Transformer Unit 1', 'TRF-001', 'TX-1000', 'TX123456', 'Electrical Room A', 'PowerTech Solutions', '2023-01-15', '2024-01-15', 'active'),
(2, 'Emergency Generator 1', 'DG-001', 'DG-500KVA', 'DG789012', 'Generator Room', 'GenPower Ltd', '2023-02-01', '2024-02-01', 'active'),
(6, 'X-Ray Machine ICU', 'XR-001', 'XR-Pro-2000', 'XR345678', 'ICU Department', 'MedEquip Inc', '2023-03-10', '2024-03-10', 'active');

COMMIT;
