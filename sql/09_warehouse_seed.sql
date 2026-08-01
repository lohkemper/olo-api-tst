-- ============================================================================
-- MBC - Warehouse - Seed
-- ============================================================================
-- Permissions + Nav, Item-Seed, Packlisten-Permissions/-Nav, Nav-Konsolidierung/Stock.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- >>> aus: 15_seed_warehouse_permissions.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse Module - Permissions & Navigation Seed
-- ============================================================================
-- Version: 1.0.0
-- Erstellt: 2025-12-07
-- Beschreibung: Berechtigungen und Navigation für das Warehouse-Modul
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Insert Warehouse Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Location Permissions
('warehouse.locations.read.own', 'warehouse_locations', 'read', 'own', 'Eigene Lagerplätze anzeigen'),
('warehouse.locations.read.any', 'warehouse_locations', 'read', 'any', 'Beliebige Lagerplätze anzeigen (Admin)'),
('warehouse.locations.create', 'warehouse_locations', 'create', NULL, 'Neue Lagerplätze erstellen'),
('warehouse.locations.update.own', 'warehouse_locations', 'update', 'own', 'Eigene Lagerplätze bearbeiten'),
('warehouse.locations.update.any', 'warehouse_locations', 'update', 'any', 'Beliebige Lagerplätze bearbeiten (Admin)'),
('warehouse.locations.delete.own', 'warehouse_locations', 'delete', 'own', 'Eigene Lagerplätze löschen'),
('warehouse.locations.delete.any', 'warehouse_locations', 'delete', 'any', 'Beliebige Lagerplätze löschen (Admin)'),
('warehouse.locations.move', 'warehouse_locations', 'move', NULL, 'Lagerplätze verschieben (Baum-Struktur ändern)'),
('warehouse.locations.tree.view', 'warehouse_locations', 'tree', 'view', 'Baum-Ansicht der Lagerplätze anzeigen'),

-- Item Permissions
('warehouse.items.read.own', 'warehouse_items', 'read', 'own', 'Eigene Items anzeigen'),
('warehouse.items.read.any', 'warehouse_items', 'read', 'any', 'Beliebige Items anzeigen (Admin)'),
('warehouse.items.create', 'warehouse_items', 'create', NULL, 'Neue Items erstellen'),
('warehouse.items.update.own', 'warehouse_items', 'update', 'own', 'Eigene Items bearbeiten'),
('warehouse.items.update.any', 'warehouse_items', 'update', 'any', 'Beliebige Items bearbeiten (Admin)'),
('warehouse.items.delete.own', 'warehouse_items', 'delete', 'own', 'Eigene Items löschen'),
('warehouse.items.delete.any', 'warehouse_items', 'delete', 'any', 'Beliebige Items löschen (Admin)'),
('warehouse.items.assign', 'warehouse_items', 'assign', NULL, 'Items Lagerplätzen zuweisen'),
('warehouse.items.unassign', 'warehouse_items', 'unassign', NULL, 'Item-Zuweisungen aufheben'),

-- Tag Permissions (für Item-Tags)
('warehouse.items.tags.manage', 'warehouse_items', 'tags', 'manage', 'Tags zu Items hinzufügen/entfernen'),

-- Advanced Permissions (v2+)
('warehouse.items.export', 'warehouse_items', 'export', NULL, 'Items als CSV/JSON exportieren'),
('warehouse.items.import', 'warehouse_items', 'import', NULL, 'Items aus CSV/JSON importieren'),
('warehouse.locations.export', 'warehouse_locations', 'export', NULL, 'Lagerstruktur exportieren'),
('warehouse.locations.qrcode', 'warehouse_locations', 'qrcode', 'generate', 'QR-Codes für Lagerplätze generieren'),

-- Statistics & Analytics (optional)
('warehouse.statistics.view', 'warehouse', 'statistics', 'view', 'Lagerstatistiken anzeigen');


-- ============================================================================
-- Assign Permissions to Roles
-- ============================================================================

-- Guest: Keine Warehouse-Berechtigungen
-- (Guests haben keinen Zugriff auf das Lager-System)

-- User: Standard-Berechtigungen für eigenes Lager
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  -- Locations
  'warehouse.locations.read.own',
  'warehouse.locations.create',
  'warehouse.locations.update.own',
  'warehouse.locations.delete.own',
  'warehouse.locations.move',
  'warehouse.locations.tree.view',
  -- Items
  'warehouse.items.read.own',
  'warehouse.items.create',
  'warehouse.items.update.own',
  'warehouse.items.delete.own',
  'warehouse.items.assign',
  'warehouse.items.unassign',
  'warehouse.items.tags.manage',
  -- Export
  'warehouse.items.export',
  'warehouse.locations.export',
  'warehouse.locations.qrcode',
  -- Statistics
  'warehouse.statistics.view'
);

-- Moderator: Gleiche Berechtigungen wie User (kein Cross-User-Zugriff)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  -- Locations
  'warehouse.locations.read.own',
  'warehouse.locations.create',
  'warehouse.locations.update.own',
  'warehouse.locations.delete.own',
  'warehouse.locations.move',
  'warehouse.locations.tree.view',
  -- Items
  'warehouse.items.read.own',
  'warehouse.items.create',
  'warehouse.items.update.own',
  'warehouse.items.delete.own',
  'warehouse.items.assign',
  'warehouse.items.unassign',
  'warehouse.items.tags.manage',
  -- Export
  'warehouse.items.export',
  'warehouse.items.import',
  'warehouse.locations.export',
  'warehouse.locations.qrcode',
  -- Statistics
  'warehouse.statistics.view'
);

-- Admin: Alle Warehouse-Berechtigungen (inkl. Cross-User-Zugriff)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'admin'
AND p.resource IN ('warehouse_locations', 'warehouse_items', 'warehouse');

-- Super Admin: Alle Warehouse-Berechtigungen (erbt von Admin)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'super_admin'
AND p.resource IN ('warehouse_locations', 'warehouse_items', 'warehouse');


-- ============================================================================
-- Navigation-Einträge für Warehouse-Modul
-- ============================================================================

-- Haupt-Navigation: Warehouse-Eintrag
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
  'Lager',
  'warehouse',
  '/warehouse',
  60, -- Position nach E-Mail-Modul (50)
  1
);

-- Hole die ID des gerade eingefügten Haupteintrags
SET @warehouse_navigations_id = LAST_INSERT_ID();

-- Sub-Navigation: Lagerplätze (Tree-View)
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
  @warehouse_navigations_id,
  'Lagerplätze',
  'account_tree',
  '/warehouse/locations',
  1,
  1
);

-- Sub-Navigation: Items-Liste
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
  @warehouse_navigations_id,
  'Artikel',
  'inventory_2',
  '/warehouse/items',
  2,
  1
);

-- Sub-Navigation: Nicht zugewiesene Items
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
  @warehouse_navigations_id,
  'Nicht zugewiesen',
  'inbox',
  '/warehouse/items/unassigned',
  3,
  1
);

-- Sub-Navigation: Statistiken (optional)
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
  @warehouse_navigations_id,
  'Statistiken',
  'analytics',
  '/warehouse/statistics',
  4,
  0
);


-- ============================================================================
-- Navigation-Rollen-Zuordnung
-- ============================================================================

-- Warehouse-Navigation für User, Moderator, Admin, Super Admin
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route LIKE '/warehouse%'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


-- ============================================================================
-- Test-Daten (optional - für Entwicklung)
-- ============================================================================

-- Test-User (falls noch nicht vorhanden)
-- INSERT IGNORE INTO mbc_users (users_id, username, email, password) VALUES
-- (999, 'warehouse_testuser', 'warehouse@test.local', '$2y$10$...');

-- Test-Locations (nur für Entwicklung)
-- SET @test_user_id = 999;
--
-- INSERT INTO mbc_warehouse_locations (name, parent_id, type, user_id, description) VALUES
--   ('Keller', NULL, 'room', @test_user_id, 'Hauptlagerraum im Keller'),
--   ('Garage', NULL, 'room', @test_user_id, 'Garagenlager'),
--   ('Büro', NULL, 'room', @test_user_id, 'Büro-Lagerbereich');
--
-- SET @keller_id = (SELECT locations_id FROM mbc_warehouse_locations WHERE name = 'Keller' AND user_id = @test_user_id LIMIT 1);
--
-- INSERT INTO mbc_warehouse_locations (name, parent_id, type, user_id, description) VALUES
--   ('Regal 1', @keller_id, 'shelf', @test_user_id, 'Metallregal links'),
--   ('Regal 2', @keller_id, 'shelf', @test_user_id, 'Holzregal rechts'),
--   ('Werkzeugkasten', @keller_id, 'box', @test_user_id, 'Roter Werkzeugkasten');
--
-- SET @regal1_id = (SELECT locations_id FROM mbc_warehouse_locations WHERE name = 'Regal 1' AND user_id = @test_user_id LIMIT 1);
--
-- INSERT INTO mbc_warehouse_locations (name, parent_id, type, user_id, description) VALUES
--   ('Schachtel A', @regal1_id, 'box', @test_user_id, 'Blaue Aufbewahrungsbox'),
--   ('Schachtel B', @regal1_id, 'box', @test_user_id, 'Grüne Aufbewahrungsbox');

-- Test-Items
-- SET @schachtel_a_id = (SELECT locations_id FROM mbc_warehouse_locations WHERE name = 'Schachtel A' AND user_id = @test_user_id LIMIT 1);
--
-- INSERT INTO mbc_warehouse_items (name, description, location_id, quantity, unit, user_id, barcode) VALUES
--   ('HDMI Kabel', '2m, schwarz, High-Speed', @schachtel_a_id, 3, 'Stück', @test_user_id, '4250394762081'),
--   ('Arduino Uno', 'Mikrocontroller Board Rev3', @schachtel_a_id, 1, 'Stück', @test_user_id, '8058333490090'),
--   ('Breadboard', '830 Kontakte, weiß', @schachtel_a_id, 2, 'Stück', @test_user_id, NULL),
--   ('Widerstände 220Ω', 'Kohleschicht, 1/4W', @schachtel_a_id, 100, 'Stück', @test_user_id, NULL),
--   ('LEDs rot 5mm', 'Superhelle LEDs', @schachtel_a_id, 50, 'Stück', @test_user_id, NULL);

