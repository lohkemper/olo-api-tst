-- ============================================================================
-- MBC Gym Module - Database Schema (Phase 0 — Catalog Foundation)
-- ============================================================================
-- Version: 0.1.0
-- Erstellt: 2026-05-03
-- Beschreibung: Übungs-Katalog und Kategorien als Foundation für das
--               Gym-Modul. Workout-/Plan-/Body-/Cardio-/Nutrition-Tabellen
--               folgen in den späteren Phasen (siehe projects/gym/docs/data-model.md).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_gym_exercise_categories
-- Beschreibung: Übungs-Kategorien (Muskelgruppen, hierarchisch)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_exercise_categories (
  exercise_categories_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Basis-Daten
  name VARCHAR(80) NOT NULL COMMENT 'Anzeige-Name (z.B. "Brust", "Oberer Brustbereich")',
  muscle_group VARCHAR(40) NOT NULL COMMENT 'Normalisiert: chest, back, legs, shoulders, arms, core, cardio, full_body',
  parent_id INT UNSIGNED DEFAULT NULL COMMENT 'Parent-Kategorie (NULL = Root)',

  -- User-Zuordnung (NULL = System-Template, sichtbar für alle)
  user_id INT UNSIGNED DEFAULT NULL COMMENT 'Besitzer (NULL = System-Template)',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_gym_category_parent
    FOREIGN KEY (parent_id)
    REFERENCES mbc_gym_exercise_categories(exercise_categories_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_category_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_gym_cat_user_id (user_id),
  INDEX idx_gym_cat_parent_id (parent_id),
  INDEX idx_gym_cat_muscle_group (muscle_group)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Übungs-Kategorien (Muskelgruppen) für Gym-Modul';


-- ============================================================================
-- Tabelle: mbc_gym_exercises
-- Beschreibung: Übungs-Katalog (eigene + System-Templates)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_exercises (
  exercises_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- User-Zuordnung (NULL = System-Template)
  user_id INT UNSIGNED DEFAULT NULL COMMENT 'Besitzer (NULL = System-Template)',

  -- Basis-Daten
  name VARCHAR(120) NOT NULL COMMENT 'Anzeige-Name (z.B. "Bankdrücken Langhantel")',
  slug VARCHAR(120) NOT NULL COMMENT 'URL-/Identifikations-Slug',

  -- Zuordnung
  category_id INT UNSIGNED DEFAULT NULL COMMENT 'Kategorie/Muskelgruppe',
  equipment_article_id INT UNSIGNED DEFAULT NULL COMMENT 'Optional: Equipment-Referenz auf mbc_warehouse_items (Phase 4, Typ matched mbc_warehouse_items.items_id INT UNSIGNED — FK in 31)',

  -- Klassifikation
  primary_muscle VARCHAR(40) DEFAULT NULL COMMENT 'Primär beanspruchter Muskel',
  secondary_muscles JSON DEFAULT NULL COMMENT 'Sekundär beanspruchte Muskeln (Array)',
  exercise_type ENUM('strength','cardio','mobility','bodyweight') NOT NULL DEFAULT 'strength' COMMENT 'Übungstyp',
  measurement_type ENUM('weight_reps','reps_only','time','distance','weight_time') NOT NULL DEFAULT 'weight_reps' COMMENT 'Mess-Schema beim Logging',

  -- Beschreibung & Medien
  description TEXT DEFAULT NULL COMMENT 'Beschreibung / Anleitung',
  video_url VARCHAR(500) DEFAULT NULL COMMENT 'Tutorial-Video-URL',
  photo_url VARCHAR(500) DEFAULT NULL COMMENT 'Übungs-Foto-URL',

  -- Flags
  is_template TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = System-Template, 0 = User-eigen',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_gym_exercise_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_exercise_category
    FOREIGN KEY (category_id)
    REFERENCES mbc_gym_exercise_categories(exercise_categories_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_gym_ex_user_id (user_id),
  INDEX idx_gym_ex_category_id (category_id),
  INDEX idx_gym_ex_slug (slug),
  INDEX idx_gym_ex_type (exercise_type),
  INDEX idx_gym_ex_template (is_template),
  -- Eindeutigkeit: pro User darf der Slug nur einmal existieren.
  -- NULL-User (System-Templates) sind global eindeutig.
  UNIQUE KEY uniq_gym_ex_user_slug (user_id, slug)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Übungs-Katalog (Gym-Modul, Phase 0)';


-- ============================================================================
-- Seed: System-Kategorien (Muskelgruppen)
-- ============================================================================

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
-- Schema-Version (falls mbc_schema_versions vorhanden)
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.1.0', 'Phase 0 — Catalog Foundation (exercises + categories)')
ON DUPLICATE KEY UPDATE
  version = '0.1.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Phase 0 — Catalog Foundation (exercises + categories)';

-- ============================================================================
-- Ende — Phase 0 Schema
-- ============================================================================
