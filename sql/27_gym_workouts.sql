-- ============================================================================
-- MBC Gym Module - Workouts + Sets (Phase 1 — MVP)
-- ============================================================================
-- Version: 0.2.0
-- Erstellt: 2026-05-03
-- Beschreibung: Workout-Sessions und einzelne Sets als Foundation für
--               Live-Tracking. Setzt 25_gym-schema.sql voraus.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_gym_workouts
-- Beschreibung: Workout-Sessions (eine Session = ein Trainingseinheit)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_workouts (
  workouts_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- User-Zuordnung
  user_id INT UNSIGNED NOT NULL COMMENT 'Besitzer der Session',

  -- Plan-Referenz (Phase 2 — FK wird in Phase 2 angelegt sobald mbc_gym_plan_days existiert)
  plan_day_id INT UNSIGNED DEFAULT NULL COMMENT 'Optional: Referenz auf einen Plan-Tag (Phase 2)',

  -- Zeitstempel der Session
  started_at DATETIME NOT NULL COMMENT 'Workout-Beginn',
  ended_at DATETIME DEFAULT NULL COMMENT 'NULL = noch aktive Session',

  -- Optionale Metadaten
  name VARCHAR(120) DEFAULT NULL COMMENT 'Optional: Name (z.B. "Push Day A")',
  notes TEXT DEFAULT NULL COMMENT 'Freitextnotizen',
  body_weight_kg DECIMAL(5,2) DEFAULT NULL COMMENT 'Optional: Körpergewicht zum Workout-Zeitpunkt',

  -- Server-berechnete Werte (beim End-Endpoint befüllt)
  total_volume_kg DECIMAL(10,2) DEFAULT NULL COMMENT 'Σ (reps × weight_kg) aller non-warmup Sets',
  duration_seconds INT UNSIGNED DEFAULT NULL COMMENT 'ended_at - started_at in Sekunden',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_gym_workout_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_gym_workout_user_id (user_id),
  INDEX idx_gym_workout_started_at (started_at),
  INDEX idx_gym_workout_user_started (user_id, started_at),
  INDEX idx_gym_workout_active (user_id, ended_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Workout-Sessions (Gym-Modul, Phase 1)';


-- ============================================================================
-- Tabelle: mbc_gym_workout_sets
-- Beschreibung: Einzelne Sets innerhalb eines Workouts
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_workout_sets (
  workout_sets_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- User-Zuordnung (denormalisiert für RLS-Filter ohne JOIN)
  user_id INT UNSIGNED NOT NULL COMMENT 'Besitzer (matched workouts.user_id)',

  -- Beziehungen
  workout_id INT UNSIGNED NOT NULL COMMENT 'Zugehöriges Workout',
  exercise_id INT UNSIGNED NOT NULL COMMENT 'Geübte Übung',

  -- Reihenfolge innerhalb (workout_id, exercise_id)
  set_index INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1..n innerhalb derselben Übung im Workout',

  -- Mess-Werte (je nach Exercise.measurement_type unterschiedlich befüllt)
  reps INT UNSIGNED DEFAULT NULL COMMENT 'Wiederholungen',
  weight_kg DECIMAL(7,2) DEFAULT NULL COMMENT 'Gewicht in kg',
  time_seconds INT UNSIGNED DEFAULT NULL COMMENT 'Zeit-basierte Übungen (Plank, Wall-Sit)',
  distance_m DECIMAL(8,2) DEFAULT NULL COMMENT 'Distanz für Cardio/Walks',
  rpe DECIMAL(3,1) DEFAULT NULL COMMENT 'Rate of Perceived Exertion (1.0..10.0)',

  -- Flags
  is_warmup TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Warmup-Set (zählt nicht ins Volumen)',
  is_failure TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Bis zum Muskelversagen',

  -- Optional
  notes VARCHAR(255) DEFAULT NULL COMMENT 'Kurz-Notiz pro Set',
  performed_at DATETIME NOT NULL COMMENT 'Zeitpunkt der Set-Ausführung',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_gym_set_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_set_workout
    FOREIGN KEY (workout_id)
    REFERENCES mbc_gym_workouts(workouts_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_set_exercise
    FOREIGN KEY (exercise_id)
    REFERENCES mbc_gym_exercises(exercises_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_gym_set_user_id (user_id),
  INDEX idx_gym_set_workout_id (workout_id),
  INDEX idx_gym_set_exercise_id (exercise_id),
  INDEX idx_gym_set_workout_exercise (workout_id, exercise_id, set_index),
  INDEX idx_gym_set_performed_at (performed_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Einzelne Sets pro Workout (Gym-Modul, Phase 1)';


-- ============================================================================
-- Phase-1 Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Workout Permissions
('gym.workouts.read.own',       'gym_workouts',     'read',   'own',  'Eigene Workouts anzeigen'),
('gym.workouts.read.any',       'gym_workouts',     'read',   'any',  'Beliebige Workouts anzeigen (Admin)'),
('gym.workouts.create',         'gym_workouts',     'create', NULL,   'Workouts starten'),
('gym.workouts.update.own',     'gym_workouts',     'update', 'own',  'Eigene Workouts bearbeiten (Notes, End)'),
('gym.workouts.delete.own',     'gym_workouts',     'delete', 'own',  'Eigene Workouts löschen'),

-- Workout-Set Permissions
('gym.workout_sets.read.own',   'gym_workout_sets', 'read',   'own',  'Eigene Sets anzeigen'),
('gym.workout_sets.create',     'gym_workout_sets', 'create', NULL,   'Sets erfassen'),
('gym.workout_sets.update.own', 'gym_workout_sets', 'update', 'own',  'Eigene Sets bearbeiten'),
('gym.workout_sets.delete.own', 'gym_workout_sets', 'delete', 'own',  'Eigene Sets löschen');


-- User: Eigene Workouts/Sets verwalten
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'gym.workouts.read.own',
  'gym.workouts.create',
  'gym.workouts.update.own',
  'gym.workouts.delete.own',
  'gym.workout_sets.read.own',
  'gym.workout_sets.create',
  'gym.workout_sets.update.own',
  'gym.workout_sets.delete.own'
);

-- Moderator: identisch zu User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'gym.workouts.read.own',
  'gym.workouts.create',
  'gym.workouts.update.own',
  'gym.workouts.delete.own',
  'gym.workout_sets.read.own',
  'gym.workout_sets.create',
  'gym.workout_sets.update.own',
  'gym.workout_sets.delete.own'
);

-- Admin / Super Admin: alle Phase-1 Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('gym_workouts', 'gym_workout_sets');


-- ============================================================================
-- Sub-Navigation für Gym (Workouts/Übungen/Historie/Statistik-Platzhalter)
-- ============================================================================

SET @gym_navigations_id = (
  SELECT navigations_id
  FROM mbc_navigations
  WHERE route = '/gym' AND parent_id IS NULL
  LIMIT 1
);

-- Sub: Aktives Workout (Schnellzugriff)
INSERT IGNORE INTO `mbc_navigations` (
  `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  @gym_navigations_id, 'Aktives Workout', 'play--filled--alt', '/gym/workouts/active', 1, 1
);

-- Sub: Workout-Historie
INSERT IGNORE INTO `mbc_navigations` (
  `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  @gym_navigations_id, 'Workouts', 'list', '/gym/workouts', 2, 1
);

-- Sub: Übungs-Katalog
INSERT IGNORE INTO `mbc_navigations` (
  `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  @gym_navigations_id, 'Übungen', 'category', '/gym/exercises', 3, 1
);


-- Sub-Navigation an alle Gym-Rollen
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route LIKE '/gym/%'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.2.0', 'Phase 1 — Workouts + Sets')
ON DUPLICATE KEY UPDATE
  version = '0.2.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Phase 1 — Workouts + Sets';

-- ============================================================================
-- Ende — Phase 1 Schema
-- ============================================================================