-- Nicht zugewiesene Items
-- INSERT INTO mbc_warehouse_items (name, description, location_id, quantity, unit, user_id) VALUES
--   ('USB-C Hub', '7-in-1, Aluminium', NULL, 1, 'Stück', @test_user_id),
--   ('SD-Karte 64GB', 'SanDisk Extreme', NULL, 2, 'Stück', @test_user_id);

-- Test-Tags (falls mbc_tags vorhanden ist)
-- INSERT IGNORE INTO mbc_tags (name, user_id) VALUES
--   ('Elektronik', @test_user_id),
--   ('Kabel', @test_user_id),
--   ('Mikrocontroller', @test_user_id),
--   ('Bauteile', @test_user_id);

-- Test-Item-Tags-Zuordnungen
-- SET @tag_elektronik = (SELECT tags_id FROM mbc_tags WHERE name = 'Elektronik' AND user_id = @test_user_id LIMIT 1);
-- SET @tag_kabel = (SELECT tags_id FROM mbc_tags WHERE name = 'Kabel' AND user_id = @test_user_id LIMIT 1);
-- SET @tag_controller = (SELECT tags_id FROM mbc_tags WHERE name = 'Mikrocontroller' AND user_id = @test_user_id LIMIT 1);
-- SET @tag_bauteile = (SELECT tags_id FROM mbc_tags WHERE name = 'Bauteile' AND user_id = @test_user_id LIMIT 1);
--
-- SET @item_hdmi = (SELECT items_id FROM mbc_warehouse_items WHERE name = 'HDMI Kabel' AND user_id = @test_user_id LIMIT 1);
-- SET @item_arduino = (SELECT items_id FROM mbc_warehouse_items WHERE name = 'Arduino Uno' AND user_id = @test_user_id LIMIT 1);
--
-- INSERT IGNORE INTO mbc_warehouse_item_tags (items_id, tag_id) VALUES
--   (@item_hdmi, @tag_elektronik),
--   (@item_hdmi, @tag_kabel),
--   (@item_arduino, @tag_elektronik),
--   (@item_arduino, @tag_controller);


COMMIT;

-- ============================================================================
-- Update Schema Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('warehouse_permissions', '1.0.0', 'Berechtigungen und Navigation für Warehouse-Modul')
ON DUPLICATE KEY UPDATE
  version = '1.0.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Berechtigungen und Navigation für Warehouse-Modul';

-- ============================================================================
-- Ende des Permission-Seeds
-- ============================================================================


-- >>> aus: 36_navigation_warehouse_consolidation.sql ------------------------------------------------------------
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


-- >>> aus: 37_warehouse_items_seed.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse - Items-Seed (Produktdaten + Lagerplätze)
-- ============================================================================
-- Erstellt: 2026-06-27
--
-- Beschreibung: Befüllt das Warenlager mit konkreten Produkten. Jedes Item
--   bekommt direkt einen Lagerplatz zugewiesen. Lagerplätze werden bei Bedarf
--   automatisch (inkl. Eltern-Hierarchie) angelegt — als Pfad notiert, z.B.
--     '/Keller/Regal 1/Box A'
--
-- Bedienung:
--   Pro Produkt genau EINE Zeile im Abschnitt „ITEMS" ergänzen:
--     CALL sp_seed_item(@uid, '<Lagerplatz-Pfad>', '<typ-des-platzes>',
--                       '<Name>', '<Beschreibung>',
--                       <menge>, '<einheit>', '<barcode-oder-NULL>', NULL);
--   Danach die gesamte Datei erneut ausführen — sie ist idempotent:
--     • Lagerplätze werden per Pfad gematcht (kein Duplikat).
--     • Items werden per (user_id, name, location_id) gematcht (kein Duplikat).
--
-- Idempotent: Re-Run fügt nichts doppelt ein. Helfer-Prozeduren werden am
--   Ende wieder entfernt, damit die DB sauber bleibt.
-- Voraussetzung: 14_warehouse-schema.sql, 20_..., 24_..., 23_... ausgeführt.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 0. Ziel-User auflösen
-- ---------------------------------------------------------------------------
-- @seed_user ggf. auf den tatsächlichen Login-Namen anpassen, dem die Items
-- gehören sollen. @uid wird daraus aufgelöst; ist er NULL, schlägt der erste
-- Insert per FK-Fehler fehl (gewollte Schutzwirkung gegen Falsch-Seeds).
-- Laut /auth/user: username = 'Oliver' → users_id = 8.
SET @seed_user := 'Oliver';
SET @uid := (SELECT users_id FROM mbc_users WHERE username = @seed_user);

-- ---------------------------------------------------------------------------
-- 1. Helfer: Lagerplatz-Pfad sicherstellen (idempotent, inkl. Hierarchie)
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_seed_ensure_location;

DELIMITER $$

CREATE PROCEDURE sp_seed_ensure_location(
  IN  p_user_id  INT,
  IN  p_path     VARCHAR(1000),   -- '/Keller/Regal 1/Box A'
  IN  p_leaf_type VARCHAR(50),    -- Typ NUR des letzten Segments (room/shelf/box/slot)
  OUT p_leaf_id  INT
)
BEGIN
  DECLARE v_remaining VARCHAR(1000);
  DECLARE v_segment   VARCHAR(255);
  DECLARE v_parent    INT DEFAULT NULL;
  DECLARE v_found     INT DEFAULT NULL;
  DECLARE v_built     VARCHAR(1000) DEFAULT '';
  DECLARE v_is_last   TINYINT DEFAULT 0;

  -- führende/trailende Slashes entfernen
  SET v_remaining = TRIM(BOTH '/' FROM p_path);

  WHILE LENGTH(v_remaining) > 0 DO
    SET v_segment = SUBSTRING_INDEX(v_remaining, '/', 1);
    SET v_built   = CONCAT(v_built, '/', v_segment);
    SET v_is_last = (LOCATE('/', v_remaining) = 0);

    -- existiert dieser Knoten bereits (per vom Trigger berechnetem path)?
    -- MAX(): liefert immer genau eine Zeile (NULL falls nicht vorhanden) und
    -- vermeidet so die „No data"-Warnung von SELECT ... INTO.
    SELECT MAX(locations_id) INTO v_found
    FROM mbc_warehouse_locations
    WHERE user_id = p_user_id AND path = v_built;

    IF v_found IS NULL THEN
      INSERT INTO mbc_warehouse_locations (name, parent_id, type, user_id)
      VALUES (v_segment, v_parent, IF(v_is_last, p_leaf_type, NULL), p_user_id);
      SET v_found = LAST_INSERT_ID();
    END IF;

    SET v_parent = v_found;

    -- Rest-Pfad vorrücken
    IF LOCATE('/', v_remaining) > 0 THEN
      SET v_remaining = SUBSTRING(v_remaining, LENGTH(v_segment) + 2);
    ELSE
      SET v_remaining = '';
    END IF;
  END WHILE;

  SET p_leaf_id = v_parent;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------------
-- 2. Helfer: Item anlegen (Lagerplatz sicherstellen + idempotent inserten)
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_seed_item;

DELIMITER $$

CREATE PROCEDURE sp_seed_item(
  IN p_user_id   INT,
  IN p_loc_path  VARCHAR(1000),
  IN p_loc_type  VARCHAR(50),
  IN p_name      VARCHAR(255),
  IN p_desc      TEXT,
  IN p_quantity  DECIMAL(10,2),
  IN p_unit      VARCHAR(50),
  IN p_barcode   VARCHAR(255),
  IN p_meta      JSON,
  IN p_grid_row  INT,            -- Rasterzeile (1-basiert) oder NULL
  IN p_grid_col  INT             -- Rasterspalte (1-basiert) oder NULL
)
BEGIN
  DECLARE v_loc INT;

  CALL sp_seed_ensure_location(p_user_id, p_loc_path, p_loc_type, v_loc);

  -- idempotent: gleiches Item (Name) am selben Platz nicht doppelt anlegen
  IF NOT EXISTS (
    SELECT 1 FROM mbc_warehouse_items
    WHERE user_id = p_user_id
      AND name    = p_name
      AND (location_id <=> v_loc)
  ) THEN
    INSERT INTO mbc_warehouse_items
      (name, description, location_id, quantity, unit, barcode, meta,
       grid_row, grid_col, user_id)
    VALUES
      (p_name, p_desc, v_loc, COALESCE(p_quantity, 1.00),
       COALESCE(p_unit, 'Stück'), p_barcode, p_meta,
       NULLIF(p_grid_row, 0), NULLIF(p_grid_col, 0), p_user_id);
  END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------------
-- 2b. Helfer: Lagerplatz mit Maßen anlegen (idempotent, ohne Item)
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_seed_location;

DELIMITER $$

