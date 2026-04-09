-- ======================================================================
-- Inserts: mbc_navigation_roles
-- Beschreibung: Beispiel-Zuweisungen von Rollen zu Navigationseinträgen
-- Voraussetzung: mbc_navigation_roles Tabelle muss existieren
-- ======================================================================

-- Beispiel-Inserts für Navigation-Rollen-Zuweisungen
-- Die navigation_id und role_id müssen an Ihre vorhandenen Daten angepasst werden

-- Syntax:
-- INSERT INTO `mbc_navigation_roles` (`navigation_id`, `role_id`, `assigned_by`)
-- VALUES (navigation_id, role_id, user_id_des_zuweisers);

-- Beispiel: Navigation 1 ist nur für Admins (role_id=4) und Super-Admins (role_id=5) sichtbar
-- INSERT INTO `mbc_navigation_roles` (`navigation_id`, `role_id`, `assigned_by`) VALUES (1, 4, 1);
-- INSERT INTO `mbc_navigation_roles` (`navigation_id`, `role_id`, `assigned_by`) VALUES (1, 5, 1);

-- Beispiel: Navigation 2 ist für alle eingeloggten User (role_id=2) sichtbar
-- INSERT INTO `mbc_navigation_roles` (`navigation_id`, `role_id`, `assigned_by`) VALUES (2, 2, 1);

-- Beispiel: Navigation 3 ist für Moderatoren (role_id=3) und höher sichtbar
-- INSERT INTO `mbc_navigation_roles` (`navigation_id`, `role_id`, `assigned_by`) VALUES (3, 3, 1);
-- INSERT INTO `mbc_navigation_roles` (`navigation_id`, `role_id`, `assigned_by`) VALUES (3, 4, 1);
-- INSERT INTO `mbc_navigation_roles` (`navigation_id`, `role_id`, `assigned_by`) VALUES (3, 5, 1);

-- ======================================================================
-- Rollen-Referenz (basierend auf typischer RBAC-Struktur):
-- role_id=1: guest
-- role_id=2: user
-- role_id=3: moderator
-- role_id=4: admin
-- role_id=5: super_admin
-- ======================================================================
