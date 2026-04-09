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
