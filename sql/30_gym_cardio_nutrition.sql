-- ============================================================================
-- MBC Gym Module - Cardio + Nutrition (Phase 5)
-- ============================================================================
-- Version: 0.5.0
-- Erstellt: 2026-05-06
-- Beschreibung: Cardio-Sessions (Lauf/Rad/Schwimm/Rudern) und Ernährung
--               (Lebensmittel-Katalog + tagesgenaue Mahlzeiten).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_gym_cardio_sessions
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_cardio_sessions (
  cardio_sessions_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL,

  activity_type ENUM(
    'running', 'cycling', 'swimming', 'rowing',
    'walking', 'hiking', 'elliptical', 'other'
  ) NOT NULL,

  started_at DATETIME NOT NULL,
  duration_seconds INT UNSIGNED NOT NULL COMMENT 'Gesamtdauer in Sekunden',

  distance_m DECIMAL(10,2) DEFAULT NULL COMMENT 'Distanz in Metern',
  avg_heartrate INT UNSIGNED DEFAULT NULL COMMENT 'Durchschnitts-Puls bpm',
  max_heartrate INT UNSIGNED DEFAULT NULL COMMENT 'Maximum-Puls bpm',
  calories_kcal INT UNSIGNED DEFAULT NULL,
  avg_pace_sec_per_km INT UNSIGNED DEFAULT NULL COMMENT 'Pace s/km (wird beim POST/PUT auto-berechnet wenn distance_m + duration vorhanden)',
  elevation_gain_m INT UNSIGNED DEFAULT NULL,

  source ENUM('manual','apple_health','google_fit','garmin') NOT NULL DEFAULT 'manual',
  external_id VARCHAR(120) DEFAULT NULL COMMENT 'Dedup-Key aus externen Quellen',

  notes TEXT DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_cardio_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_gym_cardio_user_id (user_id),
  INDEX idx_gym_cardio_started_at (started_at),
  INDEX idx_gym_cardio_user_started (user_id, started_at),
  -- External-Source-Dedup: pro (User, Provider, externe ID) nur eine Session
  UNIQUE KEY uniq_gym_cardio_external (user_id, source, external_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cardio-Sessions (Gym-Modul, Phase 5)';


-- ============================================================================
-- Tabelle: mbc_gym_foods
-- Beschreibung: Lebensmittel-Katalog (System-Templates + eigene Einträge)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_foods (
  foods_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = System-Template',

  name VARCHAR(160) NOT NULL,
  brand VARCHAR(120) DEFAULT NULL,

  serving_size_g DECIMAL(7,2) NOT NULL DEFAULT 100.00 COMMENT 'Standard-Portion in Gramm',

  -- Nährwerte pro 100 g
  kcal_per_100g    DECIMAL(6,2) NOT NULL,
  protein_per_100g DECIMAL(5,2) NOT NULL,
  carbs_per_100g   DECIMAL(5,2) NOT NULL,
  fat_per_100g     DECIMAL(5,2) NOT NULL,
  fiber_per_100g   DECIMAL(5,2) DEFAULT NULL,
  sugar_per_100g   DECIMAL(5,2) DEFAULT NULL,

  barcode VARCHAR(40) DEFAULT NULL COMMENT 'EAN für Barcode-Scan (Phase 5.5)',

  is_template TINYINT(1) NOT NULL DEFAULT 0,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_food_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_gym_food_user_id (user_id),
  INDEX idx_gym_food_name (name),
  INDEX idx_gym_food_template (is_template),
  INDEX idx_gym_food_barcode (barcode)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lebensmittel-Katalog (Gym-Modul, Phase 5)';


-- ============================================================================
-- Tabelle: mbc_gym_nutrition_entries
-- Beschreibung: Konsum-Einträge (Lebensmittel × Menge × Mahlzeit × Zeitpunkt)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_nutrition_entries (
  nutrition_entries_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL,
  food_id INT UNSIGNED NOT NULL,

  meal ENUM('breakfast','lunch','dinner','snack') NOT NULL,
  consumed_at DATETIME NOT NULL,
  amount_g DECIMAL(7,2) NOT NULL COMMENT 'Konsumierte Menge in Gramm',

  notes VARCHAR(255) DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_nutrition_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_nutrition_food
    FOREIGN KEY (food_id)
    REFERENCES mbc_gym_foods(foods_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

  INDEX idx_gym_nutrition_user_id (user_id),
  INDEX idx_gym_nutrition_food_id (food_id),
  INDEX idx_gym_nutrition_consumed_at (consumed_at),
  INDEX idx_gym_nutrition_user_consumed (user_id, consumed_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mahlzeiten-Tagebuch (Gym-Modul, Phase 5)';


-- ============================================================================
-- Seed: 25 Standard-Lebensmittel (System-Templates)
-- ============================================================================

INSERT IGNORE INTO mbc_gym_foods
  (user_id, name, brand, serving_size_g, kcal_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g, fiber_per_100g, is_template)
VALUES
  -- Eiweiß-Quellen
  (NULL, 'Hähnchenbrust (gegart)',  NULL, 100, 165, 31.0,  0.0,  3.6,  0.0, 1),
  (NULL, 'Magerquark',               NULL, 250,  67, 12.0,  4.1,  0.3,  0.0, 1),
  (NULL, 'Hüttenkäse',               NULL, 200,  98, 11.0,  3.4,  4.3,  0.0, 1),
  (NULL, 'Lachs (gegart)',           NULL, 150, 208, 20.0,  0.0, 13.4,  0.0, 1),
  (NULL, 'Thunfisch in Wasser',      NULL, 150, 116, 26.0,  0.0,  1.0,  0.0, 1),
  (NULL, 'Eier (Hühnerei)',          NULL,  60, 155, 13.0,  1.1, 11.0,  0.0, 1),
  (NULL, 'Whey Protein',             NULL,  30, 380, 75.0,  8.0,  6.0,  0.0, 1),

  -- Kohlenhydrate
  (NULL, 'Haferflocken',             NULL,  60, 372, 13.0, 60.0,  7.0, 10.0, 1),
  (NULL, 'Reis (gekocht)',           NULL, 200, 130,  2.7, 28.0,  0.3,  0.4, 1),
  (NULL, 'Vollkornnudeln (gekocht)', NULL, 200, 124,  5.8, 25.0,  0.9,  3.2, 1),
  (NULL, 'Kartoffeln (gekocht)',     NULL, 200,  87,  2.0, 17.0,  0.1,  1.8, 1),
  (NULL, 'Süßkartoffel (gegart)',    NULL, 200,  86,  1.6, 20.0,  0.1,  3.0, 1),
  (NULL, 'Vollkornbrot',             NULL,  40, 247,  8.0, 40.0,  3.5,  7.0, 1),
  (NULL, 'Banane',                   NULL, 120,  89,  1.1, 23.0,  0.3,  2.6, 1),
  (NULL, 'Apfel',                    NULL, 150,  52,  0.3, 14.0,  0.2,  2.4, 1),

  -- Fette
  (NULL, 'Olivenöl',                 NULL,  10, 884,  0.0,  0.0,100.0,  0.0, 1),
  (NULL, 'Mandeln',                  NULL,  30, 579, 21.0, 22.0, 50.0, 12.0, 1),
  (NULL, 'Walnüsse',                 NULL,  30, 654, 15.0, 14.0, 65.0,  7.0, 1),
  (NULL, 'Erdnussbutter',            NULL,  20, 588, 25.0, 20.0, 50.0,  6.0, 1),
  (NULL, 'Avocado',                  NULL, 100, 160,  2.0,  9.0, 15.0,  7.0, 1),

  -- Gemüse
  (NULL, 'Brokkoli (gegart)',        NULL, 200,  35,  2.4,  7.0,  0.4,  3.3, 1),
  (NULL, 'Spinat',                   NULL, 100,  23,  2.9,  3.6,  0.4,  2.2, 1),
  (NULL, 'Tomaten',                  NULL, 100,  18,  0.9,  3.9,  0.2,  1.2, 1),

  -- Getränke
  (NULL, 'Milch 1.5%',               NULL, 200,  47,  3.4,  4.8,  1.5,  0.0, 1),
  (NULL, 'Espresso',                 NULL,  30,   2,  0.1,  0.0,  0.0,  0.0, 1);


-- ============================================================================
-- Phase-5 Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('gym.cardio_sessions.read.own',   'gym_cardio_sessions',   'read',   'own',  'Eigene Cardio-Sessions anzeigen'),
('gym.cardio_sessions.read.any',   'gym_cardio_sessions',   'read',   'any',  'Beliebige Cardio-Sessions anzeigen (Admin)'),
('gym.cardio_sessions.create',     'gym_cardio_sessions',   'create', NULL,   'Cardio-Sessions erfassen'),
('gym.cardio_sessions.update.own', 'gym_cardio_sessions',   'update', 'own',  'Eigene Cardio-Sessions bearbeiten'),
('gym.cardio_sessions.delete.own', 'gym_cardio_sessions',   'delete', 'own',  'Eigene Cardio-Sessions löschen'),

('gym.foods.read.own',             'gym_foods',             'read',   'own',  'Eigene + System-Lebensmittel anzeigen'),
('gym.foods.read.any',             'gym_foods',             'read',   'any',  'Beliebige Lebensmittel anzeigen (Admin)'),
('gym.foods.create',               'gym_foods',             'create', NULL,   'Lebensmittel erstellen'),
('gym.foods.update.own',           'gym_foods',             'update', 'own',  'Eigene Lebensmittel bearbeiten'),
('gym.foods.delete.own',           'gym_foods',             'delete', 'own',  'Eigene Lebensmittel löschen'),

('gym.nutrition_entries.read.own', 'gym_nutrition_entries', 'read',   'own',  'Eigene Mahlzeiten anzeigen'),
('gym.nutrition_entries.create',   'gym_nutrition_entries', 'create', NULL,   'Mahlzeiten erfassen'),
('gym.nutrition_entries.update.own','gym_nutrition_entries','update', 'own',  'Eigene Mahlzeiten bearbeiten'),
('gym.nutrition_entries.delete.own','gym_nutrition_entries','delete', 'own',  'Eigene Mahlzeiten löschen');


-- User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'gym.cardio_sessions.read.own',
  'gym.cardio_sessions.create',
  'gym.cardio_sessions.update.own',
  'gym.cardio_sessions.delete.own',
  'gym.foods.read.own',
  'gym.foods.create',
  'gym.foods.update.own',
  'gym.foods.delete.own',
  'gym.nutrition_entries.read.own',
  'gym.nutrition_entries.create',
  'gym.nutrition_entries.update.own',
  'gym.nutrition_entries.delete.own'
);

-- Moderator: identisch zu User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'gym.cardio_sessions.read.own',
  'gym.cardio_sessions.create',
  'gym.cardio_sessions.update.own',
  'gym.cardio_sessions.delete.own',
  'gym.foods.read.own',
  'gym.foods.create',
  'gym.foods.update.own',
  'gym.foods.delete.own',
  'gym.nutrition_entries.read.own',
  'gym.nutrition_entries.create',
  'gym.nutrition_entries.update.own',
  'gym.nutrition_entries.delete.own'
);

-- Admin / Super Admin
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('gym_cardio_sessions', 'gym_foods', 'gym_nutrition_entries');


-- ============================================================================
-- Sub-Navigation: Cardio + Ernährung
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
  @gym_navigations_id, 'Cardio', 'run', '/gym/cardio', 8, 1
);

INSERT IGNORE INTO `mbc_navigations` (
  `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  @gym_navigations_id, 'Ernährung', 'restaurant', '/gym/nutrition', 9, 1
);

INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route IN ('/gym/cardio', '/gym/nutrition')
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.5.0', 'Phase 5 — Cardio + Nutrition')
ON DUPLICATE KEY UPDATE
  version = '0.5.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Phase 5 — Cardio + Nutrition';
