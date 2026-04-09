-- ======================================================================
-- Inserts: Navigation Permissions
-- Beschreibung: Fügt navigation.manage Permission in das System ein
-- Voraussetzung: mbc_permissions und mbc_role_permissions müssen existieren
-- ======================================================================

-- Navigation Permissions einfügen
INSERT INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('navigation.read', 'navigation', 'read', NULL, 'Navigationseinträge anzeigen'),
('navigation.create', 'navigation', 'create', NULL, 'Neue Navigationseinträge erstellen'),
('navigation.update', 'navigation', 'update', NULL, 'Navigationseinträge bearbeiten'),
('navigation.delete', 'navigation', 'delete', NULL, 'Navigationseinträge löschen'),
('navigation.manage', 'navigation', 'manage', NULL, 'Navigation und Rollen-Zuweisungen verwalten')
ON DUPLICATE KEY UPDATE
  `resource` = VALUES(`resource`),
  `action` = VALUES(`action`),
  `scope` = VALUES(`scope`),
  `description` = VALUES(`description`);

-- ======================================================================
-- Permission-Zuweisungen zu Rollen
-- ======================================================================

-- Admin-Rolle: Alle Navigation-Permissions
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'admin' AND p.name IN (
  'navigation.read',
  'navigation.create',
  'navigation.update',
  'navigation.delete',
  'navigation.manage'
);

-- Super-Admin-Rolle: Alle Navigation-Permissions
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'super_admin' AND p.name IN (
  'navigation.read',
  'navigation.create',
  'navigation.update',
  'navigation.delete',
  'navigation.manage'
);

-- Moderator-Rolle: Nur Lesen und Bearbeiten (optional)
-- INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
-- SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
-- WHERE r.name = 'moderator' AND p.name IN (
--   'navigation.read',
--   'navigation.update'
-- );
