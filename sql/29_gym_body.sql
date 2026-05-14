-- ============================================================================
-- MBC Gym Module - Body-Tracking (Phase 3)
-- ============================================================================
-- Version: 0.4.0
-- Erstellt: 2026-05-04
-- Beschreibung: Körpermaße und Body-Composition. Foto-Tracking folgt in
--               Phase 3.5 (multipart upload, eigene Tabelle).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_gym_body_measurements
-- Beschreibung: Tagesgenaue Körpermaße (eine Messung pro User+Tag)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_body_measurements (
  body_measurements_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL,

  measured_at DATE NOT NULL COMMENT 'Tag der Messung',

  -- Composition
  weight_kg      DECIMAL(5,2) DEFAULT NULL,
  body_fat_pct   DECIMAL(4,2) DEFAULT NULL,
  muscle_mass_kg DECIMAL(5,2) DEFAULT NULL,

  -- Umfänge in cm
  chest_cm       DECIMAL(5,2) DEFAULT NULL,
  waist_cm       DECIMAL(5,2) DEFAULT NULL,
  hips_cm        DECIMAL(5,2) DEFAULT NULL,
  arm_left_cm    DECIMAL(5,2) DEFAULT NULL,
  arm_right_cm   DECIMAL(5,2) DEFAULT NULL,
  thigh_left_cm  DECIMAL(5,2) DEFAULT NULL,
  thigh_right_cm DECIMAL(5,2) DEFAULT NULL,
  calf_left_cm   DECIMAL(5,2) DEFAULT NULL,
  calf_right_cm  DECIMAL(5,2) DEFAULT NULL,
  neck_cm        DECIMAL(5,2) DEFAULT NULL,

  notes TEXT DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_body_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Eine Messung pro Tag pro User (überschreiben statt duplizieren)
  UNIQUE KEY uniq_gym_body_user_day (user_id, measured_at),

  INDEX idx_gym_body_user_id (user_id),
  INDEX idx_gym_body_measured_at (measured_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Körpermaße tagesgenau (Gym-Modul, Phase 3)';


-- ============================================================================
-- Phase-3 Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('gym.body_measurements.read.own',   'gym_body_measurements', 'read',   'own',  'Eigene Körpermaße anzeigen'),
('gym.body_measurements.read.any',   'gym_body_measurements', 'read',   'any',  'Beliebige Körpermaße anzeigen (Admin)'),
('gym.body_measurements.create',     'gym_body_measurements', 'create', NULL,   'Körpermaße erfassen'),
('gym.body_measurements.update.own', 'gym_body_measurements', 'update', 'own',  'Eigene Körpermaße bearbeiten'),
('gym.body_measurements.delete.own', 'gym_body_measurements', 'delete', 'own',  'Eigene Körpermaße löschen'),

('gym.analytics.read.own',           'gym_analytics',         'read',   'own',  'Eigene Auswertungen anzeigen'),
('gym.analytics.read.any',           'gym_analytics',         'read',   'any',  'Beliebige Auswertungen anzeigen (Admin)');


-- User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'gym.body_measurements.read.own',
  'gym.body_measurements.create',
  'gym.body_measurements.update.own',
  'gym.body_measurements.delete.own',
  'gym.analytics.read.own'
);

-- Moderator: identisch zu User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'gym.body_measurements.read.own',
  'gym.body_measurements.create',
  'gym.body_measurements.update.own',
  'gym.body_measurements.delete.own',
  'gym.analytics.read.own'
);

-- Admin / Super Admin: alle Phase-3-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('gym_body_measurements', 'gym_analytics');


-- ============================================================================
-- Sub-Navigation: Körper + Statistik
-- ============================================================================

SET @gym_navigations_id = (
  SELECT navigations_id
  FROM mbc_navigations
  WHERE route = '/gym' AND parent_id IS NULL
  LIMIT 1
);

INSERT IGNORE INTO `mbc_navigations` (
  `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  @gym_navigations_id, 'Körper', 'user--profile', '/gym/body', 6, 1
);

INSERT IGNORE INTO `mbc_navigations` (
  `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  @gym_navigations_id, 'Statistik', 'analytics', '/gym/analytics', 7, 1
);


INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route IN ('/gym/body', '/gym/analytics')
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.4.0', 'Phase 3 — Body-Measurements + Analytics-Permissions')
ON DUPLICATE KEY UPDATE
  version = '0.4.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Phase 3 — Body-Measurements + Analytics-Permissions';
