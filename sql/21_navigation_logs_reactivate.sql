-- ============================================================================
-- MBC Navigation - Logs Route reaktivieren
-- ============================================================================
-- Erstellt: 2026-05-03
-- Quelle: docs/archive/refactoring-navigation-layouts.md §3 Item #8
--
-- Beschreibung: id 13 (/admin/logs) wieder aktivieren, nachdem die Route
-- in src/app/app.routes.ts implementiert wurde (LogsViewerComponent).
--
-- Voraussetzung:
--   - 19_navigation_cleanup.sql bereits ausgeführt (parent_id 27, is_active 0)
--   - app.routes.ts enthält Eintrag 'admin/logs' → LogsViewerComponent
-- ============================================================================

START TRANSACTION;

UPDATE `mbc_navigations`
SET `is_active` = 1
WHERE `navigations_id` = 13;

-- mbc_schema_versions.module ist Primary Key → bei Re-Run die Version überschreiben.
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.2.0', 'Reactivate /admin/logs after LogsViewerComponent impl')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- Verifizierung:
--   SELECT navigations_id, route, is_active, parent_id FROM mbc_navigations
--   WHERE navigations_id = 13;
--   → erwartet: route=/admin/logs, is_active=1, parent_id=27
