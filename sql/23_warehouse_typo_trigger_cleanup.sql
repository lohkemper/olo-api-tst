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
