-- ======================================================================
-- Tabelle: mbc_roles
-- Beschreibung: Speichert alle Rollen im System (user, moderator, admin, etc.)
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_roles` (
  `roles_id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Technischer Name (z.B. admin, moderator)',
  `display_name` VARCHAR(100) NOT NULL COMMENT 'Anzeigename für UI',
  `description` TEXT NULL COMMENT 'Beschreibung der Rolle',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rollen-Tabelle für RBAC-System';
