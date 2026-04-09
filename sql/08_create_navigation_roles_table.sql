-- ======================================================================
-- Tabelle: mbc_navigation_roles
-- Beschreibung: Many-to-Many Beziehung zwischen Navigation und Rollen
--               Definiert welche Rollen Zugriff auf eine Navigation haben
-- Voraussetzung: mbc_navigation und mbc_roles müssen existieren
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_navigation_roles` (
  `navigations_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` INT UNSIGNED NULL COMMENT 'User-ID des Zuweisers (optional)',
  PRIMARY KEY (`navigations_id`, `role_id`),
  CONSTRAINT `fk_navigation_roles_navigation` FOREIGN KEY (`navigations_id`)
    REFERENCES `mbc_navigations`(`navigations_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_navigation_roles_role` FOREIGN KEY (`role_id`)
    REFERENCES `mbc_roles`(`roles_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  INDEX `idx_navigations_id` (`navigations_id`),
  INDEX `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Navigation-Rollen-Zuweisungen - Definiert Sichtbarkeit nach Rolle (Many-to-Many)';
