-- ============================================================================
-- MBC - Gym - Struktur
-- ============================================================================
-- Exercise-Catalog, Workouts, Plans/Records, Body, Cardio/Nutrition, Phase-4.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- >>> aus: 25_gym-schema.sql [struct-Teil] ------------------------------------------------------------
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


-- >>> aus: 27_gym_workouts.sql [struct-Teil] ------------------------------------------------------------
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


-- >>> aus: 28_gym_plans_records.sql [struct-Teil] ------------------------------------------------------------
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


-- >>> aus: 29_gym_body.sql [struct-Teil] ------------------------------------------------------------
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
  height_cm      DECIMAL(5,2) DEFAULT NULL,
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


-- >>> aus: 30_gym_cardio_nutrition.sql [struct-Teil] ------------------------------------------------------------
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


-- >>> aus: 31_gym_phase4.sql [struct-Teil] ------------------------------------------------------------
-- ============================================================================
-- MBC Gym Module - Equipment-Link + Coach-Rolle (Phase 4)
-- ============================================================================
-- Version: 0.6.0
-- Erstellt: 2026-05-06
-- Beschreibung: Übungen können auf Warehouse-Items als Equipment-Referenz
--               verlinken. Neue Rolle gym_coach kann Pläne an User zuweisen.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- FK von mbc_gym_exercises.equipment_article_id auf mbc_warehouse_items.items_id
-- mbc_warehouse_items.items_id ist INT UNSIGNED — die referenzierende Spalte
-- MUSS exakt matchen, sonst MariaDB/MySQL-Fehler 1005 (errno 150). Frühere
-- Versionen von 25_gym-schema.sql legten die Spalte fälschlich als signed INT
-- an; das MODIFY unten repariert solche Bestands-Installs idempotent.
-- Idempotent durch DROP IF EXISTS davor.
-- ============================================================================

ALTER TABLE mbc_gym_exercises
  MODIFY COLUMN equipment_article_id INT UNSIGNED DEFAULT NULL;

ALTER TABLE mbc_gym_exercises
  DROP FOREIGN KEY IF EXISTS fk_gym_exercise_equipment;

ALTER TABLE mbc_gym_exercises
  ADD CONSTRAINT fk_gym_exercise_equipment
    FOREIGN KEY (equipment_article_id)
    REFERENCES mbc_warehouse_items(items_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;


-- ============================================================================
-- Tabelle: mbc_gym_plan_assignments
-- Beschreibung: Coach weist einem User einen Plan zu.
--   - assignee_user_id: der trainierende User
--   - assigned_by_user_id: der Coach (oder selbst-Zuweisung wenn = assignee)
--   - status: pending → accepted → completed | declined
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_plan_assignments (
  plan_assignments_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  assignee_user_id    INT UNSIGNED NOT NULL,
  assigned_by_user_id INT UNSIGNED NOT NULL,
  plan_id             INT UNSIGNED NOT NULL,

  status ENUM('pending','accepted','declined','completed') NOT NULL DEFAULT 'pending',
  message TEXT DEFAULT NULL COMMENT 'Optional: Coach-Nachricht beim Zuweisen',
  start_at DATE DEFAULT NULL COMMENT 'Optional: Wann der User starten soll',

  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME DEFAULT NULL COMMENT 'Wann assignee accepted/declined hat',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_assign_assignee
    FOREIGN KEY (assignee_user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_assign_coach
    FOREIGN KEY (assigned_by_user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_assign_plan
    FOREIGN KEY (plan_id)
    REFERENCES mbc_gym_plans(plans_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Pro (Coach, Plan, Assignee) gibt es nur eine aktive Assignment.
  -- Wenn ein User dieselbe Plan-Assignment "neu" bekommt, soll der bestehende
  -- Eintrag UPDATEd werden (Status zurück auf pending) statt dupliziert.
  UNIQUE KEY uniq_gym_assign (assigned_by_user_id, plan_id, assignee_user_id),

  INDEX idx_gym_assign_assignee (assignee_user_id),
  INDEX idx_gym_assign_coach (assigned_by_user_id),
  INDEX idx_gym_assign_plan (plan_id),
  INDEX idx_gym_assign_status (status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plan-Zuweisungen Coach → User (Gym-Modul, Phase 4)';


-- ============================================================================
-- Rolle: gym_coach
-- ============================================================================


COMMIT;


-- >>> aus: 48_gym_primary_muscles.sql ----------------------------------------------------------------
-- =====================================================================
-- 48_gym_primary_muscles.sql
-- Gym — mehrere Primärmuskeln pro Übung
--
-- Bisher hielt `primary_muscle VARCHAR(40)` genau einen Muskel. Die neue
-- JSON-Spalte `primary_muscles` nimmt die vollständige Liste auf — analog zu
-- `secondary_muscles`, das es schon gibt.
--
-- `primary_muscle` BLEIBT bestehen und führt weiterhin den ersten Eintrag:
-- get-gym-analytics.php gruppiert darüber (COALESCE mit category.muscle_group)
-- und get-gym-personal-records/-plans liefern ihn denormalisiert mit. Ein
-- Backfill füllt die neue Spalte aus dem Altbestand.
--
-- Voraussetzung: mbc_gym_exercises (oben in dieser Datei).
-- Idempotent: ADD COLUMN IF NOT EXISTS + UPDATE nur auf NULL-Zeilen.
-- =====================================================================

START TRANSACTION;

ALTER TABLE `mbc_gym_exercises`
  ADD COLUMN IF NOT EXISTS `primary_muscles` JSON DEFAULT NULL
    COMMENT 'Primär beanspruchte Muskeln (Array); primary_muscle = erster Eintrag'
    AFTER `primary_muscle`;

-- Backfill: bestehende Einzelwerte in die Liste heben.
UPDATE `mbc_gym_exercises`
   SET `primary_muscles` = JSON_ARRAY(`primary_muscle`)
 WHERE `primary_muscles` IS NULL
   AND `primary_muscle` IS NOT NULL
   AND `primary_muscle` <> '';

COMMIT;


-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.7.0', 'Phase 4 — Equipment-Link + Coach-Rolle; Multi-Primärmuskeln')
ON DUPLICATE KEY UPDATE
  version = '0.7.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Phase 4 — Equipment-Link + Coach-Rolle; Multi-Primärmuskeln';

