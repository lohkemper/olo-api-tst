-- ============================================================================
-- MBC Navigation - Pre-Flight Cleanup
-- ============================================================================
-- Erstellt: 2026-05-03
-- Quelle: docs/REFACTORING-NAVIGATION-LAYOUTS.md §1
--
-- Beschreibung: Reine DB-/Routing-Fixes ohne Layout-Diskussion.
--   1. Tipfehler `warehaouse` → `warehouse` in route (id 36, 37)
--   2. parent_id-Fix id 13 (Logs): 2 (Navigation) → 27 (Admin)
--   3. is_active = 0 für tote Routen (id 3, 13, 28) bis Implementierung steht
--   4. sort_order-Harmonisierung Lager-Children (id 37: 4 → 60)
--
-- Idempotent: UPDATEs sind safe re-runnable. Transaction-gekapselt.
-- Voraussetzung: 07_create_navigation_table.sql ausgeführt + Seed-Daten vorhanden.
-- ============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Tipfehler `warehaouse` → `warehouse` in route
-- ---------------------------------------------------------------------------
-- id 36: /warehaouse/items/unassigned → /warehouse/items/unassigned
-- id 37: /warehaouse/statistics       → /warehouse/statistics

UPDATE `mbc_navigations`
SET `route` = REPLACE(`route`, '/warehaouse/', '/warehouse/')
WHERE `navigations_id` IN (36, 37)
  AND `route` LIKE '/warehaouse/%';

-- ---------------------------------------------------------------------------
-- 2. parent_id-Fix id 13 (Logs)
-- ---------------------------------------------------------------------------
-- Logs gehört semantisch unter id 27 (Admin), nicht id 2 (Navigation).

UPDATE `mbc_navigations`
SET `parent_id` = 27
WHERE `navigations_id` = 13
  AND `parent_id` = 2;

-- ---------------------------------------------------------------------------
-- 3. is_active = 0 für tote Routen
-- ---------------------------------------------------------------------------
-- Routen sind in app.routes.ts nicht definiert → führen ins Leere (Wildcard → /home).
-- Reaktivieren, sobald Implementierung steht (siehe Items #6, #8, #13 im Doc).
--
-- id  3: /reports
-- id 13: /admin/logs        (war bereits 0, defensiv setzen)
-- id 28: /content

UPDATE `mbc_navigations`
SET `is_active` = 0
WHERE `navigations_id` IN (3, 13, 28);

-- ---------------------------------------------------------------------------
-- 4. sort_order-Harmonisierung
-- ---------------------------------------------------------------------------
-- Lager-Children nutzen Skala 60-69; id 37 (Statistiken) hat aktuell 4
-- → mischt sich mit Top-Level-Sortierung.
--
-- id 37: 4 → 60 (Statistiken am Anfang der Lager-Children)

UPDATE `mbc_navigations`
SET `sort_order` = 60
WHERE `navigations_id` = 37
  AND `sort_order` = 4;

-- ---------------------------------------------------------------------------
-- 5. Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
SELECT 'navigation', '1.1.0', 'Pre-flight cleanup: typo, parent_id, is_active, sort_order'
WHERE NOT EXISTS (
  SELECT 1 FROM `mbc_schema_versions`
  WHERE `module` = 'navigation' AND `version` = '1.1.0'
);

COMMIT;

-- ============================================================================
-- Verifizierung (nach Ausführung manuell prüfen)
-- ============================================================================
--
-- 1. Tipfehler weg:
--    SELECT navigations_id, route FROM mbc_navigations
--    WHERE route LIKE '%warehaouse%';
--    → erwartet: 0 Zeilen
--
-- 2. Logs unter Admin:
--    SELECT navigations_id, parent_id, title FROM mbc_navigations
--    WHERE navigations_id = 13;
--    → erwartet: parent_id = 27
--
-- 3. Tote Routen inaktiv:
--    SELECT navigations_id, route, is_active FROM mbc_navigations
--    WHERE navigations_id IN (3, 13, 28);
--    → erwartet: alle is_active = 0
--
-- 4. Statistiken sortiert:
--    SELECT navigations_id, title, sort_order FROM mbc_navigations
--    WHERE navigations_id = 37;
--    → erwartet: sort_order = 60
--
-- ============================================================================
