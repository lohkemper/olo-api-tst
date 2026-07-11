-- ============================================================================
-- MBC Navigation - Warehouse "Bestand"-Sub-Eintrag + Icon-Korrektur
-- ============================================================================
-- Version: 1.5.0
-- Erstellt: 2026-07-11
--
-- Beschreibung: Handoff 4 verlagert die Warehouse-Bereichs-Navigation
--   (Bestand | Packlisten | Vorlagen) vollständig in das „Lager"-Sub-Menü der
--   Top-Navigation; die frühere In-Page-Segment-Leiste entfällt.
--
--   Migration 38 hat den „Lager"-Parent (route=/warehouse, parent_id IS NULL)
--   um zwei Kinder (Packlisten, Vorlagen) erweitert. Dadurch öffnet ein Klick
--   auf „Lager" nur noch das Dropdown und navigiert nicht mehr direkt auf die
--   Stock-Seite. Deshalb: expliziter „Bestand"-Sub-Eintrag (route=/warehouse,
--   sort 1) als erster Menüpunkt, damit die Stock-Ansicht per Menü erreichbar
--   bleibt.
--
--   Zusätzlich: die in 38 gesetzten Icon-Namen 'list_alt'/'content_copy' sind
--   NICHT im Frontend-ICON_MAP (projects/ui/src/lib/icon/icon-map.ts) und
--   rendern damit kein Icon. Auf gemappte Aliase 'list' bzw. 'category'
--   korrigiert.
--
-- Idempotent: NOT-EXISTS-Insert + route-basierte UPDATEs (safe re-runnable),
--   transaction-gekapselt.
-- Voraussetzung: 36_navigation_warehouse_consolidation.sql, 38_warehouse_packlists.sql
-- ============================================================================

START TRANSACTION;

SET @warehouse_nav_id = (
  SELECT navigations_id FROM mbc_navigations
  WHERE route = '/warehouse' AND parent_id IS NULL
  LIMIT 1
);

-- ---------------------------------------------------------------------------
-- 1. „Bestand"-Sub-Eintrag (Stock-Ansicht) als erster Menüpunkt
-- ---------------------------------------------------------------------------
-- route = /warehouse (identisch zum Parent, der als reiner Dropdown-Container
-- selbst nicht mehr navigiert). NOT-EXISTS-Guard verhindert Doppel-Insert.
INSERT INTO `mbc_navigations` (`parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`)
SELECT * FROM (SELECT @warehouse_nav_id, 'Bestand', 'inventory', '/warehouse', 1, 1) AS seed
WHERE @warehouse_nav_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `mbc_navigations`
    WHERE `route` = '/warehouse' AND `parent_id` = @warehouse_nav_id
  );

-- ---------------------------------------------------------------------------
-- 2. Icon-Korrektur der in 38 geseedeten Sub-Einträge (Namen nicht im ICON_MAP)
-- ---------------------------------------------------------------------------
UPDATE `mbc_navigations` SET `icon` = 'list'     WHERE `route` = '/warehouse/packlists';
UPDATE `mbc_navigations` SET `icon` = 'category' WHERE `route` = '/warehouse/templates';

-- ---------------------------------------------------------------------------
-- 3. Rollen-Zuordnung des neuen „Bestand"-Eintrags
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route = '/warehouse' AND n.parent_id = @warehouse_nav_id
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');

-- ---------------------------------------------------------------------------
-- 4. Schema-Version
-- ---------------------------------------------------------------------------
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.5.0', 'Warehouse Stock sub-nav entry (/warehouse) + icon-map fixes (list/category)')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- ============================================================================
-- Ende der Migration
-- ============================================================================
