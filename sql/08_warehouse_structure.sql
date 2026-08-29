-- ============================================================================
-- MBC - Warehouse - Struktur
-- ============================================================================
-- Locations/Items/Tags, Trigger/Views, Typo-Migration, Masse/Grid, Packlisten-Tabellen.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- >>> aus: 14_warehouse-schema.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse Module - Database Schema
-- ============================================================================
-- Version: 1.0.0
-- Erstellt: 2025-12-06
-- Beschreibung: Hierarchisches Warenlager-System mit Plätzen und Items
-- ============================================================================

-- Tabelle: mbc_warehouse_locations
-- Beschreibung: Hierarchische Lagerplätze (Tree-Struktur)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_locations (
  -- Primary Key
  locations_id INT AUTO_INCREMENT PRIMARY KEY,

  -- Basis-Daten
  name VARCHAR(255) NOT NULL COMMENT 'Name des Platzes',
  parent_id INT DEFAULT NULL COMMENT 'Parent-ID (NULL = Root)',
  path VARCHAR(1000) DEFAULT NULL COMMENT 'Vollständiger Pfad (z.B. "/Keller/Regal 1/Schachtel A")',
  level INT DEFAULT 0 COMMENT 'Tiefe im Baum (0 = Root)',

  -- Optionale Daten
  type VARCHAR(50) DEFAULT NULL COMMENT 'Typ: room, shelf, box, slot',
  description TEXT COMMENT 'Optionale Beschreibung',
  meta JSON DEFAULT NULL COMMENT 'Flexible Metadaten (JSON)',

  -- User-Zuordnung
  user_id INT NOT NULL COMMENT 'Besitzer des Platzes',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_location_parent
    FOREIGN KEY (parent_id)
    REFERENCES mbc_warehouse_locations(locations_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_location_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_parent_id (parent_id),
  INDEX idx_user_id (user_id),
  INDEX idx_path (path(255)),
  INDEX idx_level (level),
  INDEX idx_type (type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hierarchische Lagerplätze (Warehouse-Modul)';


-- ============================================================================
-- Tabelle: mbc_warehouse_items
-- Beschreibung: Lagerartikel/Objekte mit optionaler Platzzuordnung
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_items (
  -- Primary Key
  items_id INT AUTO_INCREMENT PRIMARY KEY,

  -- Basis-Daten
  name VARCHAR(255) NOT NULL COMMENT 'Name des Objekts',
  description TEXT COMMENT 'Optionale Beschreibung',

  -- Location-Zuordnung
  location_id INT DEFAULT NULL COMMENT 'Zugewiesener Platz (NULL = unassigned)',

  -- Mengen & Einheiten
  quantity DECIMAL(10,2) DEFAULT 1.00 COMMENT 'Menge',
  unit VARCHAR(50) DEFAULT 'Stück' COMMENT 'Einheit (z.B. Stück, kg, m)',

  -- Identifikation
  barcode VARCHAR(255) DEFAULT NULL COMMENT 'Barcode/Seriennummer',

  -- Medien
  image_url VARCHAR(500) DEFAULT NULL COMMENT 'Bild-URL',

  -- Flexible Metadaten
  meta JSON DEFAULT NULL COMMENT 'Flexible Metadaten (JSON)',

  -- User-Zuordnung
  user_id INT NOT NULL COMMENT 'Besitzer des Objekts',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_item_location
    FOREIGN KEY (location_id)
    REFERENCES mbc_warehouse_locations(locations_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  CONSTRAINT fk_item_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_location_id (location_id),
  INDEX idx_user_id (user_id),
  INDEX idx_barcode (barcode),
  INDEX idx_name (name(100))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lagerartikel/Objekte (Warehouse-Modul)';


-- ============================================================================
-- Tabelle: mbc_warehouse_item_tags
-- Beschreibung: Junction Table für Item-Tags (N:M-Relation)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_warehouse_item_tags (
  -- Composite Primary Key
  items_id INT NOT NULL,
  tag_id INT NOT NULL,

  -- Timestamp
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  -- Primary Key
  PRIMARY KEY (items_id, tag_id),

  -- Constraints
  CONSTRAINT fk_item_tag_item
    FOREIGN KEY (items_id)
    REFERENCES mbc_warehouse_items(items_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_item_tag_tag
    FOREIGN KEY (tag_id)
    REFERENCES mbc_tags(tags_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_tag_id (tag_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Item-Tags Junction Table (Warehouse-Modul)';


-- ============================================================================
-- Trigger: Auto-Berechnung von path und level bei Location-Insert
-- ============================================================================

DELIMITER $$

CREATE TRIGGER trg_warehouse_location_before_insert
BEFORE INSERT ON mbc_warehouse_locations
FOR EACH ROW
BEGIN
  DECLARE parent_path VARCHAR(1000);
  DECLARE parent_level INT;

  -- Wenn parent_id gesetzt ist, hole Parent-Daten
  IF NEW.parent_id IS NOT NULL THEN
    SELECT path, level INTO parent_path, parent_level
    FROM mbc_warehouse_locations
    WHERE locations_id = NEW.parent_id;

    -- Berechne neuen Path und Level
    SET NEW.path = CONCAT(COALESCE(parent_path, ''), '/', NEW.name);
    SET NEW.level = parent_level + 1;
  ELSE
    -- Root-Node
    SET NEW.path = CONCAT('/', NEW.name);
    SET NEW.level = 0;
  END IF;
END$$

DELIMITER ;


-- ============================================================================
-- Trigger: Auto-Update von path und level bei Location-Update
-- ============================================================================

DELIMITER $$

CREATE TRIGGER trg_warehouse_location_before_update
BEFORE UPDATE ON mbc_warehouse_locations
FOR EACH ROW
BEGIN
  DECLARE parent_path VARCHAR(1000);
  DECLARE parent_level INT;

  -- Nur wenn parent_id oder name geändert wurde
  IF NEW.parent_id != OLD.parent_id OR NEW.name != OLD.name THEN
    IF NEW.parent_id IS NOT NULL THEN
      SELECT path, level INTO parent_path, parent_level
      FROM mbc_warehouse_locations
      WHERE locations_id = NEW.parent_id;

      SET NEW.path = CONCAT(COALESCE(parent_path, ''), '/', NEW.name);
      SET NEW.level = parent_level + 1;
    ELSE
      SET NEW.path = CONCAT('/', NEW.name);
      SET NEW.level = 0;
    END IF;
  END IF;
END$$

DELIMITER ;


-- ============================================================================
-- Stored Procedure: Rekursives Update aller Children nach Location-Move
-- ============================================================================

DELIMITER $$

CREATE PROCEDURE sp_update_warehouse_location_children(IN p_location_id INT)
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE child_id INT;
  DECLARE cur CURSOR FOR
    SELECT locations_id FROM mbc_warehouse_locations WHERE parent_id = p_location_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  -- Hole alle direkten Children
  OPEN cur;

  read_loop: LOOP
    FETCH cur INTO child_id;
    IF done THEN
      LEAVE read_loop;
    END IF;

    -- Update Child (Trigger berechnet path/level automatisch)
    UPDATE mbc_warehouse_locations
    SET updated_at = CURRENT_TIMESTAMP
    WHERE locations_id = child_id;

    -- Rekursiv für Children's Children
    CALL sp_update_warehouse_location_children(child_id);
  END LOOP;

  CLOSE cur;
END$$

DELIMITER ;


-- ============================================================================
-- Views für vereinfachte Queries
-- ============================================================================

-- View: Locations mit Item-Count
CREATE OR REPLACE VIEW vw_warehouse_locations_with_item_count AS
SELECT
  l.*,
  COUNT(i.items_id) AS item_count
FROM mbc_warehouse_locations l
LEFT JOIN mbc_warehouse_items i ON l.locations_id = i.location_id
GROUP BY l.locations_id;


-- View: Items mit Location-Path
CREATE OR REPLACE VIEW vw_warehouse_items_with_location AS
SELECT
  i.*,
  l.name AS location_name,
  l.path AS location_path,
  l.level AS location_level
FROM mbc_warehouse_items i
LEFT JOIN mbc_warehouse_locations l ON i.location_id = l.locations_id;


-- View: Items mit Tags (aggregiert)
CREATE OR REPLACE VIEW vw_warehouse_items_with_tags AS
SELECT
  i.items_id,
  i.name,
  i.description,
  i.location_id,
  i.quantity,
  i.unit,
  i.barcode,
  i.image_url,
  i.user_id,
  i.created_at,
  i.updated_at,
  GROUP_CONCAT(
    JSON_OBJECT('id', t.tags_id, 'name', t.name)
    SEPARATOR ','
  ) AS tags_json
FROM mbc_warehouse_items i
LEFT JOIN mbc_warehouse_item_tags it ON i.items_id = it.items_id
LEFT JOIN mbc_tags t ON it.tag_id = t.tags_id
GROUP BY i.items_id;


-- ============================================================================
-- Test-Daten (optional - kann auskommentiert werden)
-- ============================================================================

-- Beispiel: Test-User (falls noch nicht vorhanden)
-- INSERT IGNORE INTO mbc_users (users_id, username, email) VALUES (1, 'testuser', 'test@example.com');

-- Beispiel: Root-Locations
-- INSERT INTO mbc_warehouse_locations (name, parent_id, type, user_id) VALUES
--   ('Keller', NULL, 'room', 1),
--   ('Garage', NULL, 'room', 1),
--   ('Büro', NULL, 'room', 1);

-- Beispiel: Sub-Locations
-- SET @keller_id = LAST_INSERT_ID();
-- INSERT INTO mbc_warehouse_locations (name, parent_id, type, user_id) VALUES
--   ('Regal 1', @keller_id, 'shelf', 1),
--   ('Regal 2', @keller_id, 'shelf', 1);

-- Beispiel: Items
-- INSERT INTO mbc_warehouse_items (name, description, location_id, quantity, unit, user_id) VALUES
--   ('HDMI Kabel', '2m, schwarz', NULL, 3, 'Stück', 1),
--   ('Arduino Uno', 'Mikrocontroller Board', NULL, 1, 'Stück', 1);


-- ============================================================================
-- Schema-Version-Tracking
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_schema_versions (
  module VARCHAR(50) PRIMARY KEY,
  version VARCHAR(20) NOT NULL,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('warehouse', '1.0.0', 'Initial schema mit Locations, Items, Tags')
ON DUPLICATE KEY UPDATE
  version = '1.0.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Initial schema mit Locations, Items, Tags';


-- ============================================================================
-- Ende des Schemas
-- ============================================================================


-- >>> aus: 18_migrate_warehaouse_to_warehouse.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse Module - Migration: Rename "warehouse" to "warehouse"
-- ============================================================================
-- Version: 1.1.0
-- Erstellt: 2025-12-24
-- Beschreibung: Umbenennung aller Datenbank-Objekte von "warehouse" zu "warehouse"
-- ============================================================================

-- WICHTIG: Erstellen Sie vor der Ausführung ein vollständiges Backup!
--
-- Dieses Script benennt folgende Objekte um:
-- - Tabellen: mbc_warehouse_* → mbc_warehouse_*
-- - Trigger: trg_warehouse_* → trg_warehouse_*
-- - Stored Procedures: sp_update_warehouse_* → sp_update_warehouse_*
-- - Views: vw_warehouse_* → vw_warehouse_*
--
-- Hinweis: Die Umbenennung erfolgt in einer festgelegten Reihenfolge,
-- um Foreign Key Constraints korrekt zu handhaben.

-- ============================================================================
-- Schritt 1: Drop abhängige Views
-- ============================================================================

DROP VIEW IF EXISTS vw_warehouse_items_with_tags;
DROP VIEW IF EXISTS vw_warehouse_items_with_location;
DROP VIEW IF EXISTS vw_warehouse_locations_with_item_count;

-- ============================================================================
-- Schritt 2: Drop Trigger
-- ============================================================================

DROP TRIGGER IF EXISTS trg_warehouse_location_before_update;
DROP TRIGGER IF EXISTS trg_warehouse_location_before_insert;

-- ============================================================================
-- Schritt 3: Drop Stored Procedure
-- ============================================================================

DROP PROCEDURE IF EXISTS sp_update_warehouse_location_children;

-- ============================================================================
-- Schritt 4: Tabellen umbenennen
-- ============================================================================
-- Reihenfolge: Zuerst Junction Table, dann Items, dann Locations
-- (wegen Foreign Key Dependencies)

ALTER TABLE mbc_warehouse_item_tags RENAME TO mbc_warehouse_item_tags;
ALTER TABLE mbc_warehouse_items RENAME TO mbc_warehouse_items;
ALTER TABLE mbc_warehouse_locations RENAME TO mbc_warehouse_locations;

-- ============================================================================
-- Schritt 5: Trigger neu erstellen (mit neuen Namen und Tabellennamen)
-- ============================================================================

DELIMITER $$

CREATE TRIGGER trg_warehouse_location_before_insert
BEFORE INSERT ON mbc_warehouse_locations
FOR EACH ROW
BEGIN
  DECLARE parent_path VARCHAR(1000);
  DECLARE parent_level INT;

  -- Wenn parent_id gesetzt ist, hole Parent-Daten
  IF NEW.parent_id IS NOT NULL THEN
    SELECT path, level INTO parent_path, parent_level
    FROM mbc_warehouse_locations
    WHERE locations_id = NEW.parent_id;

    -- Berechne neuen Path und Level
    SET NEW.path = CONCAT(COALESCE(parent_path, ''), '/', NEW.name);
    SET NEW.level = parent_level + 1;
  ELSE
    -- Root-Node
    SET NEW.path = CONCAT('/', NEW.name);
    SET NEW.level = 0;
  END IF;
END$$

DELIMITER ;

-- ============================================================================

DELIMITER $$

CREATE TRIGGER trg_warehouse_location_before_update
BEFORE UPDATE ON mbc_warehouse_locations
FOR EACH ROW
BEGIN
  DECLARE parent_path VARCHAR(1000);
  DECLARE parent_level INT;

  -- Nur wenn parent_id oder name geändert wurde
  IF NEW.parent_id != OLD.parent_id OR NEW.name != OLD.name THEN
    IF NEW.parent_id IS NOT NULL THEN
      SELECT path, level INTO parent_path, parent_level
      FROM mbc_warehouse_locations
      WHERE locations_id = NEW.parent_id;

      SET NEW.path = CONCAT(COALESCE(parent_path, ''), '/', NEW.name);
      SET NEW.level = parent_level + 1;
    ELSE
      SET NEW.path = CONCAT('/', NEW.name);
      SET NEW.level = 0;
    END IF;
  END IF;
END$$

DELIMITER ;

-- ============================================================================
-- Schritt 6: Stored Procedure neu erstellen (mit neuem Namen und Tabellennamen)
-- ============================================================================

DELIMITER $$

CREATE PROCEDURE sp_update_warehouse_location_children(IN p_location_id INT)
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE child_id INT;
  DECLARE cur CURSOR FOR
    SELECT locations_id FROM mbc_warehouse_locations WHERE parent_id = p_location_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  -- Hole alle direkten Children
  OPEN cur;

  read_loop: LOOP
    FETCH cur INTO child_id;
    IF done THEN
      LEAVE read_loop;
    END IF;

    -- Update Child (Trigger berechnet path/level automatisch)
    UPDATE mbc_warehouse_locations
    SET updated_at = CURRENT_TIMESTAMP
    WHERE locations_id = child_id;

    -- Rekursiv für Children's Children
    CALL sp_update_warehouse_location_children(child_id);
  END LOOP;

  CLOSE cur;
END$$

DELIMITER ;

-- ============================================================================
-- Schritt 7: Views neu erstellen (mit neuen Namen und Tabellennamen)
-- ============================================================================

-- View: Locations mit Item-Count
CREATE OR REPLACE VIEW vw_warehouse_locations_with_item_count AS
SELECT
  l.*,
  COUNT(i.items_id) AS item_count
FROM mbc_warehouse_locations l
LEFT JOIN mbc_warehouse_items i ON l.locations_id = i.location_id
GROUP BY l.locations_id;

-- View: Items mit Location-Path
CREATE OR REPLACE VIEW vw_warehouse_items_with_location AS
SELECT
  i.*,
  l.name AS location_name,
  l.path AS location_path,
  l.level AS location_level
FROM mbc_warehouse_items i
LEFT JOIN mbc_warehouse_locations l ON i.location_id = l.locations_id;

-- View: Items mit Tags (aggregiert)
CREATE OR REPLACE VIEW vw_warehouse_items_with_tags AS
SELECT
  i.items_id,
  i.name,
  i.description,
  i.location_id,
  i.quantity,
  i.unit,
  i.barcode,
  i.image_url,
  i.user_id,
  i.created_at,
  i.updated_at,
  GROUP_CONCAT(
    JSON_OBJECT('id', t.tags_id, 'name', t.name)
    SEPARATOR ','
  ) AS tags_json
FROM mbc_warehouse_items i
LEFT JOIN mbc_warehouse_item_tags it ON i.items_id = it.items_id
LEFT JOIN mbc_tags t ON it.tag_id = t.tags_id
GROUP BY i.items_id;

-- ============================================================================
-- Schritt 8: Schema-Version aktualisieren
-- ============================================================================

-- Update existing entry if it exists
UPDATE mbc_schema_versions
SET
  module = 'warehouse',
  version = '1.1.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Renamed from warehouse to warehouse (typo correction)'
WHERE module = 'warehouse';

-- Fallback: Insert new entry if update didn't affect any rows
INSERT INTO mbc_schema_versions (module, version, description)
SELECT 'warehouse', '1.1.0', 'Renamed from warehouse to warehouse (typo correction)'
WHERE NOT EXISTS (
  SELECT 1 FROM mbc_schema_versions WHERE module = 'warehouse'
);

-- ============================================================================
-- Schritt 9: Verifizierung
-- ============================================================================

-- Überprüfen Sie nach der Migration folgende Punkte:
-- 1. Tabellen existieren:
--    SELECT * FROM information_schema.tables WHERE table_name LIKE 'mbc_warehouse%';
--
-- 2. Trigger existieren:
--    SHOW TRIGGERS LIKE 'mbc_warehouse_locations';
--
-- 3. Stored Procedures existieren:
--    SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name LIKE '%warehouse%';
--
-- 4. Views existieren:
--    SELECT * FROM information_schema.views WHERE table_name LIKE 'vw_warehouse%';
--
-- 5. Daten intakt:
--    SELECT COUNT(*) FROM mbc_warehouse_locations;
--    SELECT COUNT(*) FROM mbc_warehouse_items;
--    SELECT COUNT(*) FROM mbc_warehouse_item_tags;

-- ============================================================================
-- Ende der Migration
-- ============================================================================

-- Erfolgreiche Migration!
-- Nächste Schritte:
-- 1. Verifizieren Sie die Datenbank-Objekte (siehe Schritt 9)
-- 2. Deployen Sie den neuen Frontend/Backend-Code
-- 3. Testen Sie alle Warehouse-Funktionen
-- 4. Bei Problemen: Restore aus Backup


-- >>> aus: 20_warehouse_locations_dimensions.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse - Locations: Maße + Grid (Zeilen/Spalten)
-- ============================================================================
-- Erstellt: 2026-05-03
-- Beschreibung: Erweitert mbc_warehouse_locations um:
--   - grid_rows, grid_cols  : Anzahl Zeilen/Spalten der Bereiche im Lagerplatz
--   - width_cm, height_cm,
--     depth_cm              : Außenmaße (Breite × Höhe × Tiefe in cm)
--
-- Idempotent: Spalten-Adds sind via INFORMATION_SCHEMA-Check abgesichert.
-- Voraussetzung: 14_warehouse-schema.sql ausgeführt.
-- ============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Spalten anlegen (idempotent über prepared dynamic SQL)
-- ---------------------------------------------------------------------------

-- Hinweis: PREFIX-Abstraktion ist PHP-seitig; in SQL gehen wir vom konkreten
-- Tabellennamen aus, der bereits in 14_warehouse-schema.sql verwendet wird.
SET @schema := DATABASE();
SET @tbl    := 'mbc_warehouse_locations';

-- grid_rows
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @tbl AND COLUMN_NAME = 'grid_rows'
);
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `grid_rows` INT UNSIGNED DEFAULT NULL ',
         'COMMENT ''Anzahl Zeilen der Bereiche'' AFTER `meta`'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- grid_cols
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @tbl AND COLUMN_NAME = 'grid_cols'
);
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `grid_cols` INT UNSIGNED DEFAULT NULL ',
         'COMMENT ''Anzahl Spalten der Bereiche'' AFTER `grid_rows`'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- width_cm
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @tbl AND COLUMN_NAME = 'width_cm'
);
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `width_cm` INT UNSIGNED DEFAULT NULL ',
         'COMMENT ''Breite in cm'' AFTER `grid_cols`'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- height_cm
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @tbl AND COLUMN_NAME = 'height_cm'
);
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `height_cm` INT UNSIGNED DEFAULT NULL ',
         'COMMENT ''Höhe in cm'' AFTER `width_cm`'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- depth_cm
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @tbl AND COLUMN_NAME = 'depth_cm'
);
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `depth_cm` INT UNSIGNED DEFAULT NULL ',
         'COMMENT ''Tiefe in cm'' AFTER `height_cm`'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('warehouse', '1.1.0', 'Locations: grid_rows, grid_cols, width_cm, height_cm, depth_cm')
ON DUPLICATE KEY UPDATE
  version     = '1.1.0',
  applied_at  = CURRENT_TIMESTAMP,
  description = 'Locations: grid_rows, grid_cols, width_cm, height_cm, depth_cm';

COMMIT;


-- >>> aus: 23_warehouse_typo_trigger_cleanup.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse - Trigger/Proc/View-Cleanup nach typo-Renames
-- ============================================================================
-- Erstellt: 2026-05-03
-- Beschreibung: Auf Prod existiert die korrekt benannte Tabelle
--   `mbc_warehouse_locations`, aber Trigger/Procs/Views referenzieren
--   intern teilweise noch den alten Typo-Namen `mbc_warehaouse_*`. Beim
--   INSERT mit `parent_id != NULL` schlägt der BEFORE-INSERT-Trigger fehl mit:
--     "Table 'mbc_warehaouse_locations' doesn't exist"
--
--   Die ursprüngliche typo-cleanup-Migration (18_*) ist durch ein
--   Search-Replace beschädigt worden (RENAME-Statements sind No-ops).
--
--   Diese Migration:
--     1. Droppt alle warehouse-bezogenen Trigger / Procedures / Views
--        — sowohl mit typo (`*warehaouse*`) als auch ohne, defensiv.
--     2. Erstellt Trigger / Procedure / Views frisch mit den korrekten
--        Tabellennamen (`mbc_warehouse_*`).
--
-- Idempotent: alle DROPs nutzen IF EXISTS, alle CREATEs überschreiben sich.
-- Voraussetzung: Tabelle `mbc_warehouse_locations` existiert (sie tut's auf
-- Prod laut Diagnose; Spalten via 20_warehouse_locations_dimensions.sql).
-- WICHTIG: Vor Ausführung Backup empfohlen.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Typo-/Alt-Objekte droppen
-- ---------------------------------------------------------------------------

DROP TRIGGER IF EXISTS trg_warehaouse_location_before_insert;
DROP TRIGGER IF EXISTS trg_warehaouse_location_before_update;
DROP TRIGGER IF EXISTS trg_warehouse_location_before_insert;
DROP TRIGGER IF EXISTS trg_warehouse_location_before_update;

DROP PROCEDURE IF EXISTS sp_update_warehaouse_location_children;
DROP PROCEDURE IF EXISTS sp_update_warehouse_location_children;

DROP VIEW IF EXISTS vw_warehaouse_locations_with_item_count;
DROP VIEW IF EXISTS vw_warehaouse_items_with_location;
DROP VIEW IF EXISTS vw_warehaouse_items_with_tags;
DROP VIEW IF EXISTS vw_warehouse_locations_with_item_count;
DROP VIEW IF EXISTS vw_warehouse_items_with_location;
DROP VIEW IF EXISTS vw_warehouse_items_with_tags;

-- ---------------------------------------------------------------------------
-- 2. Trigger neu anlegen — referenzieren ausschließlich korrekt benannte Tabellen
-- ---------------------------------------------------------------------------

DELIMITER $$

CREATE TRIGGER trg_warehouse_location_before_insert
BEFORE INSERT ON mbc_warehouse_locations
FOR EACH ROW
BEGIN
  DECLARE parent_path VARCHAR(1000);
  DECLARE parent_level INT;

  IF NEW.parent_id IS NOT NULL THEN
    SELECT path, level INTO parent_path, parent_level
    FROM mbc_warehouse_locations
    WHERE locations_id = NEW.parent_id;

    SET NEW.path = CONCAT(COALESCE(parent_path, ''), '/', NEW.name);
    SET NEW.level = parent_level + 1;
  ELSE
    SET NEW.path = CONCAT('/', NEW.name);
    SET NEW.level = 0;
  END IF;
END$$

CREATE TRIGGER trg_warehouse_location_before_update
BEFORE UPDATE ON mbc_warehouse_locations
FOR EACH ROW
BEGIN
  DECLARE parent_path VARCHAR(1000);
  DECLARE parent_level INT;

  IF NEW.parent_id != OLD.parent_id OR NEW.name != OLD.name THEN
    IF NEW.parent_id IS NOT NULL THEN
      SELECT path, level INTO parent_path, parent_level
      FROM mbc_warehouse_locations
      WHERE locations_id = NEW.parent_id;

      SET NEW.path = CONCAT(COALESCE(parent_path, ''), '/', NEW.name);
      SET NEW.level = parent_level + 1;
    ELSE
      SET NEW.path = CONCAT('/', NEW.name);
      SET NEW.level = 0;
    END IF;
  END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------------
-- 3. Stored Procedure neu anlegen
-- ---------------------------------------------------------------------------

DELIMITER $$

CREATE PROCEDURE sp_update_warehouse_location_children(IN p_location_id INT)
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE child_id INT;
  DECLARE cur CURSOR FOR
    SELECT locations_id FROM mbc_warehouse_locations WHERE parent_id = p_location_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  OPEN cur;

  read_loop: LOOP
    FETCH cur INTO child_id;
    IF done THEN
      LEAVE read_loop;
    END IF;

    UPDATE mbc_warehouse_locations
    SET updated_at = CURRENT_TIMESTAMP
    WHERE locations_id = child_id;

    CALL sp_update_warehouse_location_children(child_id);
  END LOOP;

  CLOSE cur;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------------
-- 4. Views neu anlegen — alle aus korrekten Tabellen
-- ---------------------------------------------------------------------------

CREATE OR REPLACE VIEW vw_warehouse_locations_with_item_count AS
SELECT
  l.*,
  COUNT(i.items_id) AS item_count
FROM mbc_warehouse_locations l
LEFT JOIN mbc_warehouse_items i ON l.locations_id = i.location_id
GROUP BY l.locations_id;

CREATE OR REPLACE VIEW vw_warehouse_items_with_location AS
SELECT
  i.*,
  l.name  AS location_name,
  l.path  AS location_path,
  l.level AS location_level
FROM mbc_warehouse_items i
LEFT JOIN mbc_warehouse_locations l ON i.location_id = l.locations_id;

CREATE OR REPLACE VIEW vw_warehouse_items_with_tags AS
SELECT
  i.items_id,
  i.name,
  i.description,
  i.location_id,
  i.quantity,
  i.unit,
  i.barcode,
  i.image_url,
  i.user_id,
  i.created_at,
  i.updated_at,
  GROUP_CONCAT(
    JSON_OBJECT('id', t.tags_id, 'name', t.name)
    SEPARATOR ','
  ) AS tags_json
FROM mbc_warehouse_items i
LEFT JOIN mbc_warehouse_item_tags it ON i.items_id = it.items_id
LEFT JOIN mbc_tags t ON it.tag_id = t.tags_id
GROUP BY i.items_id;

-- ---------------------------------------------------------------------------
-- 5. Schema-Version
-- ---------------------------------------------------------------------------

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('warehouse', '1.2.0', 'Cleanup: typo-Trigger/Procs/Views entfernt, Definitionen frisch verankert')
ON DUPLICATE KEY UPDATE
  version     = '1.2.0',
  applied_at  = CURRENT_TIMESTAMP,
  description = 'Cleanup: typo-Trigger/Procs/Views entfernt, Definitionen frisch verankert';

-- ---------------------------------------------------------------------------
-- 6. Verifizierung (optional manuell auszuführen)
-- ---------------------------------------------------------------------------
--
-- Sollten KEINE Treffer mehr ergeben:
--   SHOW TRIGGERS WHERE `Trigger` LIKE '%warehaouse%';
--   SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name LIKE '%warehaouse%';
--   SELECT table_name FROM information_schema.views WHERE table_name LIKE '%warehaouse%';
--
-- Trigger-Definitionen prüfen (sollten "mbc_warehouse_locations" enthalten,
-- nicht "mbc_warehaouse_locations"):
--   SHOW CREATE TRIGGER trg_warehouse_location_before_insert\G
--   SHOW CREATE TRIGGER trg_warehouse_location_before_update\G


-- >>> aus: 24_warehouse_items_grid_position.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse - Items: Position im Bereiche-Raster (Zeile / Spalte)
-- ============================================================================
-- Erstellt: 2026-05-03
-- Beschreibung: Erweitert mbc_warehouse_items um:
--   - grid_row : Zeilenposition innerhalb der Matrix des Lagerplatzes (1-basiert)
--   - grid_col : Spaltenposition innerhalb der Matrix des Lagerplatzes (1-basiert)
--
-- Beide Felder sind nullable: ein Item kann einem Lagerplatz zugewiesen sein,
-- ohne eine konkrete Position zu haben (z.B. wenn der Platz keine Matrix hat
-- oder die Position noch nicht festgelegt wurde).
--
-- Idempotent: Spalten-Adds sind via INFORMATION_SCHEMA-Check abgesichert.
-- Voraussetzung: 14_warehouse-schema.sql + 20_warehouse_locations_dimensions.sql
-- bereits ausgeführt.
-- ============================================================================

-- Hinweis: Tabellenname konkret (kein PHP-PREFIX in SQL).
SET @schema := DATABASE();
SET @tbl    := 'mbc_warehouse_items';

-- grid_row
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @tbl AND COLUMN_NAME = 'grid_row'
);
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `grid_row` INT UNSIGNED DEFAULT NULL ',
         'COMMENT ''Zeilenposition im Lagerplatz-Raster (1-basiert)'' AFTER `meta`'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- grid_col
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @tbl AND COLUMN_NAME = 'grid_col'
);
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `grid_col` INT UNSIGNED DEFAULT NULL ',
         'COMMENT ''Spaltenposition im Lagerplatz-Raster (1-basiert)'' AFTER `grid_row`'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('warehouse', '1.3.0', 'Items: grid_row, grid_col für Position im Lagerplatz-Raster')
ON DUPLICATE KEY UPDATE
  version     = '1.3.0',
  applied_at  = CURRENT_TIMESTAMP,
  description = 'Items: grid_row, grid_col für Position im Lagerplatz-Raster';


-- >>> aus: 38_warehouse_packlists.sql [struct-Teil: Tabellen] ------------------------------------------------------------
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


-- >>> Teilmengen-Zuordnung Item <-> Location (Multi-Location-Split) ----------------------------------------------------
-- ============================================================================
-- MBC Warehouse Module - Item-Location-Splits (n:m mit Teilmengen)
-- ============================================================================
-- Version: 1.4.0
-- Erstellt: 2026-08-28
-- Beschreibung: Ein Item kann auf MEHRERE Lagerplätze verteilt werden
--   (z.B. 4 ESPs: 2 an Location A, 2 an Location B).
--
--   Modell:
--   - mbc_warehouse_items.quantity bleibt der physische GESAMT-Bestand
--     (Packlisten-Ledger unverändert: available = quantity - reserviert).
--   - mbc_warehouse_item_locations hält die Teilmengen je Lagerplatz.
--     Invariante: SUM(quantity je Item) <= items.quantity; Rest = unassigned.
--   - mbc_warehouse_items.location_id bleibt als denormalisierte
--     Primär-Location erhalten (Zuordnung mit größter Menge, bei Gleichstand
--     kleinste item_locations_id) und wird vom Backend gepflegt.
--   - UNIQUE(item_id, location_id): genau eine Zeile pro Item+Location;
--     Assign auf belegte Location = Mengen-Merge (Upsert).
--
-- Signedness: items_id / locations_id / users_id sind auf Prod INT UNSIGNED —
--   alle FK-Spalten hier MÜSSEN exakt matchen, sonst Fehler 1005 (errno 150).
--
-- Voraussetzung: Warehouse-Grundschema (Items + Locations), 00_create_users_table.sql
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS mbc_warehouse_item_locations (
  item_locations_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL COMMENT 'Denormalisiert für RLS-Filter',
  item_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,

  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Teilmenge an diesem Lagerplatz',
  grid_row INT UNSIGNED DEFAULT NULL COMMENT 'Zeilenposition im Lagerplatz-Raster (1-basiert)',
  grid_col INT UNSIGNED DEFAULT NULL COMMENT 'Spaltenposition im Lagerplatz-Raster (1-basiert)',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_wh_il_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_wh_il_item
    FOREIGN KEY (item_id)
    REFERENCES mbc_warehouse_items(items_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- CASCADE: Location weg => Teilmenge wird automatisch "unassigned"
  -- (Rest-Semantik; Primär-Recompute übernimmt der Backend-Handler)
  CONSTRAINT fk_wh_il_location
    FOREIGN KEY (location_id)
    REFERENCES mbc_warehouse_locations(locations_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  UNIQUE KEY uq_wh_il_item_location (item_id, location_id),
  INDEX idx_wh_il_user (user_id),
  INDEX idx_wh_il_location (location_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Teilmengen-Zuordnung Item <-> Lagerplatz (n:m, Multi-Location-Split)';

-- ---------------------------------------------------------------------------
-- Backfill: bisherige Einzel-Zuordnung als eine Junction-Zeile mit voller Menge
-- (idempotent: nur Items ohne bestehende Junction-Zeilen).
-- EXISTS-Guards auf mbc_users/mbc_warehouse_locations: auf Prod existieren
-- verwaiste Items (user_id ohne User — fk_item_user fehlt dort, Schema-Drift);
-- die würden sonst mit errno 1452 den ganzen Backfill abbrechen. Waisen sind
-- in der App unsichtbar (RLS-Filter) und werden bewusst übersprungen.
-- ---------------------------------------------------------------------------
INSERT INTO mbc_warehouse_item_locations (user_id, item_id, location_id, quantity, grid_row, grid_col)
SELECT i.user_id, i.items_id, i.location_id, i.quantity, i.grid_row, i.grid_col
FROM mbc_warehouse_items i
WHERE i.location_id IS NOT NULL
  AND EXISTS (
    SELECT 1 FROM mbc_users u WHERE u.users_id = i.user_id
  )
  AND EXISTS (
    SELECT 1 FROM mbc_warehouse_locations l WHERE l.locations_id = i.location_id
  )
  AND NOT EXISTS (
    SELECT 1 FROM mbc_warehouse_item_locations il WHERE il.item_id = i.items_id
  );

COMMIT;

-- ---------------------------------------------------------------------------
-- Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('warehouse', '1.4.0', 'Item-Location-Splits: mbc_warehouse_item_locations (Teilmengen je Lagerplatz) + Backfill')
ON DUPLICATE KEY UPDATE
  version     = '1.4.0',
  applied_at  = CURRENT_TIMESTAMP,
  description = 'Item-Location-Splits: mbc_warehouse_item_locations (Teilmengen je Lagerplatz) + Backfill';

-- ============================================================================
-- Ende der Migration
-- ============================================================================


-- >>> FK-Drift-Cleanup: fehlende User-/Tag-FKs + Waisen ----------------------------------------------------------------
-- ============================================================================
-- MBC Warehouse Module - FK-Drift-Cleanup
-- ============================================================================
-- Version: 1.5.0
-- Erstellt: 2026-08-29
-- Beschreibung: Auf Prod fehlen drei Fremdschlüssel (Schema-Drift, beim
--   Multi-Location-Backfill via errno 1452 aufgefallen):
--     - mbc_warehouse_items.fk_item_user          -> mbc_users
--     - mbc_warehouse_locations.fk_location_user  -> mbc_users
--     - mbc_warehouse_item_tags.fk_item_tag_tag   -> mbc_tags
--   Dadurch existieren verwaiste Zeilen (user_id/tag_id ohne Gegenstück).
--   Waisen sind in der App unsichtbar (RLS-Filter auf user_id) und werden
--   gelöscht; danach werden die FKs idempotent nachgezogen.
--
--   Kaskaden beim Waisen-Delete (Prod-Stand verifiziert 2026-08-29):
--     - Location-Delete: Kinder CASCADE (fk_location_parent), Items werden
--       via fk_item_location auf NULL gesetzt, Splits CASCADE (fk_wh_il_location)
--     - Item-Delete: Tag-Links CASCADE (fk_item_tag_item), Splits CASCADE
--   Spaltentypen sind auf Prod bereits durchgängig INT UNSIGNED — kein ALTER
--   COLUMN nötig; die FK-Adds matchen exakt.
--
-- Idempotent: DELETEs sind selbst-neutralisierend, FK-Adds via
-- INFORMATION_SCHEMA-Check. WICHTIG: Vor Ausführung Backup empfohlen.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Waisen löschen (Reihenfolge: Locations vor Items, damit SET NULL /
--    CASCADE der bestehenden FKs sauber greifen)
-- ---------------------------------------------------------------------------

DELETE l FROM mbc_warehouse_locations l
LEFT JOIN mbc_users u ON u.users_id = l.user_id
WHERE u.users_id IS NULL;

DELETE i FROM mbc_warehouse_items i
LEFT JOIN mbc_users u ON u.users_id = i.user_id
WHERE u.users_id IS NULL;

DELETE it FROM mbc_warehouse_item_tags it
LEFT JOIN mbc_tags t ON t.tags_id = it.tag_id
WHERE t.tags_id IS NULL;

-- Defensiv: Split-Zeilen ohne User (sollte es dank fk_wh_il_user nicht geben)
DELETE il FROM mbc_warehouse_item_locations il
LEFT JOIN mbc_users u ON u.users_id = il.user_id
WHERE u.users_id IS NULL;

-- ---------------------------------------------------------------------------
-- 2. Fehlende FKs idempotent nachziehen (INFORMATION_SCHEMA-Check-Pattern)
-- ---------------------------------------------------------------------------

SET @schema := DATABASE();

-- fk_item_user: mbc_warehouse_items.user_id -> mbc_users.users_id
SET @fk_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'mbc_warehouse_items'
    AND CONSTRAINT_NAME = 'fk_item_user' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE mbc_warehouse_items ADD CONSTRAINT fk_item_user
     FOREIGN KEY (user_id) REFERENCES mbc_users(users_id)
     ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- fk_location_user: mbc_warehouse_locations.user_id -> mbc_users.users_id
SET @fk_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'mbc_warehouse_locations'
    AND CONSTRAINT_NAME = 'fk_location_user' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE mbc_warehouse_locations ADD CONSTRAINT fk_location_user
     FOREIGN KEY (user_id) REFERENCES mbc_users(users_id)
     ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- fk_item_tag_tag: mbc_warehouse_item_tags.tag_id -> mbc_tags.tags_id
SET @fk_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'mbc_warehouse_item_tags'
    AND CONSTRAINT_NAME = 'fk_item_tag_tag' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE mbc_warehouse_item_tags ADD CONSTRAINT fk_item_tag_tag
     FOREIGN KEY (tag_id) REFERENCES mbc_tags(tags_id)
     ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('warehouse', '1.5.0', 'FK-Drift-Cleanup: Waisen entfernt, fk_item_user/fk_location_user/fk_item_tag_tag nachgezogen')
ON DUPLICATE KEY UPDATE
  version     = '1.5.0',
  applied_at  = CURRENT_TIMESTAMP,
  description = 'FK-Drift-Cleanup: Waisen entfernt, fk_item_user/fk_location_user/fk_item_tag_tag nachgezogen';

-- ============================================================================
-- Ende der Migration
-- ============================================================================

