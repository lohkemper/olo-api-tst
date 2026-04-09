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
