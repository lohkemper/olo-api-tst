-- ======================================================================
-- Tabelle: mbc_user_roles
-- Beschreibung: Many-to-Many Beziehung zwischen Users und Rollen
-- Voraussetzung: mbc_users und mbc_roles müssen existieren
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_user_roles` (
  `user_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` INT UNSIGNED NULL COMMENT 'User-ID des Zuweisers (optional)',
  PRIMARY KEY (`user_id`, `role_id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`)
    REFERENCES `mbc_users`(`users_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`)
    REFERENCES `mbc_roles`(`roles_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User-Rollen-Zuweisungen (Many-to-Many)';
