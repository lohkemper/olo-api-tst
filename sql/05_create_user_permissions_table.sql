-- ======================================================================
-- Tabelle: mbc_user_permissions
-- Beschreibung: Many-to-Many Beziehung für individuelle User-Permissions
-- Verwendung: Für spezielle Berechtigungen einzelner User (optional)
-- Voraussetzung: mbc_users und mbc_permissions müssen existieren
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_user_permissions` (
  `user_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `granted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `granted_by` INT UNSIGNED NULL COMMENT 'User-ID des Gewährers (optional)',
  `expires_at` TIMESTAMP NULL COMMENT 'Ablaufdatum der Permission (optional)',
  PRIMARY KEY (`user_id`, `permission_id`),
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`)
    REFERENCES `mbc_users`(`users_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_user_permissions_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `mbc_permissions`(`permissions_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  INDEX `idx_user_perm_user_id` (`user_id`),
  INDEX `idx_user_perm_permission_id` (`permission_id`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individuelle User-Permissions (Many-to-Many, optional)';