CREATE PROCEDURE sp_seed_location(
  IN p_user_id     INT,
  IN p_parent_path VARCHAR(1000),   -- Parent-Pfad ('' / NULL = Root)
  IN p_name        VARCHAR(255),
  IN p_type        VARCHAR(50),     -- room/shelf/box/slot
  IN p_desc        TEXT,
  IN p_width_cm    INT,
  IN p_height_cm   INT,
  IN p_depth_cm    INT,
  IN p_meta        JSON
)
BEGIN
  DECLARE v_parent INT DEFAULT NULL;
  DECLARE v_full   VARCHAR(1000);
  DECLARE v_found  INT DEFAULT NULL;

  -- Parent auflösen (falls angegeben); legt fehlende Eltern automatisch an
  IF p_parent_path IS NOT NULL AND LENGTH(TRIM(BOTH '/' FROM p_parent_path)) > 0 THEN
    CALL sp_seed_ensure_location(p_user_id, p_parent_path, NULL, v_parent);
    SET v_full = CONCAT('/', TRIM(BOTH '/' FROM p_parent_path), '/', p_name);
  ELSE
    SET v_parent = NULL;
    SET v_full   = CONCAT('/', p_name);
  END IF;

  -- idempotent: gleichen Pfad nicht doppelt anlegen
  SELECT MAX(locations_id) INTO v_found
  FROM mbc_warehouse_locations
  WHERE user_id = p_user_id AND path = v_full;

  IF v_found IS NULL THEN
    INSERT INTO mbc_warehouse_locations
      (name, parent_id, type, description, width_cm, height_cm, depth_cm, meta, user_id)
    VALUES
      (p_name, v_parent, p_type, p_desc,
       NULLIF(p_width_cm, 0), NULLIF(p_height_cm, 0), NULLIF(p_depth_cm, 0),
       p_meta, p_user_id);
  END IF;
END$$

DELIMITER ;

-- ===========================================================================
-- LOCATIONS (Boxen)  — Euroboxen als Lagerplätze unter /Eingang
-- ===========================================================================
-- Maß-Mapping: 400×300×H mm → width_cm 40, depth_cm 30, height_cm = H/10.

-- Eurobox NextGen Economy 20 L (400×300×220 mm) — 4 Stück
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 20L Nr. 1', 'box',
     'Eurobox NextGen Economy, 20 L, 400×300×220 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 22, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":20,"dimensions_mm":"400x300x220","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 20L Nr. 2', 'box',
     'Eurobox NextGen Economy, 20 L, 400×300×220 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 22, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":20,"dimensions_mm":"400x300x220","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 20L Nr. 3', 'box',
     'Eurobox NextGen Economy, 20 L, 400×300×220 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 22, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":20,"dimensions_mm":"400x300x220","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 20L Nr. 4', 'box',
     'Eurobox NextGen Economy, 20 L, 400×300×220 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 22, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":20,"dimensions_mm":"400x300x220","color":"Grau","stackable":true,"material":"Kunststoff"}');

-- Eurobox NextGen Economy 30 L (400×300×320 mm) — 4 Stück
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 30L Nr. 1', 'box',
     'Eurobox NextGen Economy, 30 L, 400×300×320 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 32, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":30,"dimensions_mm":"400x300x320","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 30L Nr. 2', 'box',
     'Eurobox NextGen Economy, 30 L, 400×300×320 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 32, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":30,"dimensions_mm":"400x300x320","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 30L Nr. 3', 'box',
     'Eurobox NextGen Economy, 30 L, 400×300×320 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 32, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":30,"dimensions_mm":"400x300x320","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 30L Nr. 4', 'box',
     'Eurobox NextGen Economy, 30 L, 400×300×320 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 32, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":30,"dimensions_mm":"400x300x320","color":"Grau","stackable":true,"material":"Kunststoff"}');

-- Eurobox NextGen Economy 11 L (400×300×120 mm) — 3 Stück
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 11L Nr. 1', 'box',
     'Eurobox NextGen Economy, 11 L, 400×300×120 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 12, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":11,"dimensions_mm":"400x300x120","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 11L Nr. 2', 'box',
     'Eurobox NextGen Economy, 11 L, 400×300×120 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 12, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":11,"dimensions_mm":"400x300x120","color":"Grau","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox 11L Nr. 3', 'box',
     'Eurobox NextGen Economy, 11 L, 400×300×120 mm. Stapelbar, offene Griffe, Industriequalität, grau.',
     40, 12, 30,
     '{"brand":"Eurobox NextGen Economy","volume_l":11,"dimensions_mm":"400x300x120","color":"Grau","stackable":true,"material":"Kunststoff"}');

-- Eurobox NextGen Grip 400×300×120 mm — 3 Stück (Volumen nicht angegeben)
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox Grip 400×300×120 Nr. 1', 'box',
     'Eurobox NextGen Grip, 400×300×120 mm. Stapelbar, offene ergonomische Griffe, Industriequalität, Kunststoff.',
     40, 12, 30,
     '{"brand":"Eurobox NextGen Grip","line":"Grip","dimensions_mm":"400x300x120","grips":"ergonomisch, offen","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox Grip 400×300×120 Nr. 2', 'box',
     'Eurobox NextGen Grip, 400×300×120 mm. Stapelbar, offene ergonomische Griffe, Industriequalität, Kunststoff.',
     40, 12, 30,
     '{"brand":"Eurobox NextGen Grip","line":"Grip","dimensions_mm":"400x300x120","grips":"ergonomisch, offen","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox Grip 400×300×120 Nr. 3', 'box',
     'Eurobox NextGen Grip, 400×300×120 mm. Stapelbar, offene ergonomische Griffe, Industriequalität, Kunststoff.',
     40, 12, 30,
     '{"brand":"Eurobox NextGen Grip","line":"Grip","dimensions_mm":"400x300x120","grips":"ergonomisch, offen","stackable":true,"material":"Kunststoff"}');

-- Eurobox NextGen Grip 400×300×320 mm — 3 Stück (Volumen nicht angegeben)
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox Grip 400×300×320 Nr. 1', 'box',
     'Eurobox NextGen Grip, 400×300×320 mm. Stapelbar, offene ergonomische Griffe, Industriequalität, Kunststoff.',
     40, 32, 30,
     '{"brand":"Eurobox NextGen Grip","line":"Grip","dimensions_mm":"400x300x320","grips":"ergonomisch, offen","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox Grip 400×300×320 Nr. 2', 'box',
     'Eurobox NextGen Grip, 400×300×320 mm. Stapelbar, offene ergonomische Griffe, Industriequalität, Kunststoff.',
     40, 32, 30,
     '{"brand":"Eurobox NextGen Grip","line":"Grip","dimensions_mm":"400x300x320","grips":"ergonomisch, offen","stackable":true,"material":"Kunststoff"}');
CALL sp_seed_location(@uid, '/Eingang', 'Eurobox Grip 400×300×320 Nr. 3', 'box',
     'Eurobox NextGen Grip, 400×300×320 mm. Stapelbar, offene ergonomische Griffe, Industriequalität, Kunststoff.',
     40, 32, 30,
     '{"brand":"Eurobox NextGen Grip","line":"Grip","dimensions_mm":"400x300x320","grips":"ergonomisch, offen","stackable":true,"material":"Kunststoff"}');

-- ===========================================================================
-- ITEMS  — pro Produkt eine CALL-Zeile (wird fortlaufend ergänzt)
-- ===========================================================================
-- Signatur:
--   sp_seed_item(@uid, Lagerplatz-Pfad, Platz-Typ,
--                Name, Beschreibung,
--                Menge, Einheit, Barcode|NULL, Meta-JSON|NULL,
--                grid_row|NULL, grid_col|NULL)
-- grid_row/grid_col = Rasterposition im Platz (1-basiert), NULL = ohne Position.
--
-- Beispiel (auskommentiert — als Vorlage):
-- CALL sp_seed_item(@uid, '/Wohnung/Keller/Regal 1', 'shelf',
--                   'HDMI-Kabel 2m', 'High-Speed, schwarz',
--                   3, 'Stück', NULL, NULL,
--                   2, 1);   -- Regal 1 (5x1): 2. Fach
--
-- ---------------------------------------------------------------------------
-- Vorhandene Lagerplätze (user_id = 8) — exakte Pfade zum Auswählen.
-- Der Path-Matcher löst diese auf die bestehende ID auf (kein Duplikat);
-- nur ein NICHT gelisteter Pfad würde neu angelegt.
-- ---------------------------------------------------------------------------
--  id  Pfad                                              Typ    Grid (rxc)
--  24  /Eingang                                          —
--   3  /Wohnung                                          —
--  20  /Wohnung/Bad                                      room
--  27  /Wohnung/Bad/Seitenschrank                        shelf
--  26  /Wohnung/Bad/Spiegelschrank                       shelf
--  25  /Wohnung/Bad/Unterschrank                         shelf
--  19  /Wohnung/Flur                                     room
--   1  /Wohnung/Keller                                   room   1x1
--   7  /Wohnung/Keller/Regal 1                           shelf  5x1
--   8  /Wohnung/Keller/Regal 2                           shelf  5x2
--   9  /Wohnung/Keller/Regal 3                           shelf         (Hinter der Tür)
--  30  /Wohnung/Keller/Regal 4                           shelf         (Hinter dem Zelt)
--  31  /Wohnung/Keller/Regal 5                           shelf  6x1
--  32  /Wohnung/Keller/Regal 6                           shelf  6x1
--  34  /Wohnung/Keller/Seedbox                           box
--  10  /Wohnung/Küche                                    room
--  13  /Wohnung/Küche/Eckschrank                         shelf  1x1
--  11  /Wohnung/Küche/Regal                              shelf  10x10
--  12  /Wohnung/Küche/Schrank                            shelf  4x5
--   5  /Wohnung/Schlafzimmer                             room
--  18  /Wohnung/Schlafzimmer/Abstellschrank              shelf  6x1
--  28  /Wohnung/Schlafzimmer/Bastelschrank               shelf  6x1
--  29  /Wohnung/Schlafzimmer/Bastelschrank/Kleinteileregal shelf 5x8
--  17  /Wohnung/Schlafzimmer/Bett                        —
--  14  /Wohnung/Schlafzimmer/Kleiderschrank              shelf  5x1
--  15  /Wohnung/Schlafzimmer/Komode 1                    shelf  4x1
--  16  /Wohnung/Schlafzimmer/Komode 2                    shelf  3x2
--   6  /Wohnung/Schlafzimmer/Schreibtisch                shelf  5x1
--   4  /Wohnung/Wohnzimmer                               room
--  22  /Wohnung/Wohnzimmer/Schrank                       —
--  33  /Wohnung/Wohnzimmer/Sideboard                     shelf  2x1
--  21  /Wohnung/Wohnzimmer/Sofa                          —
--  23  /Wohnung/Wohnzimmer/Wohnzimmertisch               —
-- ---------------------------------------------------------------------------

