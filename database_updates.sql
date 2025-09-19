-- QuailtyMed Database Schema Updates
-- Additional tables and enhancements for missing features
-- Execute these after importing the main database.sql

USE `quailtymed`;

-- --------------------------------------------------------
-- Table structure for table `user_permissions`
-- Granular permission assignments for role-based access control
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission` varchar(100) NOT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT 1,
  `granted_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_permission` (`user_id`, `permission`),
  KEY `idx_permissions_user` (`user_id`),
  KEY `idx_permissions_permission` (`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `maintenance_records`
-- PM execution history and tracking
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `maintenance_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `maintenance_schedule_id` int(11),
  `checklist_id` int(11),
  `maintenance_type` enum('preventive','corrective','breakdown','calibration') NOT NULL,
  `performed_by` int(11) NOT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `status` enum('scheduled','in_progress','completed','cancelled','deferred') NOT NULL DEFAULT 'scheduled',
  `findings` text,
  `parts_used` text,
  `cost` decimal(10,2),
  `next_maintenance_date` date,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_records_asset` (`asset_id`),
  KEY `idx_maintenance_records_schedule` (`maintenance_schedule_id`),
  KEY `idx_maintenance_records_checklist` (`checklist_id`),
  KEY `idx_maintenance_records_status` (`status`),
  KEY `idx_maintenance_records_date` (`next_maintenance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `ncr_actions`
-- CAPA (Corrective and Preventive Actions) tracking
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ncr_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ncr_id` int(11) NOT NULL,
  `action_type` enum('immediate','corrective','preventive','verification') NOT NULL,
  `description` text NOT NULL,
  `assigned_to` int(11),
  `due_date` date,
  `completed_date` date,
  `status` enum('pending','in_progress','completed','verified','cancelled') NOT NULL DEFAULT 'pending',
  `evidence` varchar(255),
  `remarks` text,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ncr_actions_ncr` (`ncr_id`),
  KEY `idx_ncr_actions_assigned` (`assigned_to`),
  KEY `idx_ncr_actions_status` (`status`),
  KEY `idx_ncr_actions_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `report_templates`
-- Custom report definitions and templates
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `report_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text,
  `report_type` enum('compliance','maintenance','ncr','audit','dashboard') NOT NULL,
  `template_data` text NOT NULL,
  `filters` text,
  `created_by` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report_templates_type` (`report_type`),
  KEY `idx_report_templates_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `system_notifications`
-- Alert and notification management
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `system_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `message` text,
  `notification_type` enum('info','warning','error','success') NOT NULL DEFAULT 'info',
  `entity_type` varchar(50),
  `entity_id` int(11),
  `target_users` text COMMENT 'JSON array of user IDs or roles',
  `is_system_wide` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_notifications_type` (`notification_type`),
  KEY `idx_system_notifications_entity` (`entity_type`, `entity_id`),
  KEY `idx_system_notifications_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Add Foreign Key Constraints for new tables
-- --------------------------------------------------------

ALTER TABLE `user_permissions`
  ADD CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_permissions_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `maintenance_records`
  ADD CONSTRAINT `fk_maintenance_records_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_maintenance_records_schedule` FOREIGN KEY (`maintenance_schedule_id`) REFERENCES `maintenance_schedules` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_maintenance_records_checklist` FOREIGN KEY (`checklist_id`) REFERENCES `checklists` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_maintenance_records_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `ncr_actions`
  ADD CONSTRAINT `fk_ncr_actions_ncr` FOREIGN KEY (`ncr_id`) REFERENCES `ncrs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ncr_actions_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ncr_actions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `report_templates`
  ADD CONSTRAINT `fk_report_templates_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `system_notifications`
  ADD CONSTRAINT `fk_system_notifications_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- Add indexes for better performance
-- --------------------------------------------------------

CREATE INDEX `idx_checklists_asset_status` ON `checklists` (`asset_id`, `status`);
CREATE INDEX `idx_checklist_items_result_date` ON `checklist_items` (`result`, `checked_at`);
CREATE INDEX `idx_assets_next_calibration` ON `assets` (`next_calibration_date`, `status`);
CREATE INDEX `idx_ncrs_status_severity` ON `ncrs` (`status`, `severity`);

-- --------------------------------------------------------
-- Insert default permissions for existing roles
-- --------------------------------------------------------

INSERT INTO `user_permissions` (`user_id`, `permission`, `granted_by`) 
SELECT u.id, p.permission, 1
FROM `users` u
CROSS JOIN (
  SELECT 'manage_users' as permission WHERE 1
  UNION SELECT 'manage_assets'
  UNION SELECT 'manage_departments'
  UNION SELECT 'create_checklists'
  UNION SELECT 'view_reports'
  UNION SELECT 'manage_ncrs'
  UNION SELECT 'manage_maintenance'
  UNION SELECT 'system_admin'
) p
WHERE u.role IN ('superadmin', 'admin')
AND NOT EXISTS (
  SELECT 1 FROM `user_permissions` up 
  WHERE up.user_id = u.id AND up.permission = p.permission
);

-- --------------------------------------------------------
-- Add sample maintenance schedules
-- --------------------------------------------------------

INSERT INTO `maintenance_schedules` (`asset_id`, `document_type_id`, `frequency`, `frequency_value`, `next_due`, `last_done`, `is_active`) VALUES
(1, 2, 'monthly', 1, '2024-01-15', '2023-12-15', 1),
(2, 2, 'weekly', 1, DATE_ADD(CURDATE(), INTERVAL 7 DAY), CURDATE(), 1),
(3, 3, 'yearly', 1, '2024-03-10', '2023-03-10', 1);

-- --------------------------------------------------------
-- Add sample NCR actions for existing NCRs (if any)
-- --------------------------------------------------------

INSERT INTO `ncr_actions` (`ncr_id`, `action_type`, `description`, `assigned_to`, `due_date`, `status`, `created_by`) 
SELECT n.id, 'corrective', 'Investigate root cause and implement corrective measures', 
       n.assigned_to, DATE_ADD(n.created_at, INTERVAL 7 DAY), 'pending', n.raised_by
FROM `ncrs` n
WHERE NOT EXISTS (SELECT 1 FROM `ncr_actions` na WHERE na.ncr_id = n.id);

-- --------------------------------------------------------
-- Add sample report templates
-- --------------------------------------------------------

INSERT INTO `report_templates` (`name`, `description`, `report_type`, `template_data`, `created_by`) VALUES
('Monthly Compliance Report', 'Monthly compliance summary with NABH/JCI metrics', 'compliance', 
 '{"columns":["department","total_checklists","passed","failed","compliance_rate"],"filters":["date_range","department"]}', 1),
('Maintenance Summary', 'Preventive maintenance execution summary', 'maintenance', 
 '{"columns":["asset","last_maintenance","next_due","status"],"filters":["asset_type","date_range"]}', 1),
('NCR Analysis', 'Non-conformance reports analysis and trends', 'ncr', 
 '{"columns":["ncr_number","severity","status","days_open"],"filters":["severity","status","department"]}', 1);

-- --------------------------------------------------------
-- Add sample system notifications
-- --------------------------------------------------------

INSERT INTO `system_notifications` (`title`, `message`, `notification_type`, `target_users`, `is_system_wide`, `created_by`) VALUES
('System Maintenance', 'Scheduled system maintenance on Saturday from 2-4 AM', 'warning', '[]', 1, 1),
('New Feature: User Management', 'User management module has been added. Admins can now manage user accounts.', 'info', '["superadmin","admin"]', 0, 1);

COMMIT;