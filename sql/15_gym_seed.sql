-- ============================================================================
-- MBC - Gym - Seed
-- ============================================================================
-- Exercise-Katalog + Foods-Katalog + Permissions/Nav aller Gym-Phasen.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- Exercise-Katalog: Kategorien + Übungen
START TRANSACTION;
INSERT IGNORE INTO mbc_gym_exercise_categories (name, muscle_group, parent_id, user_id) VALUES
  ('Brust',      'chest',     NULL, NULL),
  ('Rücken',     'back',      NULL, NULL),
  ('Beine',      'legs',      NULL, NULL),
  ('Schulter',   'shoulders', NULL, NULL),
  ('Arme',       'arms',      NULL, NULL),
  ('Core',       'core',      NULL, NULL),
  ('Cardio',     'cardio',    NULL, NULL),
  ('Ganzkörper', 'full_body', NULL, NULL);


-- ============================================================================
-- Seed: 30 Standard-Übungen (System-Templates, is_template = 1)
-- ============================================================================

-- Kategorie-IDs lookup-Variablen
SET @cat_chest     = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'chest'     AND user_id IS NULL LIMIT 1);
SET @cat_back      = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'back'      AND user_id IS NULL LIMIT 1);
SET @cat_legs      = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'legs'      AND user_id IS NULL LIMIT 1);
SET @cat_shoulders = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'shoulders' AND user_id IS NULL LIMIT 1);
SET @cat_arms      = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'arms'      AND user_id IS NULL LIMIT 1);
SET @cat_core      = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'core'      AND user_id IS NULL LIMIT 1);
SET @cat_cardio    = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'cardio'    AND user_id IS NULL LIMIT 1);
SET @cat_full      = (SELECT exercise_categories_id FROM mbc_gym_exercise_categories WHERE muscle_group = 'full_body' AND user_id IS NULL LIMIT 1);

INSERT IGNORE INTO mbc_gym_exercises
  (user_id, name, slug, category_id, primary_muscle, exercise_type, measurement_type, is_template) VALUES

  -- Brust
  (NULL, 'Bankdrücken Langhantel',          'bankdruecken-langhantel',     @cat_chest, 'chest', 'strength', 'weight_reps', 1),
  (NULL, 'Schrägbankdrücken Langhantel',    'schraegbankdruecken-lh',      @cat_chest, 'chest', 'strength', 'weight_reps', 1),
  (NULL, 'Bankdrücken Kurzhantel',          'bankdruecken-kurzhantel',     @cat_chest, 'chest', 'strength', 'weight_reps', 1),
  (NULL, 'Liegestütze',                      'liegestuetze',                @cat_chest, 'chest', 'bodyweight', 'reps_only', 1),
  (NULL, 'Butterfly',                        'butterfly',                   @cat_chest, 'chest', 'strength', 'weight_reps', 1),

  -- Rücken
  (NULL, 'Klimmzug',                         'klimmzug',                    @cat_back, 'back', 'bodyweight', 'reps_only', 1),
  (NULL, 'Latziehen breit',                  'latziehen-breit',             @cat_back, 'back', 'strength', 'weight_reps', 1),
  (NULL, 'Rudern Langhantel',                'rudern-langhantel',           @cat_back, 'back', 'strength', 'weight_reps', 1),
  (NULL, 'Rudern Kabelzug',                  'rudern-kabelzug',             @cat_back, 'back', 'strength', 'weight_reps', 1),
  (NULL, 'Kreuzheben',                       'kreuzheben',                  @cat_back, 'back', 'strength', 'weight_reps', 1),

  -- Beine
  (NULL, 'Kniebeuge Langhantel',             'kniebeuge-langhantel',        @cat_legs, 'quadriceps', 'strength', 'weight_reps', 1),
  (NULL, 'Front-Squat',                      'front-squat',                 @cat_legs, 'quadriceps', 'strength', 'weight_reps', 1),
  (NULL, 'Beinpresse',                       'beinpresse',                  @cat_legs, 'quadriceps', 'strength', 'weight_reps', 1),
  (NULL, 'Ausfallschritte',                  'ausfallschritte',             @cat_legs, 'quadriceps', 'strength', 'weight_reps', 1),
  (NULL, 'Beinbeuger liegend',               'beinbeuger-liegend',          @cat_legs, 'hamstrings', 'strength', 'weight_reps', 1),
  (NULL, 'Wadenheben stehend',               'wadenheben-stehend',          @cat_legs, 'calves',     'strength', 'weight_reps', 1),

  -- Schulter
  (NULL, 'Schulterdrücken Langhantel',       'schulterdruecken-langhantel', @cat_shoulders, 'shoulders', 'strength', 'weight_reps', 1),
  (NULL, 'Schulterdrücken Kurzhantel',       'schulterdruecken-kurzhantel', @cat_shoulders, 'shoulders', 'strength', 'weight_reps', 1),
  (NULL, 'Seitheben',                         'seitheben',                   @cat_shoulders, 'shoulders', 'strength', 'weight_reps', 1),
  (NULL, 'Frontheben',                        'frontheben',                  @cat_shoulders, 'shoulders', 'strength', 'weight_reps', 1),
  (NULL, 'Reverse Fly',                       'reverse-fly',                 @cat_shoulders, 'rear_delts','strength', 'weight_reps', 1),

  -- Arme
  (NULL, 'Bizepscurl Langhantel',            'bizepscurl-langhantel',       @cat_arms, 'biceps', 'strength', 'weight_reps', 1),
  (NULL, 'Bizepscurl Kurzhantel',            'bizepscurl-kurzhantel',       @cat_arms, 'biceps', 'strength', 'weight_reps', 1),
  (NULL, 'Trizepsdrücken Kabel',             'trizepsdruecken-kabel',       @cat_arms, 'triceps','strength', 'weight_reps', 1),
  (NULL, 'Dips',                              'dips',                        @cat_arms, 'triceps','bodyweight', 'reps_only', 1),

  -- Core
  (NULL, 'Plank',                             'plank',                       @cat_core, 'core', 'bodyweight', 'time', 1),
  (NULL, 'Crunches',                          'crunches',                    @cat_core, 'core', 'bodyweight', 'reps_only', 1),
  (NULL, 'Hängendes Beinheben',              'haengendes-beinheben',        @cat_core, 'core', 'bodyweight', 'reps_only', 1),

  -- Cardio
  (NULL, 'Laufen',                            'laufen',                      @cat_cardio, 'cardio', 'cardio', 'distance', 1),
  (NULL, 'Radfahren',                         'radfahren',                   @cat_cardio, 'cardio', 'cardio', 'distance', 1),
  (NULL, 'Rudern Ergometer',                  'rudern-ergometer',            @cat_cardio, 'cardio', 'cardio', 'distance', 1);


