-- ============================================================================
-- MBC Gym Module - Plans + Personal Records (Phase 2)
-- ============================================================================
-- Version: 0.3.0
-- Erstellt: 2026-05-03
-- Beschreibung: Trainingspläne (Plan → Days → Exercises mit Soll-Werten) und
--               server-berechnete Personal Records. Setzt Phase-1-Schemas voraus.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_gym_plans
-- Beschreibung: Trainingsplan-Templates (Wochen-Splits, Programme)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_plans (
  plans_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Besitzer des Plans',

  name VARCHAR(120) NOT NULL COMMENT 'Plan-Name (z.B. "Push/Pull/Legs 6er-Split")',
  description TEXT DEFAULT NULL,
  goal ENUM('strength','hypertrophy','endurance','general','cut','bulk') NOT NULL DEFAULT 'general'
    COMMENT 'Trainingsziel',
  weeks INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unbegrenzt zyklisch, sonst Wochen-Anzahl',
  is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Aktuell verfolgter Plan (max. 1 pro User per UI-Logik)',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_plan_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_gym_plan_user_id (user_id),
  INDEX idx_gym_plan_active (user_id, is_active)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trainingspläne (Gym-Modul, Phase 2)';


-- ============================================================================
-- Tabelle: mbc_gym_plan_days
-- Beschreibung: Tage innerhalb eines Plans (z.B. "Push", "Pull", "Legs")
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_plan_days (
  plan_days_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Denormalisiert für RLS-Filter',
  plan_id INT UNSIGNED NOT NULL,

  day_index INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1..n Reihenfolge im Plan',
  name VARCHAR(80) NOT NULL COMMENT 'Tag-Name (z.B. "Push Day A")',
  notes TEXT DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_plan_day_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_plan_day_plan
    FOREIGN KEY (plan_id)
    REFERENCES mbc_gym_plans(plans_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_gym_plan_day_user_id (user_id),
  INDEX idx_gym_plan_day_plan_id (plan_id),
  INDEX idx_gym_plan_day_order (plan_id, day_index)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tage innerhalb eines Trainingsplans (Gym-Modul, Phase 2)';


-- ============================================================================
-- Tabelle: mbc_gym_plan_exercises
-- Beschreibung: Übungen pro Plan-Tag mit Soll-Werten
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_plan_exercises (
  plan_exercises_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Denormalisiert für RLS-Filter',
  plan_day_id INT UNSIGNED NOT NULL,
  exercise_id INT UNSIGNED NOT NULL,

  order_index INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1..n Reihenfolge im Tag',
  target_sets INT UNSIGNED DEFAULT NULL COMMENT 'Soll-Anzahl Sets',
  target_reps_min INT UNSIGNED DEFAULT NULL,
  target_reps_max INT UNSIGNED DEFAULT NULL,
  target_weight_kg DECIMAL(7,2) DEFAULT NULL,
  target_rpe DECIMAL(3,1) DEFAULT NULL,
  rest_seconds INT UNSIGNED DEFAULT NULL COMMENT 'Pausentimer-Default für diese Übung',
  notes TEXT DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_plan_ex_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_plan_ex_day
    FOREIGN KEY (plan_day_id)
    REFERENCES mbc_gym_plan_days(plan_days_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_plan_ex_exercise
    FOREIGN KEY (exercise_id)
    REFERENCES mbc_gym_exercises(exercises_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

  INDEX idx_gym_plan_ex_user_id (user_id),
  INDEX idx_gym_plan_ex_day_id (plan_day_id),
  INDEX idx_gym_plan_ex_exercise_id (exercise_id),
  INDEX idx_gym_plan_ex_order (plan_day_id, order_index)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Übungen pro Plan-Tag mit Soll-Werten (Gym-Modul, Phase 2)';


-- ============================================================================
-- Tabelle: mbc_gym_personal_records
-- Beschreibung: Server-berechnete Bestleistungen pro (User, Übung, Typ)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_personal_records (
  personal_records_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL,
  exercise_id INT UNSIGNED NOT NULL,

  record_type ENUM(
    '1rm_estimated',     -- Epley-Formel: weight × (1 + reps/30)
    'volume_set',        -- Maximales reps × weight in einem einzelnen Set
    'volume_workout',    -- Maximale Summe (reps × weight) pro Übung in einem Workout
    'reps_at_weight',    -- Maximale Reps bei einem bestimmten Gewicht
    'max_distance',      -- für Cardio/Walks
    'max_time'           -- für Plank/Wall-Sit
  ) NOT NULL,

  value DECIMAL(10,2) NOT NULL COMMENT 'Wert je nach record_type (kg, kg, kg, reps, m, sek)',
  reference_weight_kg DECIMAL(7,2) DEFAULT NULL
    COMMENT 'Nur für reps_at_weight: das Gewicht zu dem die Reps gehören',

  workout_set_id INT UNSIGNED DEFAULT NULL COMMENT 'Quelle-Set (NULL bei volume_workout)',
  workout_id INT UNSIGNED DEFAULT NULL COMMENT 'Quelle-Workout',
  achieved_at DATETIME NOT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_pr_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_pr_exercise
    FOREIGN KEY (exercise_id)
    REFERENCES mbc_gym_exercises(exercises_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_pr_workout
    FOREIGN KEY (workout_id)
    REFERENCES mbc_gym_workouts(workouts_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_pr_set
    FOREIGN KEY (workout_set_id)
    REFERENCES mbc_gym_workout_sets(workout_sets_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  -- Pro (User, Übung, Typ, optionalem Referenz-Gewicht) gibt es genau einen Rekord.
  -- COALESCE-Trick auf reference_weight_kg via generated column wäre cleaner;
  -- die simple Variante mit NULL-tolerantem UNIQUE reicht für unsere Volumen.
  UNIQUE KEY uniq_gym_pr (user_id, exercise_id, record_type, reference_weight_kg),

  INDEX idx_gym_pr_user_exercise (user_id, exercise_id),
  INDEX idx_gym_pr_achieved_at (achieved_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Personal Records (Gym-Modul, Phase 2)';


-- ============================================================================
-- Späte FK auf mbc_gym_workouts.plan_day_id (Phase 1 hatte die Spalte schon
-- als INT UNSIGNED ohne FK angelegt — jetzt wird die Constraint nachgereicht).
--
-- Idempotent: DDL ist in MariaDB Auto-Commit und kann nicht zurückgerollt
-- werden, daher zuerst die Constraint droppen falls sie aus einem früheren
-- (Teil-)Run bereits existiert. Setzt MariaDB ≥ 10.4 voraus.
-- ============================================================================

ALTER TABLE mbc_gym_workouts
  DROP FOREIGN KEY IF EXISTS fk_gym_workout_plan_day;

ALTER TABLE mbc_gym_workouts
  ADD CONSTRAINT fk_gym_workout_plan_day
    FOREIGN KEY (plan_day_id)
    REFERENCES mbc_gym_plan_days(plan_days_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;


-- ============================================================================
-- Phase-2 Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('gym.plans.read.own',          'gym_plans',          'read',   'own',  'Eigene Pläne anzeigen'),
('gym.plans.read.any',          'gym_plans',          'read',   'any',  'Beliebige Pläne anzeigen (Admin)'),
('gym.plans.create',            'gym_plans',          'create', NULL,   'Pläne erstellen'),
('gym.plans.update.own',        'gym_plans',          'update', 'own',  'Eigene Pläne bearbeiten'),
('gym.plans.delete.own',        'gym_plans',          'delete', 'own',  'Eigene Pläne löschen'),

('gym.plan_days.manage.own',    'gym_plan_days',      'manage', 'own',  'Tage in eigenen Plänen verwalten'),
('gym.plan_exercises.manage.own','gym_plan_exercises', 'manage', 'own', 'Übungen in eigenen Plänen verwalten'),

('gym.personal_records.read.own', 'gym_personal_records', 'read', 'own', 'Eigene Personal Records anzeigen'),
('gym.personal_records.read.any', 'gym_personal_records', 'read', 'any', 'Beliebige PRs anzeigen (Admin)');


-- User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'gym.plans.read.own',
  'gym.plans.create',
  'gym.plans.update.own',
  'gym.plans.delete.own',
  'gym.plan_days.manage.own',
  'gym.plan_exercises.manage.own',
  'gym.personal_records.read.own'
);

-- Moderator: identisch zu User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'gym.plans.read.own',
  'gym.plans.create',
  'gym.plans.update.own',
  'gym.plans.delete.own',
  'gym.plan_days.manage.own',
  'gym.plan_exercises.manage.own',
  'gym.personal_records.read.own'
);

-- Admin / Super Admin: alle Phase-2-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('gym_plans', 'gym_plan_days', 'gym_plan_exercises', 'gym_personal_records');


-- ============================================================================
-- Sub-Navigation: Pläne + Records
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
  @gym_navigations_id, 'Pläne', 'calendar', '/gym/plans', 4, 1
);

INSERT IGNORE INTO `mbc_navigations` (
  `parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`
) VALUES (
  @gym_navigations_id, 'Records', 'trophy', '/gym/records', 5, 1
);


-- Rollen-Zuordnung für die neuen Sub-Nav-Einträge
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route IN ('/gym/plans', '/gym/records')
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.3.0', 'Phase 2 — Plans + Personal Records')
ON DUPLICATE KEY UPDATE
  version = '0.3.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Phase 2 — Plans + Personal Records';
