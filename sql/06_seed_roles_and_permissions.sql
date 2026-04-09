-- ======================================================================
-- Seed-Daten: Rollen und Permissions
-- Beschreibung: Fügt Standard-Rollen und Permissions in das System ein
-- ======================================================================

-- Standard-Rollen einfügen
INSERT INTO `mbc_roles` (`name`, `display_name`, `description`) VALUES
('guest', 'Guest', 'Nicht authentifizierte Benutzer - öffentlicher Zugriff'),
('user', 'User', 'Standard-Benutzer mit Basis-Berechtigungen'),
('moderator', 'Moderator', 'Community-Moderator mit erweiterten Rechten'),
('admin', 'Administrator', 'System-Administrator mit umfangreichen Rechten'),
('super_admin', 'Super Admin', 'Super-Administrator mit allen Systemrechten')
ON DUPLICATE KEY UPDATE
  `display_name` = VALUES(`display_name`),
  `description` = VALUES(`description`);

-- Standard-Permissions einfügen
INSERT INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Articles Permissions
('articles.read', 'articles', 'read', NULL, 'Artikel lesen (öffentlich)'),
('articles.create', 'articles', 'create', NULL, 'Neue Artikel erstellen'),
('articles.update.own', 'articles', 'update', 'own', 'Eigene Artikel bearbeiten'),
('articles.update.any', 'articles', 'update', 'any', 'Beliebige Artikel bearbeiten'),
('articles.delete.own', 'articles', 'delete', 'own', 'Eigene Artikel löschen'),
('articles.delete.any', 'articles', 'delete', 'any', 'Beliebige Artikel löschen'),
('articles.publish', 'articles', 'publish', NULL, 'Artikel veröffentlichen'),
('articles.feature', 'articles', 'feature', NULL, 'Artikel hervorheben'),

-- Users Permissions
('users.read', 'users', 'read', NULL, 'Benutzer-Profile lesen'),
('users.create', 'users', 'create', NULL, 'Neue Benutzer erstellen'),
('users.update.own', 'users', 'update', 'own', 'Eigenes Profil bearbeiten'),
('users.update.any', 'users', 'update', 'any', 'Beliebige Benutzer-Profile bearbeiten'),
('users.delete.own', 'users', 'delete', 'own', 'Eigenes Profil löschen'),
('users.delete.any', 'users', 'delete', 'any', 'Beliebige Benutzer löschen'),
('users.ban', 'users', 'ban', NULL, 'Benutzer sperren/bannen'),
('users.unban', 'users', 'unban', NULL, 'Benutzer-Sperre aufheben'),

-- Comments Permissions (optional, falls benötigt)
('comments.read', 'comments', 'read', NULL, 'Kommentare lesen'),
('comments.create', 'comments', 'create', NULL, 'Kommentare erstellen'),
('comments.update.own', 'comments', 'update', 'own', 'Eigene Kommentare bearbeiten'),
('comments.update.any', 'comments', 'update', 'any', 'Beliebige Kommentare bearbeiten'),
('comments.delete.own', 'comments', 'delete', 'own', 'Eigene Kommentare löschen'),
('comments.delete.any', 'comments', 'delete', 'any', 'Beliebige Kommentare löschen'),
('comments.moderate', 'comments', 'moderate', NULL, 'Kommentare moderieren'),

-- Admin & System Permissions
('admin.access', 'admin', 'access', NULL, 'Zugriff auf Admin-Bereich'),
('admin.dashboard', 'admin', 'dashboard', NULL, 'Admin-Dashboard anzeigen'),
('settings.manage', 'settings', 'manage', NULL, 'System-Einstellungen verwalten'),
('roles.read', 'roles', 'read', NULL, 'Rollen anzeigen'),
('roles.manage', 'roles', 'manage', NULL, 'Rollen erstellen, bearbeiten, löschen'),
('permissions.read', 'permissions', 'read', NULL, 'Permissions anzeigen'),
('permissions.manage', 'permissions', 'manage', NULL, 'Permissions verwalten'),

-- Files Permissions (optional)
('files.upload', 'files', 'upload', NULL, 'Dateien hochladen'),
('files.delete.own', 'files', 'delete', 'own', 'Eigene Dateien löschen'),
('files.delete.any', 'files', 'delete', 'any', 'Beliebige Dateien löschen')
ON DUPLICATE KEY UPDATE
  `resource` = VALUES(`resource`),
  `action` = VALUES(`action`),
  `scope` = VALUES(`scope`),
  `description` = VALUES(`description`);

-- ======================================================================
-- Permission-Zuweisungen zu Rollen
-- ======================================================================

-- Guest-Rolle (nur Lesen)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'guest' AND p.name IN (
  'articles.read',
  'comments.read'
);

-- User-Rolle (Basis-Berechtigungen)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user' AND p.name IN (
  'articles.read',
  'articles.create',
  'articles.update.own',
  'articles.delete.own',
  'comments.read',
  'comments.create',
  'comments.update.own',
  'comments.delete.own',
  'users.read',
  'users.update.own',
  'files.upload',
  'files.delete.own'
);

-- Moderator-Rolle (User + Moderations-Rechte)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator' AND p.name IN (
  -- Alle User-Permissions
  'articles.read',
  'articles.create',
  'articles.update.own',
  'articles.update.any',
  'articles.delete.own',
  'articles.delete.any',
  'articles.publish',
  'comments.read',
  'comments.create',
  'comments.update.own',
  'comments.update.any',
  'comments.delete.own',
  'comments.delete.any',
  'comments.moderate',
  'users.read',
  'users.update.own',
  'users.ban',
  'users.unban',
  'files.upload',
  'files.delete.own',
  'files.delete.any'
);

-- Admin-Rolle (Moderator + Admin-Rechte, außer Rollen-Verwaltung)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'admin' AND p.name IN (
  -- Alle Moderator-Permissions + Admin-spezifische
  'articles.read',
  'articles.create',
  'articles.update.own',
  'articles.update.any',
  'articles.delete.own',
  'articles.delete.any',
  'articles.publish',
  'articles.feature',
  'comments.read',
  'comments.create',
  'comments.update.own',
  'comments.update.any',
  'comments.delete.own',
  'comments.delete.any',
  'comments.moderate',
  'users.read',
  'users.create',
  'users.update.own',
  'users.update.any',
  'users.delete.any',
  'users.ban',
  'users.unban',
  'admin.access',
  'admin.dashboard',
  'settings.manage',
  'roles.read',
  'permissions.read',
  'files.upload',
  'files.delete.own',
  'files.delete.any'
);

-- Super Admin-Rolle (ALLE Permissions)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'super_admin';

-- ======================================================================
-- Standard-User mit "user"-Rolle (optional - für Testing)
-- HINWEIS: Wenn mbc_users bereits existiert, kann hier ein Test-User
-- mit der Rolle "user" versehen werden
-- ======================================================================

-- Beispiel: Alle existierenden User bekommen die "user"-Rolle
-- (nur ausführen, wenn gewünscht)
-- INSERT IGNORE INTO `mbc_user_roles` (`user_id`, `role_id`)
-- SELECT u.id, r.id FROM `mbc_users` u
-- CROSS JOIN `mbc_roles` r
-- WHERE r.name = 'user'
-- AND NOT EXISTS (
--   SELECT 1 FROM `mbc_user_roles` ur
--   WHERE ur.user_id = u.id
-- );