-- >>> Ab hier kommen die echten Produkte <<<

-- 1) SHINCO Mobiles Klimagerät 12000 BTU — Schlafzimmer
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer', 'room',
                  'SHINCO Mobiles Klimagerät 12000 BTU',
                  'Mobiles Klimagerät, 12000 BTU / 3,5 kW. Kühlen, Lüften, Entfeuchten. Mit Abluftschlauch, CE-zertifiziert. 83 × 44,3 × 34 cm, 28 kg, weiß.',
                  1, 'Stück', NULL,
                  '{"brand":"SHINCO","color":"weiß","btu":12000,"power_kw":3.5,"weight_kg":28.0,"dimensions_cm":"83 x 44.29 x 34","certification":"CE","functions":["Kühlen","Lüften","Entfeuchten"],"price_eur":313.69,"list_price_eur":1004.50}',
                  NULL, NULL);

-- 2) R385 Mini-Membranpumpe 12V — Wohnzimmer
CALL sp_seed_item(@uid, '/Wohnung/Wohnzimmer', 'room',
                  'R385 Mini-Membranpumpe 12V',
                  '12V Mini-Membran-/Hubkolbenpumpe, max. Saughöhe 2 m. Für Wasserspender & Aquarien. Inkl. PVC- und Silikon-Halterung. ca. 130 g.',
                  12, 'Stück', NULL,
                  '{"model":"R385","voltage_v":12,"type":"Membranpumpe","max_suction_m":2,"weight_kg":0.13,"bracket":"PVC","use":["Wasserspender","Aquarium"],"price_eur":1.39,"list_price_eur":1.73}',
                  NULL, NULL);

-- 3) Wägezelle 50 kg (Halbbrücke) — Wohnzimmer
CALL sp_seed_item(@uid, '/Wohnung/Wohnzimmer', 'room',
                  'Wägezelle 50 kg (Halbbrücke)',
                  'Wägezelle / Gewichtssensor 50 kg, Halbbrücken-DMS (Strain Gauge). Für Arduino & Mikrocontroller, Körperwaagen. Metallausführung. Variante „4pcs bracket".',
                  4, 'Stück', NULL,
                  '{"type":"Wägezelle","range_kg":50,"bridge":"Halbbrücke","tech":"DMS","platform":"Arduino","variant":"4pcs bracket","weight_kg":0.292,"package_cm":"18 x 15 x 5","price_eur":5.69}',
                  NULL, NULL);

-- 4) USB Mini-Vernebler DIY-Kit 5V — Wohnzimmer
CALL sp_seed_item(@uid, '/Wohnung/Wohnzimmer', 'room',
                  'USB Mini-Vernebler DIY-Kit 5V',
                  'USB Ultraschall-Vernebler / Mist Maker, 5V / 2W. Bausatz mit Treiberplatine und Atomizer-Membran, DIP-Bauweise zum Löten. Für DIY-/Bastelprojekte.',
                  2, 'Stück', NULL,
                  '{"type":"Ultraschall-Vernebler","voltage_v":5,"power_w":2,"interface":"USB","package":"DIP","includes":["Treiberplatine","Atomizer-Membran"],"use":"DIY","price_eur":3.49,"single_price_eur":4.79,"list_price_eur":14.97}',
                  NULL, NULL);

-- 5) TENSTAR T-Display ESP32 1,14 Zoll LCD — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'TENSTAR T-Display ESP32 1,14 Zoll LCD',
                  'ESP32-Entwicklungsboard mit WiFi & Bluetooth, integriertes 1,14" LCD. USB-Chip CH9102F, 16 MB Flash, Betriebstemperatur -40…+85 °C, 1 W. Für IoT/Prototyping.',
                  1, 'Stück', NULL,
                  '{"brand":"TENSTAR","model":"T-Display ESP32","display_inch":1.14,"chip":"CH9102F","flash":"16MB","wireless":["WiFi","Bluetooth"],"temp_range_c":"-40..85","power_w":1,"price_eur":13.39,"list_price_eur":43.62,"bulk_price_eur":12.89}',
                  NULL, NULL);

-- 6) Flylin Takoyaki-Maker 18 Formen (750W) — Küche
CALL sp_seed_item(@uid, '/Wohnung/Küche', 'room',
                  'Flylin Takoyaki-Maker 18 Formen (750W)',
                  'Elektrischer Takoyaki-Maker, 750 W, 220–240 V. 18 runde Formen (4 cm), Antihaftbeschichtung, Edelstahl, schwarz. 22 × 23,5 × 6,8 cm. Inkl. Handbuch.',
                  1, 'Stück', NULL,
                  '{"brand":"Flylin","power_w":750,"voltage_v":"220-240","holes":18,"hole_diameter_cm":4,"coating":"Antihaft","material":"Edelstahl","color":"Schwarz","dimensions_cm":"22 x 23.5 x 6.8","includes":["Handbuch"],"availability":"derzeit nicht verfügbar"}',
                  NULL, NULL);

-- 7) Logitech MX Keys S (QWERTZ, Graphit) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'Logitech MX Keys S (QWERTZ, Graphit)',
                  'Kabellose Tastatur, Low Profile, Hintergrundbeleuchtung. Bluetooth + Logi Bolt USB-Empfänger, Multi-Device (3 Geräte), USB-C wiederaufladbar. Deutsches QWERTZ, Graphit. 43 × 13,2 × 2,1 cm.',
                  1, 'Stück', NULL,
                  '{"brand":"Logitech","model":"MX Keys S","layout":"QWERTZ (DE)","color":"Graphit","connectivity":["Bluetooth","Logi Bolt USB"],"rechargeable":"USB-C","backlight":true,"dimensions_cm":"43 x 13.2 x 2.1","price_eur":79.00}',
                  NULL, NULL);

-- 8) VFU Höhenverstellbarer Schreibtisch 120×60 (Weiß Pro) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'VFU Höhenverstellbarer Schreibtisch 120×60 (Weiß Pro)',
                  'Elektrisch höhenverstellbarer Steh-Sitz-Schreibtisch, 120 × 60 cm, Höhe 73–118 cm. Memory-Funktion (2 Speicher), USB-C-Ladeanschluss, Anti-Kollision, Tragkraft 70 kg, <55 dB. Stahlrahmen, Holzwerkstoff laminiert, Weiß Pro.',
                  1, 'Stück', NULL,
                  '{"brand":"VFU","model":"Standing Desk 120x60","color":"Weiß Pro","tabletop_cm":"120 x 60","height_range_cm":"73-118","electric":true,"memory_presets":2,"usb_c":true,"anti_collision":true,"load_capacity_kg":70,"noise_db":"<55","frame_material":"Stahl","price_eur":59.99}',
                  NULL, NULL);

-- 9) PUTORSEN 3-Monitor-Tischhalterung 13–27" (weiß) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'PUTORSEN 3-Monitor-Tischhalterung 13–27" (weiß)',
                  'Tisch-Monitorhalterung für bis zu 3 Monitore 13–27" (33–68 cm), bis 7 kg/Monitor. Neigbar 90°, schwenk-/drehbar 360°, höhenverstellbar. Tischklemme oder Öse (bis 10 cm Tischstärke), VESA, Kabelmanagement. Stahl, weiß.',
                  1, 'Stück', NULL,
                  '{"brand":"PUTORSEN","type":"Monitor-Tischhalterung","monitors":3,"fits_inch":"13-27","max_load_kg_per_monitor":7,"tilt_deg":90,"swivel_deg":360,"rotate_deg":360,"height_adjustable":true,"mount":["Tischklemme","Öse"],"color":"weiß","vesa":true,"price_eur":49.99}',
                  NULL, NULL);

-- 10) CAGO Pflanztopf viereckig 28×28×28,5 cm (14 L) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'CAGO Pflanztopf viereckig 28×28×28,5 cm (14 L)',
                  'Viereckiger Pflanztopf, 28 × 28 × 28,5 cm, 14 L. Kunststoff, schwarz, leicht.',
                  4, 'Stück', NULL,
                  '{"brand":"CAGO","type":"Pflanztopf","shape":"viereckig","dimensions_cm":"28 x 28 x 28.5","volume_l":14,"material":"Kunststoff","color":"Schwarz","price_eur":2.59}',
                  NULL, NULL);

-- 11) JST ZH 1.5 2-Pin Steckverbinder-Set (Buchse + Stecker) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'JST ZH 1.5 2-Pin Steckverbinder-Set (Buchse + Stecker)',
                  'Mini-Micro JST ZH 1,5 mm, 2-polig. Buchse und Stecker mit je 150 mm 26AWG Kabel (rot/schwarz). 30 Paar.',
                  30, 'Paar', NULL,
                  '{"type":"JST ZH Steckverbinder","pitch_mm":1.5,"pins":2,"wire_length_mm":150,"wire_awg":26,"colors":["rot","schwarz"]}',
                  NULL, NULL);

-- 12) Seesaw JST 1.25 2-Pin Steckverbinder-Set (Stecker + Buchse) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'Seesaw JST 1.25 2-Pin Steckverbinder-Set (Stecker + Buchse)',
                  'Mikro JST 1,25 mm, 2-polig. Stecker und Buchse mit je 10 cm Kabel (26AWG). Farbe weiß mit rot/schwarz. 25 Paar.',
                  25, 'Paar', NULL,
                  '{"brand":"Seesaw","type":"JST 1.25 Steckverbinder","pitch_mm":1.25,"pins":2,"wire_length_mm":100,"wire_awg":26,"color":"Weiß mit rot/schwarz","availability":"derzeit nicht verfügbar"}',
                  NULL, NULL);

