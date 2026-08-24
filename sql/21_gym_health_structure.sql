-- ============================================================================
-- MBC Gym Module - Health / Blutwerte
-- ============================================================================
-- Version: 0.8.0
-- Erstellt: 2026-08-24
-- Beschreibung: Blutwerte als Key-Value-Zeitreihe (eine Zeile pro
--               User+Tag+Messwert). Der Messwert-Katalog (Labels, Einheiten,
--               Referenzbereiche) lebt im Frontend
--               (projects/gym/.../blood-metric.catalog.ts) — neue Werte
--               brauchen KEINE Schema-Migration.
-- Idempotent: kann mehrfach ausgeführt werden.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_gym_blood_values
-- Beschreibung: Blutwerte tagesgenau, Key-Value (metric = Katalog-Key)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_blood_values (
  blood_values_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL,

  measured_at DATE NOT NULL COMMENT 'Tag der Messung / des Laborbefunds',

  metric VARCHAR(40) NOT NULL COMMENT 'Katalog-Key, z.B. hemoglobin, hba1c, gamma_gt',
  value  DECIMAL(10,3) NOT NULL COMMENT 'Messwert in der Katalog-Einheit',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_blood_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Ein Wert pro Messwert pro Tag pro User (überschreiben statt duplizieren)
  UNIQUE KEY uniq_gym_blood_user_day_metric (user_id, measured_at, metric),

  INDEX idx_gym_blood_user_id (user_id),
  -- Chart-Query: Verlauf eines Messwerts über die Zeit
  INDEX idx_gym_blood_user_metric_day (user_id, metric, measured_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Blutwerte tagesgenau als Key-Value (Gym-Modul, Health)';


-- ============================================================================
-- Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('gym.blood_values.read.own',   'gym_blood_values', 'read',   'own',  'Eigene Blutwerte anzeigen'),
('gym.blood_values.read.any',   'gym_blood_values', 'read',   'any',  'Beliebige Blutwerte anzeigen (Admin)'),
('gym.blood_values.create',     'gym_blood_values', 'create', NULL,   'Blutwerte erfassen'),
('gym.blood_values.update.own', 'gym_blood_values', 'update', 'own',  'Eigene Blutwerte bearbeiten'),
('gym.blood_values.delete.own', 'gym_blood_values', 'delete', 'own',  'Eigene Blutwerte löschen');

-- User + Moderator: eigene Werte voll verwalten
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('user', 'moderator')
AND p.name IN (
  'gym.blood_values.read.own',
  'gym.blood_values.create',
  'gym.blood_values.update.own',
  'gym.blood_values.delete.own'
);

-- Admin / Super Admin: alle Health-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource = 'gym_blood_values';


-- ============================================================================
-- Sub-Navigation: Gesundheit (/gym/health)
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
  @gym_navigations_id, 'Gesundheit', 'activity', '/gym/health', 8, 1
);

-- Mega-Menü-Sub-Text (Spalte description, siehe 04_navigation_seed.sql)
UPDATE `mbc_navigations` SET `description` = 'Blutwerte & Laborwerte im Verlauf'
  WHERE `route` = '/gym/health';

INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route = '/gym/health'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.8.0', 'Health — Blutwerte (Key-Value-Zeitreihe + Nav + Permissions)')
ON DUPLICATE KEY UPDATE
  version = '0.8.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Health — Blutwerte (Key-Value-Zeitreihe + Nav + Permissions)';
