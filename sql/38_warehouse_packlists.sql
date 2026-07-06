-- ============================================================================
-- MBC Warehouse Module - Packliste + Verleihservice
-- ============================================================================
-- Version: 1.1.0
-- Erstellt: 2026-07-04
-- Beschreibung: Packlisten-Vorlagen (Templates mit Gruppen), Packlisten-Instanzen
--   mit Verleih-Metadaten und Bestands-Reservierung (Ledger-Modell).
--
--   Reservierungs-Ledger (KEINE Extra-Tabelle): eine aktive Reservierung ist ein
--   Packlisten-Positions-Datensatz mit checked_out = 1 AND returned = 0. Damit gilt
--   available(item) = item.quantity - SUM(aktive packlist_items.quantity).
--   mbc_warehouse_items.quantity (physischer Bestand) wird NIE verändert.
--
-- Signedness: mbc_warehouse_items.items_id / mbc_warehouse_locations.locations_id /
--   mbc_users.users_id sind INT UNSIGNED (vgl. 31_gym_phase4.sql). Alle FK-Spalten
--   hier MÜSSEN exakt matchen, sonst MariaDB/MySQL-Fehler 1005 (errno 150).
--
-- Voraussetzung: 14_warehouse-schema.sql, 00_create_users_table.sql
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_warehouse_packlist_templates
-- Beschreibung: Wiederverwendbare Packlisten-Vorlagen ("wie ein Online-Shop")
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_packlist_templates (
  templates_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Besitzer der Vorlage',

  name VARCHAR(255) NOT NULL COMMENT 'Vorlagen-Name (z.B. "Zelt-Wochenende")',
  description TEXT DEFAULT NULL,
  meta JSON DEFAULT NULL COMMENT 'Flexible Metadaten (JSON)',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_wh_pl_template_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_wh_pl_template_user (user_id),
  INDEX idx_wh_pl_template_name (name(100))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Packlisten-Vorlagen (Warehouse-Verleih)';


-- ============================================================================
-- Tabelle: mbc_warehouse_packlist_template_groups
-- Beschreibung: Auswahl-Gruppen innerhalb einer Vorlage (z.B. "Zelt": 6P/4P/1P).
--   In der Packliste wird je Gruppe eine ODER mehrere Optionen gewählt
--   (selection_mode: 'multi' = Checkbox = Default, 'single' = Radio = genau eine).
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_packlist_template_groups (
  groups_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Denormalisiert für RLS-Filter',
  template_id INT UNSIGNED NOT NULL,

  name VARCHAR(255) NOT NULL COMMENT 'Gruppen-Name (z.B. "Zelt")',
  selection_mode ENUM('single','multi') NOT NULL DEFAULT 'multi'
    COMMENT 'single = genau eine Option, multi = eine oder mehrere',
  order_index INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Reihenfolge in der Vorlage',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_wh_pl_group_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_pl_group_template
    FOREIGN KEY (template_id)
    REFERENCES mbc_warehouse_packlist_templates(templates_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_wh_pl_group_user (user_id),
  INDEX idx_wh_pl_group_template (template_id),
  INDEX idx_wh_pl_group_order (template_id, order_index)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auswahl-Gruppen in Packlisten-Vorlagen (Warehouse-Verleih)';


-- ============================================================================
-- Tabelle: mbc_warehouse_packlist_template_items
-- Beschreibung: Positionen (Artikel + Menge) einer Vorlage; optional in einer Gruppe.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_packlist_template_items (
  template_items_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Denormalisiert für RLS-Filter',
  template_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = Standalone-Position (immer dabei)',
  item_id INT UNSIGNED NOT NULL,

  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Soll-Menge (Vorlage)',
  order_index INT UNSIGNED NOT NULL DEFAULT 0,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_wh_pl_titem_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_pl_titem_template
    FOREIGN KEY (template_id)
    REFERENCES mbc_warehouse_packlist_templates(templates_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_pl_titem_group
    FOREIGN KEY (group_id)
    REFERENCES mbc_warehouse_packlist_template_groups(groups_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_pl_titem_item
    FOREIGN KEY (item_id)
    REFERENCES mbc_warehouse_items(items_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_wh_pl_titem_user (user_id),
  INDEX idx_wh_pl_titem_template (template_id),
  INDEX idx_wh_pl_titem_group (group_id),
  INDEX idx_wh_pl_titem_item (item_id),
  INDEX idx_wh_pl_titem_order (template_id, order_index)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Positionen einer Packlisten-Vorlage (Warehouse-Verleih)';


-- ============================================================================
-- Tabelle: mbc_warehouse_packlists
-- Beschreibung: Konkrete Packliste (Instanz), optional aus einer Vorlage, mit
--   Verleih-Metadaten und Status-Workflow.
--   Workflow: draft → packing → packed → returning → closed (| cancelled)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_packlists (
  packlists_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Besitzer der Packliste',
  template_id INT UNSIGNED DEFAULT NULL COMMENT 'Ursprungs-Vorlage (NULL = frei erstellt)',

  name VARCHAR(255) NOT NULL,
  status ENUM('draft','packing','packed','returning','closed','cancelled')
    NOT NULL DEFAULT 'draft',

  -- Verleih-Metadaten
  borrower_name VARCHAR(255) DEFAULT NULL COMMENT 'Entleiher (Freitext)',
  borrower_contact VARCHAR(255) DEFAULT NULL COMMENT 'Kontakt (Freitext)',
  borrowed_at DATE DEFAULT NULL COMMENT 'Ausleihdatum',
  due_at DATE DEFAULT NULL COMMENT 'Fälligkeit / geplante Rückgabe',
  returned_at DATE DEFAULT NULL COMMENT 'Tatsächliches Rückgabedatum',

  notes TEXT DEFAULT NULL,
  meta JSON DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_wh_packlist_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_packlist_template
    FOREIGN KEY (template_id)
    REFERENCES mbc_warehouse_packlist_templates(templates_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  INDEX idx_wh_packlist_user (user_id),
  INDEX idx_wh_packlist_status (user_id, status),
  INDEX idx_wh_packlist_template (template_id),
  INDEX idx_wh_packlist_due (due_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Packlisten-Instanzen mit Verleih-Metadaten (Warehouse-Verleih)';


-- ============================================================================
-- Tabelle: mbc_warehouse_packlist_items
-- Beschreibung: Positionen einer Packliste. Aktive Reservierung (Ledger) =
--   checked_out = 1 AND returned = 0. item_name ist ein Snapshot für Historie,
--   falls der Original-Artikel später gelöscht wird (item_id → NULL).
--   source_location_id merkt den Heim-Lagerort beim Auschecken (für die Rückgabe).
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_packlist_items (
  packlist_items_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Denormalisiert für RLS-Filter',
  packlist_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED DEFAULT NULL COMMENT 'Original-Artikel (NULL falls gelöscht)',
  item_name VARCHAR(255) DEFAULT NULL COMMENT 'Snapshot des Artikelnamens (Historie)',
  group_label VARCHAR(255) DEFAULT NULL COMMENT 'Herkunfts-Gruppenname (Anzeige)',

  quantity_planned DECIMAL(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Soll-Menge aus Vorlage',
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Angepasste tatsächliche Menge',

  checked_out TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Phase A: gepackt/reserviert',
  checked_out_at DATETIME DEFAULT NULL,
  returned TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Phase B: zurückgeführt',
  returned_at DATETIME DEFAULT NULL,

  source_location_id INT UNSIGNED DEFAULT NULL COMMENT 'Heim-Lagerort (Snapshot beim Auschecken)',
  order_index INT UNSIGNED NOT NULL DEFAULT 0,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_wh_pli_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_pli_packlist
    FOREIGN KEY (packlist_id)
    REFERENCES mbc_warehouse_packlists(packlists_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_pli_item
    FOREIGN KEY (item_id)
    REFERENCES mbc_warehouse_items(items_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_pli_source_location
    FOREIGN KEY (source_location_id)
    REFERENCES mbc_warehouse_locations(locations_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  INDEX idx_wh_pli_user (user_id),
  INDEX idx_wh_pli_packlist (packlist_id),
  INDEX idx_wh_pli_item (item_id),
  -- Kern-Index für die Reservierungs-Aggregation available(item)
  INDEX idx_wh_pli_reservation (item_id, checked_out, returned),
  INDEX idx_wh_pli_order (packlist_id, order_index)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Positionen einer Packliste inkl. Reservierungs-Ledger (Warehouse-Verleih)';


-- ============================================================================
-- Permissions
-- ============================================================================

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

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('warehouse_packlists', '1.1.0', 'Packliste + Verleihservice (Templates, Packlists, Reservierungs-Ledger)')
ON DUPLICATE KEY UPDATE
  version = '1.1.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Packliste + Verleihservice (Templates, Packlists, Reservierungs-Ledger)';

-- ============================================================================
-- Ende der Migration
-- ============================================================================
