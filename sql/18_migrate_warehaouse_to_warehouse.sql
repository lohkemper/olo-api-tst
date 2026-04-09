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
