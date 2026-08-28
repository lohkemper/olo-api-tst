-- ============================================================================
-- MBC Gym Module - Trainings-Split am Plan-Tag
-- ============================================================================
-- Version: 0.9.0
-- Erstellt: 2026-08-26
-- Beschreibung: Ergänzt mbc_gym_plan_days um `split` (push/pull/legs/upper/
--               lower/full) für das Split-Segment + die farbcodierten Badges
--               der Workouts-History (Handoff AREA-gym, ui.md §2).
--               Workouts erben den Split über plan_day_id; freie Workouts
--               leiten ihn client-seitig aus den trainierten Muskelgruppen ab.
-- Idempotent: kann mehrfach ausgeführt werden (IF NOT EXISTS, MariaDB).
-- VARCHAR statt ENUM — gleiche Konvention wie warehouse_locations.type.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE mbc_gym_plan_days
  ADD COLUMN IF NOT EXISTS split VARCHAR(20) DEFAULT NULL
  COMMENT 'Trainings-Split: push | pull | legs | upper | lower | full'
  AFTER name;

COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.9.0', 'Plan-Days — split-Feld für Workouts-Split-Segment')
ON DUPLICATE KEY UPDATE
  version = '0.9.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Plan-Days — split-Feld für Workouts-Split-Segment';
