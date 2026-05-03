-- ============================================================================
-- MBC Navigation - Lager-Statistiken Route reaktivieren
-- ============================================================================
-- Erstellt: 2026-05-03
-- Quelle: docs/REFACTORING-NAVIGATION-LAYOUTS.md §3 Item #16
--
-- Beschreibung: id 37 (/warehouse/statistics) wieder aktivieren, nachdem die
-- Route in projects/warehouse/.../warehouse.routes.ts implementiert wurde
-- (StatisticsHomeComponent mit Dashboard-Layout).
--
-- Voraussetzung:
--   - 19_navigation_cleanup.sql bereits ausgeführt
--     (Tipfehler /warehaouse/ → /warehouse/, sort_order 4 → 60, is_active 0)
--   - WAREHOUSE_ROUTES enthält Eintrag 'statistics' → StatisticsHomeComponent
-- ============================================================================

START TRANSACTION;

UPDATE `mbc_navigations`
SET `is_active` = 1
WHERE `navigations_id` = 37;

-- mbc_schema_versions.module ist Primary Key → bei Re-Run die Version überschreiben.
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.3.0', 'Reactivate /warehouse/statistics after StatisticsHomeComponent impl')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- Verifizierung:
--   SELECT navigations_id, route, is_active, sort_order FROM mbc_navigations
--   WHERE navigations_id = 37;
--   → erwartet: route=/warehouse/statistics, is_active=1, sort_order=60
