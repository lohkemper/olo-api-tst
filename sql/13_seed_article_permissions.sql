-- Seed Article Permissions
-- Adds article-specific permissions to the system

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Insert Article Permissions
--

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('articles.read', 'articles', 'read', NULL, 'Artikel lesen'),
('articles.create', 'articles', 'create', NULL, 'Neue Artikel erstellen'),
('articles.update.own', 'articles', 'update', 'own', 'Eigene Artikel bearbeiten'),
('articles.update.any', 'articles', 'update', 'any', 'Beliebige Artikel bearbeiten'),
('articles.delete.own', 'articles', 'delete', 'own', 'Eigene Artikel löschen'),
('articles.delete.any', 'articles', 'delete', 'any', 'Beliebige Artikel löschen'),
('articles.publish', 'articles', 'publish', NULL, 'Artikel veröffentlichen');

--
-- Assign Permissions to Roles
--

-- Guest: Read articles
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'guest'
AND p.name IN ('articles.read');

-- User: Read + Create + Edit/Delete own
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN ('articles.read', 'articles.create', 'articles.update.own', 'articles.delete.own');

-- Moderator: + Edit/Delete any + Publish
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN ('articles.update.any', 'articles.delete.any', 'articles.publish');

-- Admin & Super Admin: All article permissions (inherited from moderator + explicit)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource = 'articles';

COMMIT;
