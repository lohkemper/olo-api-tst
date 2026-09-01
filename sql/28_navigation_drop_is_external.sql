-- 28: `is_external` aus mbc_navigations entfernen (2026-08-31).
--
-- Extern wird seither ausschließlich aus der Route abgeleitet: absolute
-- http(s)-URLs öffnen extern (Frontend: isExternalRoute in @olo/core/api-types
-- bzw. isAbsoluteUrl in der TopNav). Das Flag war dadurch redundant.
--
-- Idempotent: prüft vor dem DROP, ob die Spalte existiert.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mbc_navigations'
    AND COLUMN_NAME = 'is_external'
);

SET @ddl := IF(
  @col_exists > 0,
  'ALTER TABLE `mbc_navigations` DROP COLUMN `is_external`',
  'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
