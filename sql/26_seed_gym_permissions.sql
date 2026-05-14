-- ============================================================================
-- MBC Gym Module - Permissions & Navigation Seed
-- ============================================================================
-- Version: 0.1.0
-- Erstellt: 2026-05-03
-- Beschreibung: Berechtigungen und Top-Level-Navigation für das Gym-Modul.
--               Setzt 25_gym-schema.sql voraus.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Insert Gym Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Exercise Permissions (Catalog)
('gym.exercises.read.own',         'gym_exercises',           'read',   'own',  'Eigene + System-Übungen anzeigen'),
('gym.exercises.read.any',         'gym_exercises',           'read',   'any',  'Beliebige Übungen anzeigen (Admin)'),
('gym.exercises.create',           'gym_exercises',           'create', NULL,   'Neue Übungen erstellen'),
('gym.exercises.update.own',       'gym_exercises',           'update', 'own',  'Eigene Übungen bearbeiten'),
('gym.exercises.update.any',       'gym_exercises',           'update', 'any',  'Beliebige Übungen bearbeiten (Admin)'),
('gym.exercises.delete.own',       'gym_exercises',           'delete', 'own',  'Eigene Übungen löschen'),
('gym.exercises.delete.any',       'gym_exercises',           'delete', 'any',  'Beliebige Übungen löschen (Admin)'),

-- Exercise Category Permissions
('gym.exercise_categories.read',   'gym_exercise_categories', 'read',   NULL,   'Übungs-Kategorien anzeigen'),
('gym.exercise_categories.create', 'gym_exercise_categories', 'create', NULL,   'Neue Kategorien erstellen (Admin)'),
('gym.exercise_categories.update', 'gym_exercise_categories', 'update', NULL,   'Kategorien bearbeiten (Admin)'),
('gym.exercise_categories.delete', 'gym_exercise_categories', 'delete', NULL,   'Kategorien löschen (Admin)');


-- ============================================================================
-- Assign Permissions to Roles
-- ============================================================================

-- User: Standard-Gym-Berechtigungen (eigener Bereich)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'gym.exercises.read.own',
  'gym.exercises.create',
  'gym.exercises.update.own',
  'gym.exercises.delete.own',
  'gym.exercise_categories.read'
);

-- Moderator: Identische Berechtigungen wie User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'gym.exercises.read.own',
  'gym.exercises.create',
  'gym.exercises.update.own',
  'gym.exercises.delete.own',
  'gym.exercise_categories.read'
);

-- Admin: Alle Gym-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'admin'
AND p.resource IN ('gym_exercises', 'gym_exercise_categories');

-- Super Admin: Alle Gym-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'super_admin'
AND p.resource IN ('gym_exercises', 'gym_exercise_categories');


-- ============================================================================
-- Navigation-Einträge für Gym-Modul
-- ============================================================================

-- Top-Level: Gym
INSERT IGNORE INTO `mbc_navigations` (
  `navigations_id`,
  `parent_id`,
  `title`,
  `icon`,
  `route`,
  `sort_order`,
  `is_active`
) VALUES (
  NULL,
  NULL,
  'Gym',
  'activity',
  '/gym',
  70, -- Position nach Warehouse (60)
  1
);


-- ============================================================================
-- Navigation-Rollen-Zuordnung
-- ============================================================================

-- Gym-Navigation für User, Moderator, Admin, Super Admin
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route LIKE '/gym%'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Update Schema Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym_permissions', '0.1.0', 'Berechtigungen und Navigation für Gym-Modul (Phase 0)')
ON DUPLICATE KEY UPDATE
  version = '0.1.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Berechtigungen und Navigation für Gym-Modul (Phase 0)';

-- ============================================================================
-- Ende — Phase 0 Permissions & Navigation
-- ============================================================================
