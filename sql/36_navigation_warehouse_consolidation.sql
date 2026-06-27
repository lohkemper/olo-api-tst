-- ============================================================================
-- MBC Navigation - Warehouse-Konsolidierung
-- ============================================================================
-- Erstellt: 2026-06-27
--
-- Beschreibung: Die früheren Einzelseiten /warehouse/locations,
-- /warehouse/items (+ /unassigned) und /warehouse/statistics wurden zu EINER
-- vereinten Seite unter /warehouse zusammengeführt (Kopf + KPI-Stats + Baum
-- links, Item-/Location-Detail rechts). Die Sub-Menü-Einträge zeigen damit auf
-- nicht mehr existierende Routen → deaktivieren.
--
-- Der Haupteintrag „Lager" (route = /warehouse, parent_id = NULL) bleibt als
-- einziger aktiver Warehouse-Eintrag bestehen und führt direkt auf die
-- vereinte Seite.
--
-- Idempotent: route-basierte UPDATEs sind safe re-runnable. Transaction-gekapselt.
-- Voraussetzung: 15_seed_warehouse_permissions.sql (Seed der Nav-Einträge).
-- ============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Warehouse-Untermenü deaktivieren (Routen existieren nicht mehr)
-- ---------------------------------------------------------------------------
-- /warehouse/locations          (Lagerplätze)      → jetzt Baum auf /warehouse
-- /warehouse/items              (Artikel)          → redirect → /warehouse
-- /warehouse/items/unassigned   (Nicht zugewiesen) → Bucket im Baum
-- /warehouse/statistics         (Statistiken)      → inline auf /warehouse

UPDATE `mbc_navigations`
SET `is_active` = 0
WHERE `route` IN (
  '/warehouse/locations',
  '/warehouse/items',
  '/warehouse/items/unassigned',
  '/warehouse/statistics'
);

-- ---------------------------------------------------------------------------
-- 2. Haupteintrag „Lager" auf die vereinte Seite sicherstellen
-- ---------------------------------------------------------------------------
UPDATE `mbc_navigations`
SET `is_active` = 1,
    `route`     = '/warehouse'
WHERE `route` = '/warehouse'
  AND `parent_id` IS NULL;

-- ---------------------------------------------------------------------------
-- 3. Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
-- mbc_schema_versions.module ist Primary Key → bei Re-Run die Version überschreiben.
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.4.0', 'Warehouse consolidation: deactivate locations/items/statistics submenu, keep unified /warehouse')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- ============================================================================
-- Verifizierung (nach Ausführung manuell prüfen)
-- ============================================================================
--
-- 1. Untermenü deaktiviert:
--    SELECT navigations_id, route, is_active FROM mbc_navigations
--    WHERE route LIKE '/warehouse/%';
--    → erwartet: alle is_active = 0
--
-- 2. Haupteintrag aktiv und korrekt:
--    SELECT navigations_id, title, route, is_active FROM mbc_navigations
--    WHERE route = '/warehouse' AND parent_id IS NULL;
--    → erwartet: is_active = 1, route = /warehouse
--
-- ============================================================================
