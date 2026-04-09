-- ======================================================================
-- Tabelle: mbc_role_permissions
-- Beschreibung: Many-to-Many Beziehung zwischen Rollen und Permissions
-- Voraussetzung: mbc_roles und mbc_permissions müssen existieren
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_role_permissions` (
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`)
    REFERENCES `mbc_roles`(`roles_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `mbc_permissions`(`permissions_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  INDEX `idx_role_perm_role_id` (`role_id`),
  INDEX `idx_role_perm_permission_id` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rollen-Permissions-Zuweisungen (Many-to-Many)';
