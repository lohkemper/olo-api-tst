-- ============================================================================
-- 34_seed_grow_permissions.sql
-- MBC Grow Module - Permissions & Navigation Seed
-- ----------------------------------------------------------------------------
-- Version: 0.1.0
-- Beschreibung: Berechtigungen und Top-Level-Navigation für das Grow-Modul.
--               Setzt 33_grow-schema.sql voraus.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Insert Grow Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Grow-Cycle Permissions
('grow.cycles.read.own',    'grow_cycles', 'read',   'own', 'Eigene Aufzucht-Durchläufe anzeigen'),
('grow.cycles.read.any',    'grow_cycles', 'read',   'any', 'Beliebige Durchläufe anzeigen (Admin)'),
('grow.cycles.create',      'grow_cycles', 'create', NULL,  'Neuen Durchlauf erstellen'),
('grow.cycles.update.own',  'grow_cycles', 'update', 'own', 'Eigenen Durchlauf bearbeiten'),
('grow.cycles.update.any',  'grow_cycles', 'update', 'any', 'Beliebigen Durchlauf bearbeiten (Admin)'),
('grow.cycles.delete.own',  'grow_cycles', 'delete', 'own', 'Eigenen Durchlauf löschen'),
('grow.cycles.delete.any',  'grow_cycles', 'delete', 'any', 'Beliebigen Durchlauf löschen (Admin)'),
-- Grow-Plant Permissions
('grow.plants.read.own',    'grow_plants', 'read',   'own', 'Eigene Pflanzen anzeigen'),
('grow.plants.read.any',    'grow_plants', 'read',   'any', 'Beliebige Pflanzen anzeigen (Admin)'),
('grow.plants.create',      'grow_plants', 'create', NULL,  'Pflanze anlegen'),
('grow.plants.update.own',  'grow_plants', 'update', 'own', 'Eigene Pflanze bearbeiten'),
('grow.plants.update.any',  'grow_plants', 'update', 'any', 'Beliebige Pflanze bearbeiten (Admin)'),
('grow.plants.delete.own',  'grow_plants', 'delete', 'own', 'Eigene Pflanze löschen'),
('grow.plants.delete.any',  'grow_plants', 'delete', 'any', 'Beliebige Pflanze löschen (Admin)');

-- ============================================================================
-- Assign Permissions to Roles
-- ============================================================================

-- User: Standard-Grow-Berechtigungen (eigener Bereich)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'grow.cycles.read.own', 'grow.cycles.create', 'grow.cycles.update.own', 'grow.cycles.delete.own',
  'grow.plants.read.own',  'grow.plants.create',  'grow.plants.update.own',  'grow.plants.delete.own'
);

-- Moderator: identisch zu User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'grow.cycles.read.own', 'grow.cycles.create', 'grow.cycles.update.own', 'grow.cycles.delete.own',
  'grow.plants.read.own',  'grow.plants.create',  'grow.plants.update.own',  'grow.plants.delete.own'
);

-- Admin: alle Grow-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'admin'
AND p.resource IN ('grow_cycles', 'grow_plants');

-- Super Admin: alle Grow-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'super_admin'
AND p.resource IN ('grow_cycles', 'grow_plants');

-- ============================================================================
-- Navigation-Eintrag für Grow-Modul
-- ============================================================================

INSERT IGNORE INTO `mbc_navigations` (
  `navigations_id`, `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  NULL, NULL, 'Grow', 'plant', '/grow',
  75, -- Position nach Gym (70)
  1
);

-- Navigation-Rollen-Zuordnung
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route LIKE '/grow%'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');

COMMIT;

-- ============================================================================
-- Update Schema Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('grow_permissions', '0.1.0', 'Berechtigungen und Navigation für Grow-Modul (Phase 1)')
ON DUPLICATE KEY UPDATE
  version = '0.1.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Berechtigungen und Navigation für Grow-Modul (Phase 1)';
