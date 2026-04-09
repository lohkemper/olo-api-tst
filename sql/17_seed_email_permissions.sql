-- ============================================================================
-- MBC Email Module - Permissions Seed
-- ============================================================================
-- Version: 1.0.0
-- Erstellt: 2025-12-07
-- Beschreibung: E-Mail-spezifische Berechtigungen für das System
-- Phase: 1 (Read-Only IMAP Client)
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Insert Email Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Lese-Berechtigungen
('email.read.own', 'email', 'read', 'own', 'Eigene E-Mails lesen'),
('email.read.any', 'email', 'read', 'any', 'Beliebige E-Mails lesen (Admin)'),

-- Sync-Berechtigung
('email.sync', 'email', 'sync', NULL, 'E-Mails vom IMAP-Server synchronisieren'),

-- Status-Änderungen
('email.update.read_status', 'email', 'update', 'read_status', 'Gelesen-Status ändern'),
('email.update.flag_status', 'email', 'update', 'flag_status', 'Markierungs-Status ändern'),

-- Tag-Management
('email.tags.manage', 'email', 'tags', 'manage', 'Tags zu E-Mails hinzufügen/entfernen'),

-- Lösch-Berechtigungen
('email.delete.own', 'email', 'delete', 'own', 'Eigene E-Mails löschen'),
('email.delete.any', 'email', 'delete', 'any', 'Beliebige E-Mails löschen (Admin)'),

-- Ordner-Berechtigungen
('email.folders.read', 'email_folders', 'read', NULL, 'E-Mail-Ordner anzeigen'),

-- Phase 2 Berechtigungen (vorbereitet, aber noch nicht aktiv)
('email.send', 'email', 'send', NULL, 'E-Mails versenden (Phase 2)'),
('email.drafts.manage', 'email', 'drafts', 'manage', 'Entwürfe verwalten (Phase 2)'),
('email.attachments.download', 'email', 'attachments', 'download', 'Anhänge herunterladen (Phase 2)');


-- ============================================================================
-- Assign Permissions to Roles
-- ============================================================================

-- Guest: Keine E-Mail-Berechtigungen
-- (Guests haben keinen Zugriff auf E-Mails)

-- User: Standard-Berechtigungen für eigene E-Mails
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'email.read.own',
  'email.sync',
  'email.update.read_status',
  'email.update.flag_status',
  'email.tags.manage',
  'email.delete.own',
  'email.folders.read'
);

-- Moderator: Zusätzliche Berechtigungen (falls benötigt)
-- Moderatoren haben die gleichen Berechtigungen wie User (Phase 1)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'email.read.own',
  'email.sync',
  'email.update.read_status',
  'email.update.flag_status',
  'email.tags.manage',
  'email.delete.own',
  'email.folders.read'
);

-- Admin: Alle E-Mail-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'admin'
AND p.resource IN ('email', 'email_folders');

-- Super Admin: Alle E-Mail-Berechtigungen (erbt von Admin)
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'super_admin'
AND p.resource IN ('email', 'email_folders');


-- ============================================================================
-- Navigation-Einträge für E-Mail-Modul
-- ============================================================================

-- Haupt-Navigation: E-Mail-Eintrag
INSERT IGNORE INTO `mbc_navigations` (
  `navigations_id`,
  `parent_id`,
  `title`,
  `icon`,
  `route`,
  `sort_order`,
  `is_active`
) VALUES (
  NULL,
  NULL,
  'E-Mail',
  'mail',
  '/emails',
  50, -- Position nach anderen Modulen
  1
);

-- Hole die ID des gerade eingefügten Haupteintrags
SET @email_navigations_id = LAST_INSERT_ID();

-- Sub-Navigation: Posteingang
INSERT IGNORE INTO `mbc_navigations` (
  `navigations_id`,
  `parent_id`,
  `title`,
  `icon`,
  `route`,
  `sort_order`,
  `is_active`
) VALUES (
  NULL,
  @email_navigations_id,
  'Posteingang',
  'inbox',
  '/emails/folder/1',
  1,
  1
);

-- Sub-Navigation: Gesendet (Phase 2)
INSERT IGNORE INTO `mbc_navigations` (
  `navigations_id`,
  `parent_id`,
  `title`,
  `icon`,
  `route`,
  `sort_order`,
  `is_active`
) VALUES (
  NULL,
  @email_navigations_id,
  'Gesendet',
  'send',
  '/emails/folder/2',
  2,
  0 -- Deaktiviert in Phase 1
);


-- ============================================================================
-- Navigation-Rollen-Zuordnung
-- ============================================================================

-- E-Mail-Navigation für User-Rolle
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route LIKE '/emails%'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Ende des Permission-Seeds
-- ============================================================================