-- 13) Ykall Tragbares Airbrush-Set mit Kompressor (schwarz) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'Ykall Tragbares Airbrush-Set mit Kompressor (schwarz)',
                  'Tragbares Handheld-Airbrush-Set mit Kompressor, 0,3-mm-Düse, zwei Luftdruckstufen (23/27 PSI). Akkubetrieben (USB-C, >60 min). Für Nageldesign, Make-up, Tortendeko, Tattoo, Modellbau. Inkl. Pipette, 5× Reinigungsbürste/-nadel, Behälter 20/40 ml. Schwarz.',
                  1, 'Stück', NULL,
                  '{"brand":"Ykall","type":"Airbrush-Set","color":"Schwarz","nozzle_mm":0.3,"pressure_psi":[23,27],"power":"Akku/USB-C","runtime_min":60,"includes":["Airbrush","Pipette","0.3mm-Düse","5x Reinigungsbürste","5x Reinigungsnadel","Behälter 20ml","Behälter 40ml","USB-C-Kabel","Handbuch"],"price_eur":34.99}',
                  NULL, NULL);

-- 14) Siemens EQ.6 plus s300 Kaffeevollautomat (TE653501DE, Silber) — Küche
CALL sp_seed_item(@uid, '/Wohnung/Küche', 'room',
                  'Siemens EQ.6 plus s300 Kaffeevollautomat (TE653501DE, Silber)',
                  'Kaffeevollautomat mit Milchsystem, 11 Getränke, automatische Milchsystem-Reinigung, Keramikmahlwerk, großes Touchdisplay. 1500 W, 230 V, 1,7 L Wassertank. 46,5 × 28 × 38,5 cm, 8,3 kg. Inkl. BRITA INTENZA Wasserfilter, Messlöffel, Teststreifen, Milchschlauch. Silber/Grau.',
                  1, 'Stück', '4242003803516',
                  '{"brand":"Siemens","model":"EQ.6 plus s300","model_number":"TE653501DE","asin":"B074QGDYVB","gtin":"04242003803516","color":"Silber/Grau","drinks":11,"grinder":"Keramik","power_w":1500,"voltage_v":230,"tank_l":1.7,"display":"Touchscreen","dimensions_cm":"46.5 x 28 x 38.5","weight_kg":8.3,"year":2019,"includes":["BRITA INTENZA Wasserfilter","Messlöffel","Teststreifen","Milchschlauch","Milchsteigrohr"],"price_eur":599.00}',
                  NULL, NULL);

-- 15) Gardebruk Auflagenbox 385L Metall abschließbar (weiß) — Balkon (NEUER Lagerplatz)
CALL sp_seed_item(@uid, '/Wohnung/Balkon', 'room',
                  'Gardebruk Auflagenbox 385L Metall abschließbar (weiß)',
                  'Auflagenbox / Gartentruhe, 385 L. Pulverbeschichtetes, rostfreies Metall, abschließbar (Sicherheitsschloss + 2 Schlüssel), 2 wartungsfreie Gasdruckfedern. Wetterfest/wasserdicht, Bodenbelastung bis 100 kg. 120 × 62 cm. Weiß.',
                  1, 'Stück', NULL,
                  '{"brand":"Gardebruk","type":"Auflagenbox","volume_l":385,"material":"Metall (pulverbeschichtet)","lockable":true,"gas_springs":2,"weatherproof":true,"load_capacity_kg":100,"dimensions_cm":"120 x 62","color":"Weiß","price_eur":119.95}',
                  NULL, NULL);

-- 16) Mia&Coco Heizdecke 10 Heizstufen (grau) — Schlafzimmer
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer', 'room',
                  'Mia&Coco Heizdecke 10 Heizstufen (grau)',
                  'Elektrische Heiz-/Wärmedecke, Flanell & Sherpa. 10 Heizstufen (25–53 °C), Auto-Off-Timer bis 9 h, Überhitzungsschutz, ETL-zertifiziert. Abnehmbares Kabel, maschinenwaschbar. Grau.',
                  1, 'Stück', NULL,
                  '{"brand":"Mia&Coco","type":"Heizdecke","material":"Flanell/Sherpa","heat_levels":10,"temp_range_c":"25-53","auto_off_timer_h":9,"overheat_protection":true,"certification":"ETL","washable":true,"color":"Grau"}',
                  NULL, NULL);

-- 17) TWDRTDD E27-auf-E27 Adapter-Sockel (weiß) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'TWDRTDD E27-auf-E27 Adapter-Sockel (weiß)',
                  'E27-auf-E27 Lampenfassungs-Adapter / Verlängerungssockel. Metall & Kunststoff (PBT), hochtemperaturbeständig. Max. 250 V / 2 A. 37 × 14 × 65 mm. Weiß. 3er-Pack.',
                  3, 'Stück', NULL,
                  '{"brand":"TWDRTDD","type":"Lampenfassungs-Adapter","socket":"E27 auf E27","material":"Metall/Kunststoff (PBT)","max_voltage_v":250,"max_current_a":2,"dimensions_mm":"37 x 14 x 65","color":"Weiß","pack_size":3,"pack_price_eur":8.99,"unit_price_eur":3.00}',
                  NULL, NULL);

-- 18) Artecho Acrylfarben-Set Professional 12×500 ml — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'Artecho Acrylfarben-Set Professional 12×500 ml',
                  'Acrylfarben-Set, 12 Farben à 500 ml. Wasserfest, lichtecht, schnelltrocknend, glänzendes Finish. Für Leinwand, Holz, Stoff, Leder, Stein u. a. Säurefrei, ungiftig (ASTM D-4236, EN71).',
                  1, 'Set', NULL,
                  '{"brand":"Artecho","type":"Acrylfarben-Set","colors":12,"volume_ml_each":500,"total_volume_l":6,"finish":"glänzend","properties":["wasserfest","lichtecht","schnelltrocknend","säurefrei","ungiftig"],"standards":["ASTM D-4236","EN71"],"color_list":["Titanweiß","Orange","Scharlachrot","Zitronengelb","Cerulean Blau","Saftgrün","Schwarz","Türkis","Violett","Gelber Ocker","Gebrannt Umbra","Gebrannt Siena"]}',
                  NULL, NULL);

-- 19) Kärcher Fenstersauger WV 4-4 Plus (ohne Akku) — Flur-Schrank (NEUER Lagerplatz)
CALL sp_seed_item(@uid, '/Wohnung/Flur/Schrank', 'shelf',
                  'Kärcher Fenstersauger WV 4-4 Plus (ohne Akku)',
                  'Akku-Fenstersauger, 150 ml Schmutzwassertank, LED-Akkustatusanzeige, 280 mm Absaugdüse, Silikon-Abziehlippe. Bis 40 min mit Battery Power 4/25 (separat erhältlich). Inkl. Sprühflasche mit Mikrofaserbezug + 20 ml Fensterreiniger-Konzentrat. Ohne Akku. 1,18 kg. Weiß.',
                  1, 'Stück', NULL,
                  '{"brand":"Kärcher","model":"WV 4-4 Plus","model_number":"1.633-540.0","asin":"B0BW97TQGZ","type":"Akku-Fenstersauger","tank_ml":150,"squeegee_width_mm":280,"runtime_min":40,"battery":"Battery Power 4/25 (separat)","battery_included":false,"weight_kg":1.18,"color":"Weiß","includes":["WV 4-4 Plus","Sprühflasche mit Mikrofaserbezug","Fensterreiniger-Konzentrat 20 ml"]}',
                  NULL, NULL);

-- 20) Philips Luftreiniger 600-Serie (AC0651/10, weiß) — Schlafzimmer
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer', 'room',
                  'Philips Luftreiniger 600-Serie (AC0651/10, weiß)',
                  'Luftreiniger mit NanoProtect HEPA, 99,97 % Partikelabscheidung, für bis zu 44 m² (CADR 170 m³/h). AeraSense-Sensor, App-Steuerung (Air+, Android/iOS), 12 W, ab 19 dB. Kabelgebunden. 24,3 × 23,7 × 34,1 cm, 2,2 kg. Weiß.',
                  1, 'Stück', NULL,
                  '{"brand":"Philips","model":"600-Serie","model_number":"AC0651/10","asin":"B0CCF2NKCW","type":"Luftreiniger","filter":"NanoProtect HEPA","coverage_m2":44,"cadr_m3h":170,"power_w":12,"noise_db_min":19,"sensor":"AeraSense","app":"Air+","dimensions_cm":"24.3 x 23.7 x 34.1","weight_kg":2.2,"color":"Weiß","price_eur":99.99}',
                  NULL, NULL);

-- 21) TRALT Ergonomischer Bürostuhl (grau) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'TRALT Ergonomischer Bürostuhl (grau)',
                  'Ergonomischer Büro-/Gaming-Stuhl mit verstellbarer Lordosenstütze (4 cm), 2D-Kopfstütze (9 cm höhenverstellbar, 60° Winkel), Sitzhöhe +10 cm, Armlehnen (90° Neigung, 3 cm). Atmungsaktiver Netzrücken (S-Form), Tragkraft 150 kg, Fünf-Punkt-Metallgestell, 360° drehbar. Für 1,65–1,88 m. 70 × 70 × 120–125 cm. Grau. 5 Jahre Garantie.',
                  1, 'Stück', NULL,
                  '{"brand":"TRALT","type":"Bürostuhl (ergonomisch)","color":"Grau","load_capacity_kg":150,"lumbar_support":true,"headrest":"2D, 9 cm verstellbar","backrest":"Netz, S-Form","base":"5-Punkt Metall","dimensions_cm":"70 x 70 x 120-125","suitable_height_cm":"165-188","warranty_years":5,"price_eur":103.74}',
                  NULL, NULL);

