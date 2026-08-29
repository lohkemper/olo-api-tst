-- ============================================================================
-- MBC - Core / Auth - Struktur
-- ============================================================================
-- Users, Roles, Permissions, Zuordnungstabellen, ID-Rename-Migration, UI-Settings.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


CREATE TABLE IF NOT EXISTS `mbc_users` (
  `users_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(255) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NULL,
  `last_name` VARCHAR(100) NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` TIMESTAMP NULL,
  PRIMARY KEY (`users_id`),
  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Benutzer-Stammdaten';


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


-- ======================================================================
-- Migration: Umbenennung der ID-Spalten zum Schema {tabellenname}_id
-- Datum: 2025-11-22
-- Beschreibung:
--   - Benennt alle `id` Spalten um zu `{tabellenname}_id`
--   - Benennt mbc_navigation zu mbc_navigations um
--   - Aktualisiert alle Foreign Key Referenzen
-- ACHTUNG: Backup erstellen vor Ausführung!
-- ======================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ======================================================================
-- 1. Tabelle mbc_navigation zu mbc_navigations umbenennen
-- ======================================================================
RENAME TABLE `mbc_navigation` TO `mbc_navigations`;

-- ======================================================================
-- 2. mbc_users: id -> users_id
-- ======================================================================

-- Foreign Keys entfernen die auf mbc_users.id referenzieren
ALTER TABLE `mbc_user_roles` DROP FOREIGN KEY `fk_user_roles_user`;
ALTER TABLE `mbc_user_permissions` DROP FOREIGN KEY `fk_user_permissions_user`;

-- Spalte umbenennen
ALTER TABLE `mbc_users` CHANGE `id` `users_id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Foreign Keys neu erstellen
ALTER TABLE `mbc_user_roles`
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`)
    REFERENCES `mbc_users`(`users_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `mbc_user_permissions`
  ADD CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`)
    REFERENCES `mbc_users`(`users_id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- ======================================================================
-- 3. mbc_roles: id -> roles_id
-- ======================================================================

-- Foreign Keys entfernen die auf mbc_roles.id referenzieren
ALTER TABLE `mbc_user_roles` DROP FOREIGN KEY `fk_user_roles_role`;
ALTER TABLE `mbc_role_permissions` DROP FOREIGN KEY `fk_role_permissions_role`;
ALTER TABLE `mbc_navigation_roles` DROP FOREIGN KEY `fk_navigation_roles_role`;

-- Spalte umbenennen
ALTER TABLE `mbc_roles` CHANGE `id` `roles_id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Foreign Keys neu erstellen
ALTER TABLE `mbc_user_roles`
  ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`)
    REFERENCES `mbc_roles`(`roles_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `mbc_role_permissions`
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`)
    REFERENCES `mbc_roles`(`roles_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `mbc_navigation_roles`
  ADD CONSTRAINT `fk_navigation_roles_role` FOREIGN KEY (`role_id`)
    REFERENCES `mbc_roles`(`roles_id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- ======================================================================
-- 4. mbc_permissions: id -> permissions_id
-- ======================================================================

-- Foreign Keys entfernen die auf mbc_permissions.id referenzieren
ALTER TABLE `mbc_role_permissions` DROP FOREIGN KEY `fk_role_permissions_permission`;
ALTER TABLE `mbc_user_permissions` DROP FOREIGN KEY `fk_user_permissions_permission`;
ALTER TABLE `mbc_navigations` DROP FOREIGN KEY `fk_navigation_permission`;

-- Spalte umbenennen
ALTER TABLE `mbc_permissions` CHANGE `id` `permissions_id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Foreign Keys neu erstellen
ALTER TABLE `mbc_role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `mbc_permissions`(`permissions_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `mbc_user_permissions`
  ADD CONSTRAINT `fk_user_permissions_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `mbc_permissions`(`permissions_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `mbc_navigations`
  ADD CONSTRAINT `fk_navigations_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `mbc_permissions`(`permissions_id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ======================================================================
-- 5. mbc_navigations: id -> navigations_id
-- ======================================================================

-- Foreign Keys entfernen die auf mbc_navigations.id referenzieren
ALTER TABLE `mbc_navigations` DROP FOREIGN KEY `fk_navigation_parent`;
ALTER TABLE `mbc_navigation_roles` DROP FOREIGN KEY `fk_navigation_roles_navigation`;

-- Spalte umbenennen
ALTER TABLE `mbc_navigations` CHANGE `id` `navigations_id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Foreign Keys neu erstellen (parent_id referenziert sich selbst)
ALTER TABLE `mbc_navigations`
  ADD CONSTRAINT `fk_navigations_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `mbc_navigations`(`navigations_id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- navigation_roles: navigation_id -> navigations_id
ALTER TABLE `mbc_navigation_roles` CHANGE `navigation_id` `navigations_id` INT UNSIGNED NOT NULL;

-- Index aktualisieren
ALTER TABLE `mbc_navigation_roles` DROP INDEX `idx_navigation_id`;
ALTER TABLE `mbc_navigation_roles` ADD INDEX `idx_navigations_id` (`navigations_id`);

-- Primary Key aktualisieren
ALTER TABLE `mbc_navigation_roles` DROP PRIMARY KEY;
ALTER TABLE `mbc_navigation_roles` ADD PRIMARY KEY (`navigations_id`, `role_id`);

ALTER TABLE `mbc_navigation_roles`
  ADD CONSTRAINT `fk_navigation_roles_navigation` FOREIGN KEY (`navigations_id`)
    REFERENCES `mbc_navigations`(`navigations_id`) ON DELETE CASCADE ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- ======================================================================
-- Migration abgeschlossen
-- ======================================================================
SELECT 'Migration erfolgreich: ID-Spalten umbenannt zu {tabellenname}_id Schema' AS Status;


-- UI-Präferenzen pro Benutzer (Theme / Density / Accent).
-- Spec: design-refactor.md → 3.3 Theme- & User-Settings.
--
-- NULL = keine serverseitige Präferenz → der Client nutzt localStorage/Default
-- (theme=auto, density=comfortable, accent=sodium). Beim Login überschreiben
-- gesetzte Werte den lokalen Stand (UserSettingsService.hydrateFromUser).

ALTER TABLE `mbc_users`
  ADD COLUMN `theme`   VARCHAR(10) NULL DEFAULT NULL AFTER `last_login`,
  ADD COLUMN `density` VARCHAR(12) NULL DEFAULT NULL AFTER `theme`,
  ADD COLUMN `accent`  VARCHAR(12) NULL DEFAULT NULL AFTER `density`;

