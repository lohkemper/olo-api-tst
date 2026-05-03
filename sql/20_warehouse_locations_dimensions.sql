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