-- 22) Tosiicop Deans T-Stecker Kabel-Set (5 Paar) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'Tosiicop Deans T-Stecker Kabel-Set (5 Paar)',
                  'Deans T-Plug Steckverbinder-Kabel, 5 Paar (5 Buchse + 5 Stecker). 14AWG Silikondraht, 40 mm (ohne Stecker), bis 25 A Dauerstrom. Für RC LiPo-Akkus, FPV-Drohne, RC-Auto/Boot. Vorverzinnte Pigtail-Enden.',
                  5, 'Paar', NULL,
                  '{"brand":"Tosiicop","type":"Deans T-Plug Steckverbinder","wire_awg":14,"wire_material":"Silikon","wire_length_mm":40,"max_current_a":25,"use":["RC LiPo","FPV-Drohne","RC-Auto","RC-Boot"],"availability":"derzeit nicht verfügbar"}',
                  NULL, NULL);

-- 23) Logitech MX Vertical Ergonomische Maus (Graphit) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'Logitech MX Vertical Ergonomische Maus (Graphit)',
                  'Ergonomische vertikale kabellose Maus, 57°-Winkel, 4000 DPI Optiksensor. Bluetooth + 2,4 GHz (Unifying USB-Empfänger), wiederaufladbar (bis 4 Monate), 4 Tasten, Multi-Device (3 Geräte), Logitech FLOW. Für PC/Mac/iPadOS. Graphit.',
                  1, 'Stück', NULL,
                  '{"brand":"Logitech","model":"MX Vertical","type":"Ergonomische Maus (vertikal)","color":"Graphit","dpi":4000,"angle_deg":57,"connectivity":["Bluetooth","2.4 GHz Unifying"],"rechargeable":true,"buttons":4,"multi_device":3,"platform":["PC","Mac","iPadOS"],"price_eur":57.45}',
                  NULL, NULL);

-- 24) BAKESHU Mini-Präzisions-Schraubendreher-Set 24-in-1 (schwarz) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'BAKESHU Mini-Präzisions-Schraubendreher-Set 24-in-1 (schwarz)',
                  'Mini-Präzisions-Schraubendreher-Set mit 24 Bits (Phillips, Flachkopf, Torx, Tri-Wing, Pentalobe, Hex). S2-Stahl-Bits, magnetisch, Alu-Griff mit 360°-Drehkappe, Edelstahl-Etui. Für Brillen, Uhren, Handys, Schmuck, Elektronik. Schwarz.',
                  1, 'Set', NULL,
                  '{"brand":"BAKESHU","type":"Präzisions-Schraubendreher-Set","bits":24,"bit_types":["Phillips","Flachkopf","Torx","Tri-Wing","Pentalobe","Hex"],"bit_material":"S2-Stahl","magnetic":true,"handle":"Aluminium, 360° Drehkappe","case":"Edelstahl-Etui","color":"Schwarz","price_eur":11.98}',
                  NULL, NULL);

-- 25) Kopp POWERversal 10-fach Steckdosenleiste (Silber-Schwarz) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'Kopp POWERversal 10-fach Steckdosenleiste (Silber-Schwarz)',
                  '10-fach Steckdosenleiste mit erhöhtem Berührungsschutz, IP20. 65 mm Abstand, 90°-gedrehte Anschlüsse, 1,4 m Zuleitung mit Winkelstecker. 250 V~, 16 A. 742,8 × 71 × 58,3 mm. Silber-Schwarz.',
                  1, 'Stück', NULL,
                  '{"brand":"Kopp","model":"POWERversal","type":"Steckdosenleiste","sockets":10,"protection":"IP20","voltage_v":250,"current_a":16,"cable_m":1.4,"dimensions_mm":"742.8 x 71 x 58.3","color":"Silber-Schwarz","price_eur":40.24,"list_price_eur":42.94}',
                  NULL, NULL);

-- 26) MARS HYDRO Growzelt 150×80×200 cm (schwarz) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'MARS HYDRO Growzelt 150×80×200 cm (schwarz)',
                  'Indoor-Growzelt, 150 × 80 × 200 cm. Hochreflektierendes Diamant-Mylar (1680D, doppelt genäht, lichtdicht), Stahlrahmen, abnehmbares Bodentablett, lichtdichte Doppelreißverschlüsse. Für Hydrokultur/Indoor-Anbau. Schwarz.',
                  1, 'Stück', NULL,
                  '{"brand":"MARS HYDRO","type":"Growzelt","size_cm":"150x80x200","interior":"Diamant-Mylar 1680D","frame":"Stahl","color":"Schwarz","features":["abnehmbares Bodentablett","lichtdicht","Doppelreißverschluss"],"price_eur":137.17,"list_price_eur":144.39}',
                  NULL, NULL);

-- 27) MARS HYDRO M6 Clip-Ventilator oszillierend (WiFi) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'MARS HYDRO M6 Clip-Ventilator oszillierend (WiFi)',
                  'Clip-Ventilator für Growzelt, oszillierend (45°/90°), 10 Windstufen, EC-Motor (50.000 h), max. 350 CFM, <32 dB, Fünf-Blatt-Design. WiFi-/App-Steuerung via iHub Pro. Clip-/Wandmontage. Silber.',
                  2, 'Stück', NULL,
                  '{"brand":"MARS HYDRO","model":"M6","type":"Clip-Ventilator","oscillating":true,"oscillation_deg":[45,90],"speeds":10,"max_airflow_cfm":350,"motor":"EC","noise_db":"<32","control":["WiFi","App","iHub Pro"],"color":"Silber","price_eur":41.50,"pack_price_eur":82.99,"list_price_eur":99.99}',
                  NULL, NULL);

-- 28) VooGenzek USB 2.0 Typ A 4-polig Stecker/Buchse-Set — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'VooGenzek USB 2.0 Typ A 4-polig Stecker/Buchse-Set',
                  'USB 2.0 Typ A Steckverbinder, 4-polig, je Satz männlich + weiblich, mit Kunststoffabdeckung. Für DIY-Kabel. 12 Sätze.',
                  12, 'Satz', NULL,
                  '{"brand":"VooGenzek","type":"USB 2.0 Typ A Steckverbinder","pins":4,"variant":"Stecker + Buchse","cover":"Kunststoff","use":"DIY-Kabel"}',
                  NULL, NULL);

-- 29) Reland Sun DC-Elektromagnet Push-Pull 12V (4 mm) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'Reland Sun DC-Elektromagnet Push-Pull 12V (4 mm)',
                  'Kleiner DC-Elektromagnet (Push-Pull / Hubmagnet), DC 12 V, 82 Ohm, Mikro-Hub 4 mm.',
                  1, 'Stück', NULL,
                  '{"brand":"Reland Sun","type":"DC-Elektromagnet (Push-Pull)","voltage_v":12,"resistance_ohm":82,"stroke_mm":4}',
                  NULL, NULL);

-- 30) KingSaid Mikrofonständer 2-in-1 (73–175 cm, schwarz) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'KingSaid Mikrofonständer 2-in-1 (73–175 cm, schwarz)',
                  '2-in-1 Dual-Use Mikrofonstativ (Boden-/Boom-Stativ), höhenverstellbar 73–175 cm, Dreibein, Schwenkarm. Inkl. 2 Mikrofonklemmen + Adapter. Metall/lackierter Stahl, schwarz. 1460 g.',
                  1, 'Stück', NULL,
                  '{"brand":"KingSaid","type":"Mikrofonständer","variant":"2-in-1 (Boden/Boom)","height_range_cm":"73-175","legs":"Dreibein","includes":["2 Mikrofonklemmen","Schwenkarm","Adapter"],"material":"Metall/Stahl","color":"Schwarz","weight_g":1460,"availability":"derzeit nicht verfügbar"}',
                  NULL, NULL);

-- 31) Jiusion WiFi USB Digitalmikroskop 50–1000x 4K (grau) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'Jiusion WiFi USB Digitalmikroskop 50–1000x 4K (grau)',
                  'Kabelloses/USB-Digitalmikroskop, 50–1000x Vergrößerung, 4K-Kamera (3840×2160P), 8 LEDs. WiFi + USB, inkl. Metallständer. Für iPhone/iPad/Android/Mac/Windows/Linux/Chrome. PC (Polycarbonat), 5 V. Grau.',
                  1, 'Stück', NULL,
                  '{"brand":"Jiusion","type":"Digitalmikroskop","magnification":"50-1000x","resolution":"4K 3840x2160P","leds":8,"connectivity":["WiFi","USB"],"voltage_v":5,"material":"Polycarbonat","stand":"Metall","color":"Grau","compatible":["iOS","Android","Mac","Windows","Linux","Chrome"],"price_eur":45.59,"list_price_eur":47.99}',
                  NULL, NULL);

-- 32) Joneytech Mini-Tischventilator oszillierend 4000mAh (weiß) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'Joneytech Mini-Tischventilator oszillierend 4000mAh (weiß)',
                  'Tischventilator, automatisch oszillierend (90° horizontal + 90° vertikal kippbar), 4 Geschwindigkeiten, bürstenloser Motor, leise. 4000 mAh Akku (bis 10 h), USB-C-Laden, LED-Digitalanzeige (Akkustand). Kompakt 13,8 × 7,5 × 18,3 cm, 345 g. Weiß.',
                  2, 'Stück', NULL,
                  '{"brand":"Joneytech","type":"Tischventilator","oscillating":"auto 90° H + 90° V","speeds":4,"motor":"bürstenlos","battery_mah":4000,"runtime_h":10,"charging":"USB-C","display":"LED (Akkustand)","dimensions_cm":"13.8 x 7.5 x 18.3","weight_g":345,"color":"Weiß","price_eur":26.99}',
                  NULL, NULL);

