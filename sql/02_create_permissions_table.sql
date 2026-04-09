-- ======================================================================
-- Tabelle: mbc_permissions
-- Beschreibung: Speichert alle Permissions/Berechtigungen im System
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_permissions` (
  `permissions_id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Permission-Name (z.B. articles.update.own)',
  `resource` VARCHAR(50) NOT NULL COMMENT 'Resource-Name (z.B. articles, users)',
  `action` VARCHAR(50) NOT NULL COMMENT 'Aktion (z.B. read, create, update, delete)',
  `scope` ENUM('own', 'any') NULL COMMENT 'Scope: own=eigene Ressourcen, any=alle Ressourcen',
  `description` TEXT NULL COMMENT 'Beschreibung der Permission',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_name` (`name`),
  INDEX `idx_resource_action` (`resource`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Permissions-Tabelle für RBAC/PBAC-System';