COMMIT;


-- ============================================================================
-- MBC Gym Module - Permissions & Navigation Seed
-- ============================================================================
-- Version: 0.1.0
-- Erstellt: 2026-05-03
-- Beschreibung: Berechtigungen und Top-Level-Navigation für das Gym-Modul.
--               Setzt 14_gym_structure.sql voraus.
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


-- Workouts: Permissions
START TRANSACTION;
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


-- Pläne & Records: Permissions
START TRANSACTION;
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


-- Körpermaße: Permissions
START TRANSACTION;
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


-- Foods-Katalog + Cardio/Nutrition-Permissions/Nav
START TRANSACTION;
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


-- Phase 4: Coach-Rolle + Permissions
START TRANSACTION;
INSERT IGNORE INTO `mbc_roles` (`name`, `display_name`, `description`)
VALUES ('gym_coach', 'Gym Coach', 'Kann Pläne an andere User zuweisen und deren Fortschritt verfolgen');


-- ============================================================================
-- Phase-4 Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Equipment-bezogene Read-Permissions (Warehouse-Items im Gym-Kontext lesen)
('gym.warehouse_items.read.own',     'gym_warehouse_items',   'read',   'own',  'Eigene Warehouse-Items als Equipment-Optionen lesen'),

-- Plan-Assignment-Permissions
('gym.plan_assignments.read.own',    'gym_plan_assignments',  'read',   'own',  'Eigene zugewiesene Pläne sehen'),
('gym.plan_assignments.read.coach',  'gym_plan_assignments',  'read',   'coach','Selbst zugewiesene Pläne als Coach sehen'),
('gym.plan_assignments.create',      'gym_plan_assignments',  'create', NULL,   'Pläne anderen Usern zuweisen (Coach-Aktion)'),
('gym.plan_assignments.respond',     'gym_plan_assignments',  'respond','own',  'Zuweisung akzeptieren/ablehnen'),
('gym.plan_assignments.delete.coach','gym_plan_assignments',  'delete', 'coach','Eigene Coach-Zuweisungen zurückziehen');


-- User: Equipment lesen + eigene Assignments lesen + responden
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'gym.warehouse_items.read.own',
  'gym.plan_assignments.read.own',
  'gym.plan_assignments.respond'
);

-- Moderator: identisch zu User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'gym.warehouse_items.read.own',
  'gym.plan_assignments.read.own',
  'gym.plan_assignments.respond'
);

-- gym_coach: Coach-Aktionen + alle User-Permissions zusätzlich
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'gym_coach'
AND p.name IN (
  'gym.warehouse_items.read.own',
  'gym.plan_assignments.read.own',
  'gym.plan_assignments.read.coach',
  'gym.plan_assignments.create',
  'gym.plan_assignments.respond',
  'gym.plan_assignments.delete.coach',
  -- Coach hat auch volle Plan-Lese-/Schreibrechte (vererbt sonst von 'user')
  'gym.plans.read.own',
  'gym.plans.create',
  'gym.plans.update.own',
  'gym.plans.delete.own',
  'gym.plan_days.manage.own',
  'gym.plan_exercises.manage.own'
);

-- Admin / Super Admin: alle Phase-4-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('gym_warehouse_items', 'gym_plan_assignments');


COMMIT;

