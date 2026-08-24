-- ============================================================================
-- MBC Gym Module - Körpermaße: Körpergröße
-- ============================================================================
-- Version: 0.8.1
-- Erstellt: 2026-08-24
-- Beschreibung: Ergänzt mbc_gym_body_measurements um `height_cm` für die
--               neue Körper-Seite (Maß-Erfassung auf der Körpergrafik).
-- Idempotent: kann mehrfach ausgeführt werden (IF NOT EXISTS, MariaDB).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE mbc_gym_body_measurements
  ADD COLUMN IF NOT EXISTS height_cm DECIMAL(5,2) DEFAULT NULL
  COMMENT 'Körpergröße in cm'
  AFTER weight_kg;

COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.8.1', 'Body — height_cm für Maß-Erfassung auf der Körpergrafik')
ON DUPLICATE KEY UPDATE
  version = '0.8.1',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Body — height_cm für Maß-Erfassung auf der Körpergrafik';