-- 33) Mairdi Wireless Headset M891 (Bluetooth 5.2, braun) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'Mairdi Wireless Headset M891 (Bluetooth 5.2, braun)',
                  'Kabelloses Bluetooth-5.2-Headset (QCC3024) mit Noise-Canceling-Mikrofon. Integrierte Ladestation mit Bluetooth-Empfänger (USB-C), Multipoint (2 Geräte), 40 h Gesprächszeit / 400 h Standby, 330° drehbarer Mikrofonarm, Over-Ear. Für PC/Laptop/Handy, Zoom/Teams/Skype. Braun. Inkl. USB-C-Ladekabel.',
                  1, 'Stück', NULL,
                  '{"brand":"Mairdi","model":"M891BPBT","type":"Bluetooth-Headset","bluetooth":"5.2 (QCC3024)","form":"Over-Ear","mic":"Noise Canceling, 330° drehbar","multipoint":2,"talk_time_h":40,"standby_h":400,"includes":["Headset","Ladestation mit BT-Empfänger","USB-C-Ladekabel"],"color":"Braun","availability":"derzeit nicht verfügbar"}',
                  NULL, NULL);

-- 34) SANSI 36W LED-Pflanzenlampe Vollspektrum E27 (BR30) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'SANSI 36W LED-Pflanzenlampe Vollspektrum E27 (BR30)',
                  'LED-Pflanzenlampe / Grow Light, 36 W, Vollspektrum (400–780 nm), E27-Sockel, Bauform BR30. Max. PPFD 268 μmol/s/m²@1ft, COC-Technologie (Chip on Ceramic), Keramikkörper (V0 flammhemmend). Ø11,5 × 13,3 cm. Für Indoor, Gewächshaus, Hydrokultur.',
                  3, 'Stück', NULL,
                  '{"brand":"SANSI","type":"LED-Pflanzenlampe","power_w":36,"spectrum":"Vollspektrum 400-780 nm","socket":"E27","bulb":"BR30","ppfd":"268 μmol/s/m²@1ft","tech":"COC (Chip on Ceramic)","dimensions_cm":"Ø11.5 x 13.3","price_eur":39.99,"list_price_eur":49.99}',
                  NULL, NULL);

-- 35) CQRobot TDS-Sensor (Raspberry Pi/Arduino) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'CQRobot TDS-Sensor (Raspberry Pi/Arduino)',
                  'TDS-Sensor (Total Dissolved Solids) zur Wasserqualitäts-Analyse, kompatibel mit Raspberry Pi/Arduino. Eingang 3,3–5,5 V, Analogausgang 0–2,3 V, AC-Anregung, wasserdichte Sonde, Plug-and-play (ohne Löten). Empfohlen mit ADS1115 16-Bit ADC.',
                  1, 'Stück', NULL,
                  '{"brand":"CQRobot","type":"TDS-Sensor","measures":"Total Dissolved Solids","platform":["Raspberry Pi","Arduino"],"input_v":"3.3-5.5","output_v":"0-2.3 analog","probe":"wasserdicht","note":"empfohlen mit ADS1115 16-Bit ADC","price_eur":11.99}',
                  NULL, NULL);

-- 36) Hilitand pH-Sensor-Modul mit Sonde (0–14, BNC) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'Hilitand pH-Sensor-Modul mit Sonde (0–14, BNC)',
                  'pH-Messmodul mit Sonde, Messbereich 0–14 pH, Testtemperatur 0–80 °C, BNC-Anschluss, ABS-Gehäuse. Inkl. Modul, Sonde, 4 Schrauben, 4 Muttern, 2 Ringe. Für industrielle Wasseranalyse.',
                  1, 'Stück', NULL,
                  '{"brand":"Hilitand","type":"pH-Sensor-Modul","range_ph":"0-14","temp_range_c":"0-80","connector":"BNC","material":"ABS","includes":["Modul","Sonde","4x Schraube","4x Mutter","2x Ring"],"price_eur":33.25}',
                  NULL, NULL);

-- 37) AOTOINK 30 m 3-poliges Verlängerungskabel 22AWG (LED RGB) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'AOTOINK 30 m 3-poliges Verlängerungskabel 22AWG (LED RGB)',
                  '3-adriges Verlängerungskabel, 30 m, 22 AWG (3×0,32 mm²), verzinntes Kupfer, PVC (−30…+80 °C). Für LED-Streifen WS2812B/WS2811/3528/5050, 12/24 V DC. Farben rot/grün/weiß.',
                  1, 'Rolle', NULL,
                  '{"brand":"AOTOINK","type":"Verlängerungskabel (LED)","conductors":3,"awg":22,"cross_section_mm2":0.32,"length_m":30,"material":"verzinntes Kupfer / PVC","voltage_v":"12/24 DC","use":["WS2812B","WS2811","3528","5050"],"colors":["rot","grün","weiß"],"price_eur":15.99}',
                  NULL, NULL);

-- 38) Seagate Portable Drive 4TB (STGX4000400) — Schreibtisch
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Schreibtisch', 'shelf',
                  'Seagate Portable Drive 4TB (STGX4000400)',
                  'Tragbare externe Festplatte, 4 TB, 2,5 Zoll, USB 3.0 (abwärtskompatibel USB 2.0), Plug-and-play. Inkl. Datenrettungsdienst. Schwarz matt.',
                  1, 'Stück', NULL,
                  '{"brand":"Seagate","model":"STGX4000400","type":"Externe Festplatte (HDD)","capacity_tb":4,"form_factor":"2.5 Zoll","interface":"USB 3.0","color":"Schwarz matt","includes":["Datenrettungsdienst"],"price_eur":144.99,"list_price_eur":168.99}',
                  NULL, NULL);

-- 39) TP-Link TL-WPA4220 KIT WLAN-Powerline-Set (AV600) — Wohnzimmer
CALL sp_seed_item(@uid, '/Wohnung/Wohnzimmer', 'room',
                  'TP-Link TL-WPA4220 KIT WLAN-Powerline-Set (AV600)',
                  'Powerline-Adapter-Set (AV600, 600 Mbit/s) mit WLAN 300 Mbit/s, Wi-Fi Clone, Fast-Ethernet-LAN, Plug&Play. Kompatibel mit HomePlug AV/AV2. Inkl. TL-WPA4220 + TL-PA4010, 2× RJ45-Kabel, Anleitung. Weiß.',
                  1, 'Stück', '790069396618',
                  '{"brand":"TP-Link","model":"TL-WPA4220 KIT","type":"Powerline-Adapter-Set","powerline_mbps":600,"wifi_mbps":300,"standard":"HomePlug AV/AV2 (AV600)","upc":"790069396618","color":"Weiß","includes":["TL-WPA4220","TL-PA4010","2x RJ45-Kabel","Anleitung"],"price_eur":53.46,"list_price_eur":69.90}',
                  NULL, NULL);

-- 40) ANJIELO SMART Wireless Bridge Punkt-zu-Punkt (800 m) — Bastelschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Bastelschrank', 'shelf',
                  'ANJIELO SMART Wireless Bridge Punkt-zu-Punkt (800 m)',
                  'Drahtlose Punkt-zu-Punkt-Brücke, bis 800 m outdoor (≈300 m indoor durch 4 Wände). Für IP-Kameras, Überwachung, Computer. Auto-Pairing, Punkt-zu-Punkt/Multipoint. Wasserdicht, staubgeschützt, blitzsicher. Inkl. Brücken-Paar, Netzwerkkabel, Stromkabel, Anleitung.',
                  1, 'Set', NULL,
                  '{"brand":"ANJIELO SMART","type":"Wireless Bridge (Punkt-zu-Punkt)","band":"2.4G / 863-868 MHz (Angaben widersprüchlich)","range_outdoor_m":800,"range_indoor_m":300,"use":["IP-Kamera","Überwachung","Computer"],"weatherproof":"wasserdicht/staubgeschützt/blitzsicher","includes":["Brücken-Paar","Netzwerkkabel","Stromkabel","Anleitung"],"price_eur":64.99}',
                  NULL, NULL);

-- 41) MARS HYDRO ADLITE RB55 LED-Pflanzenlampen-Set (2× Red + 2× Blue, 55 W) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'MARS HYDRO ADLITE RB55 LED-Pflanzenlampen-Set (2× Red + 2× Blue, 55 W)',
                  'ADLITE RB55 LED-Pflanzenlampen-Leisten (Zusatzbeleuchtung). Spektrum 440 nm (blau) & 660 nm (rot), je 55 W, Abdeckung 120×60 cm. Rot für Blütephase, Blau für vegetative Phase. Metall, gebürstet. Lieferumfang: 2× Red, 2× Blue, 4 Halterungen, 8 Aufhängeseile.',
                  1, 'Set', NULL,
                  '{"brand":"MARS HYDRO","model":"ADLITE RB55","type":"LED-Pflanzenlampe (Leisten)","spectrum_nm":[440,660],"power_w_each":55,"coverage_cm":"120x60","contents":["2x ADLITE Red","2x ADLITE Blue","4x Halterung","8x Aufhängeseil"],"variant":"Red55&Blue55","price_eur":95.99,"list_price_eur":119.99}',
                  NULL, NULL);

-- 42) Newentor Luftentfeuchter 25L/24h (weiß) — Schlafzimmer
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer', 'room',
                  'Newentor Luftentfeuchter 25L/24h (weiß)',
                  'Luftentfeuchter, 25 L/24h, für bis zu 80 m² / 215 m³. TCL×Newentor Kompressor, 3-fache Geräuschreduktion, ~295 W (Turbo). 3,5 L Tank, 1 m Ablaufschlauch, abnehmbarer Filter. 24h-Timer, Kindersicherung, Auto-Abtau, Überlaufschutz, Neustart nach Stromausfall. 27 × 36 × 49 cm. Weiß. 10 Jahre Garantie.',
                  1, 'Stück', NULL,
                  '{"brand":"Newentor","type":"Luftentfeuchter","capacity_l_day":25,"coverage_m2":80,"coverage_m3":215,"power_w":295,"tank_l":3.5,"hose_m":1,"features":["24h-Timer","Kindersicherung","Auto-Abtau","Überlaufschutz","Neustart nach Stromausfall","abnehmbarer Filter"],"dimensions_cm":"27 x 36 x 49","color":"Weiß","warranty_years":10,"price_eur":249.99}',
                  NULL, NULL);

