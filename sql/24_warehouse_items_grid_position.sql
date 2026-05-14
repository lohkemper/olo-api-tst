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
