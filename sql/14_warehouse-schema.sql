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