-- 43) ProfiCare Hemdenbügler PC-HBB 3117 (1400 W, schwarz) — Kleiderschrank
CALL sp_seed_item(@uid, '/Wohnung/Schlafzimmer/Kleiderschrank', 'shelf',
                  'ProfiCare Hemdenbügler PC-HBB 3117 (1400 W, schwarz)',
                  'Automatischer Hemdenbügler / Bügelpuppe, 1400 W. One-Size-Ballonkörper (XS–XXL), inkl. Duftaufsatz, höhenverstellbare Teleskopstange. Trocknen und Bügeln in einem Schritt. 26,6 × 32,7 × 20,2 cm. Schwarz.',
                  1, 'Stück', NULL,
                  '{"brand":"ProfiCare","model":"PC-HBB 3117","type":"Hemdenbügler (Bügelpuppe)","power_w":1400,"sizes":"One Size XS-XXL","includes":["Duftaufsatz","Teleskopstange"],"dimensions_cm":"26.6 x 32.7 x 20.2","color":"Schwarz","price_eur":87.49}',
                  NULL, NULL);

-- 44) Rixto Stahl-Dachträger BS + IRON2 (VW Golf 6, schwarz) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'Rixto Stahl-Dachträger BS + IRON2 (VW Golf 6, schwarz)',
                  'Stahl-Dachgepäckträger (Paar), Serie BS + IRON2, für VW Golf VI 2008–2013 (3/5 Türen) ohne Dachreling. Länge 130 cm, Stangenprofil 60 × 27 mm, max. Tragkraft 75 kg. TÜV-GS/City-Crash-Zulassung, 3 Jahre Garantie, hergestellt in Turin. Inkl. Montagewerkzeug + Anleitung. Schwarz.',
                  1, 'Paar', NULL,
                  '{"brand":"Rixto","model":"BS + IRON2","type":"Dachträger (Stahl)","vehicle":"VW Golf VI 2008-2013 (3/5 Türen)","length_cm":130,"bar_profile_mm":"60x27","max_load_kg":75,"certification":"TÜV-GS/City-Crash","warranty_years":3,"origin":"Turin, Italien","color":"Schwarz","price_eur":121.00}',
                  NULL, NULL);

-- 45) Einhell TC-VC 1812 S Nass-Trockensauger — Wohnzimmer
CALL sp_seed_item(@uid, '/Wohnung/Wohnzimmer', 'room',
                  'Einhell TC-VC 1812 S Nass-Trockensauger',
                  'Nass-Trockensauger, 1250 W, max. 180 mbar, 12 L Edelstahlbehälter, 36 mm Schlauchsystem, 78 dB(A). Schaumstofffilter (nass), Faltenfilter + Schmutzfangsack (trocken), Kombidüse, Fugendüse, integrierter Blasanschluss, 4 Rollen, Kabelhalterung (2,5 m). Rot/Schwarz/Weiß.',
                  1, 'Stück', NULL,
                  '{"brand":"Einhell","model":"TC-VC 1812 S","type":"Nass-Trockensauger","power_w":1250,"suction_mbar":180,"tank_l":12,"tank_material":"Edelstahl","hose_mm":36,"noise_db":78,"cable_m":2.5,"features":["Blasanschluss","4 Rollen","Schaumstofffilter","Faltenfilter","Schmutzfangsack","Kombidüse","Fugendüse"],"variant":"altes Modell","color":"Rot/Schwarz/Weiß","price_eur":39.90,"list_price_eur":49.95}',
                  NULL, NULL);

-- 46) Hon&Guan 150mm Inline-Rohrventilator regelbar (587 m³/h, schwarz) — Keller
CALL sp_seed_item(@uid, '/Wohnung/Keller', 'room',
                  'Hon&Guan 150mm Inline-Rohrventilator regelbar (587 m³/h, schwarz)',
                  'Inline-Rohrventilator / Kanalventilator, 150 mm, 587 m³/h, 3300 U/min, max. 19,2 W, 142 Pa, 32–60 dB. Stufenlos regelbar (Drehzahlregler), DC-Motor, EU-Stecker. Für Wintergarten, Gewächshaus, Keller, Garage, Küche, Bad. 14,7 × 14,7 × 18,5 cm. Schwarz.',
                  1, 'Stück', NULL,
                  '{"brand":"Hon&Guan","type":"Inline-Rohrventilator","diameter_mm":150,"airflow_m3h":587,"rpm":3300,"power_w":19.2,"pressure_pa":142,"noise_db":"32-60","speed_control":true,"motor":"DC","plug":"EU","dimensions_cm":"14.7 x 14.7 x 18.5","color":"Schwarz","price_eur":36.99}',
                  NULL, NULL);


-- ---------------------------------------------------------------------------
-- 3. Aufräumen: Helfer-Prozeduren entfernen
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_seed_location;
DROP PROCEDURE IF EXISTS sp_seed_item;
DROP PROCEDURE IF EXISTS sp_seed_ensure_location;

-- ---------------------------------------------------------------------------
-- 4. Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('warehouse', '1.4.0', 'Items-Seed: Produktdaten + automatische Lagerplatz-Hierarchie')
ON DUPLICATE KEY UPDATE
  version     = '1.4.0',
  applied_at  = CURRENT_TIMESTAMP,
  description = 'Items-Seed: Produktdaten + automatische Lagerplatz-Hierarchie';

-- ============================================================================
-- Verifizierung (nach Ausführung manuell prüfen)
-- ============================================================================
--   SELECT i.items_id, i.name, i.quantity, i.unit, l.path
--   FROM   mbc_warehouse_items i
--   LEFT JOIN mbc_warehouse_locations l ON l.locations_id = i.location_id
--   WHERE  i.user_id = (SELECT users_id FROM mbc_users WHERE username = 'Oliver')
--   ORDER BY l.path, i.name;
-- ============================================================================


-- >>> aus: 38_warehouse_packlists.sql [seed-Teil: Permissions/Nav] ------------------------------------------------------------
-- (seed-Teil aus 38_warehouse_packlists.sql)
START TRANSACTION;
INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Packlisten-Vorlagen
('warehouse.packlist_templates.read.own',   'warehouse_packlist_templates', 'read',   'own', 'Eigene Packlisten-Vorlagen anzeigen'),
('warehouse.packlist_templates.read.any',   'warehouse_packlist_templates', 'read',   'any', 'Beliebige Vorlagen anzeigen (Admin)'),
('warehouse.packlist_templates.create',     'warehouse_packlist_templates', 'create', NULL,  'Packlisten-Vorlagen erstellen'),
('warehouse.packlist_templates.update.own', 'warehouse_packlist_templates', 'update', 'own', 'Eigene Vorlagen bearbeiten'),
('warehouse.packlist_templates.delete.own', 'warehouse_packlist_templates', 'delete', 'own', 'Eigene Vorlagen löschen'),

-- Packlisten (Instanzen + Verleih-Workflow)
('warehouse.packlists.read.own',   'warehouse_packlists', 'read',   'own', 'Eigene Packlisten anzeigen'),
('warehouse.packlists.read.any',   'warehouse_packlists', 'read',   'any', 'Beliebige Packlisten anzeigen (Admin)'),
('warehouse.packlists.create',     'warehouse_packlists', 'create', NULL,  'Packlisten erstellen'),
('warehouse.packlists.update.own', 'warehouse_packlists', 'update', 'own', 'Eigene Packlisten bearbeiten'),
('warehouse.packlists.delete.own', 'warehouse_packlists', 'delete', 'own', 'Eigene Packlisten löschen'),
('warehouse.packlists.pack.own',   'warehouse_packlists', 'pack',   'own', 'Packen/Rückgabe abhaken (Reservierung)');


-- User + Moderator: eigene Vorlagen/Packlisten verwalten
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('user', 'moderator')
AND p.name IN (
  'warehouse.packlist_templates.read.own',
  'warehouse.packlist_templates.create',
  'warehouse.packlist_templates.update.own',
  'warehouse.packlist_templates.delete.own',
  'warehouse.packlists.read.own',
  'warehouse.packlists.create',
  'warehouse.packlists.update.own',
  'warehouse.packlists.delete.own',
  'warehouse.packlists.pack.own'
);

-- Admin / Super Admin: alle Berechtigungen für die neuen Ressourcen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('warehouse_packlist_templates', 'warehouse_packlists');


-- ============================================================================
-- Navigation: Sub-Einträge unter "Lager" (/warehouse)
-- ============================================================================

SET @warehouse_nav_id = (
  SELECT navigations_id FROM mbc_navigations
  WHERE route = '/warehouse' AND parent_id IS NULL
  LIMIT 1
);

INSERT IGNORE INTO `mbc_navigations` (`parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`)
VALUES (@warehouse_nav_id, 'Packlisten', 'list_alt', '/warehouse/packlists', 2, 1);

INSERT IGNORE INTO `mbc_navigations` (`parent_id`, `title`, `icon`, `route`, `sort_order`, `is_active`)
VALUES (@warehouse_nav_id, 'Vorlagen', 'content_copy', '/warehouse/templates', 3, 1);

-- Rollen-Zuordnung der neuen Sub-Nav-Einträge
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route IN ('/warehouse/packlists', '/warehouse/templates')
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;


-- >>> aus: 39_warehouse_nav_stock.sql ------------------------------------------------------------
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

