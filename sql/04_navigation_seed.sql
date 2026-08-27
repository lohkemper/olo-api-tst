-- ============================================================================
-- MBC - Navigation - Seed
-- ============================================================================
-- Nav-Roles/-Permissions-Links, Cleanup/Reaktivierung, Mega-Menue-Beschreibungen.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- >>> aus: 09_insert_navigation_roles.sql ------------------------------------------------------------
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


-- >>> aus: 10_insert_navigation_permissions.sql ------------------------------------------------------------
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


-- >>> aus: 19_navigation_cleanup.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Navigation - Pre-Flight Cleanup
-- ============================================================================
-- Erstellt: 2026-05-03
-- Quelle: docs/archive/refactoring-navigation-layouts.md §1
--
-- Beschreibung: Reine DB-/Routing-Fixes ohne Layout-Diskussion.
--   1. Tipfehler `warehaouse` → `warehouse` in route (id 36, 37)
--   2. parent_id-Fix id 13 (Logs): 2 (Navigation) → 27 (Admin)
--   3. is_active = 0 für tote Routen (id 3, 13, 28) bis Implementierung steht
--   4. sort_order-Harmonisierung Lager-Children (id 37: 4 → 60)
--
-- Idempotent: UPDATEs sind safe re-runnable. Transaction-gekapselt.
-- Voraussetzung: 07_create_navigation_table.sql ausgeführt + Seed-Daten vorhanden.
-- ============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Tipfehler `warehaouse` → `warehouse` in route
-- ---------------------------------------------------------------------------
-- id 36: /warehaouse/items/unassigned → /warehouse/items/unassigned
-- id 37: /warehaouse/statistics       → /warehouse/statistics

UPDATE `mbc_navigations`
SET `route` = REPLACE(`route`, '/warehaouse/', '/warehouse/')
WHERE `navigations_id` IN (36, 37)
  AND `route` LIKE '/warehaouse/%';

-- ---------------------------------------------------------------------------
-- 2. parent_id-Fix id 13 (Logs)
-- ---------------------------------------------------------------------------
-- Logs gehört semantisch unter id 27 (Admin), nicht id 2 (Navigation).

UPDATE `mbc_navigations`
SET `parent_id` = 27
WHERE `navigations_id` = 13
  AND `parent_id` = 2;

-- ---------------------------------------------------------------------------
-- 3. is_active = 0 für tote Routen
-- ---------------------------------------------------------------------------
-- Routen sind in app.routes.ts nicht definiert → führen ins Leere (Wildcard → /home).
-- Reaktivieren, sobald Implementierung steht (siehe Items #6, #8, #13 im Doc).
--
-- id  3: /reports
-- id 13: /admin/logs        (war bereits 0, defensiv setzen)
-- id 28: /content

UPDATE `mbc_navigations`
SET `is_active` = 0
WHERE `navigations_id` IN (3, 13, 28);

-- ---------------------------------------------------------------------------
-- 4. sort_order-Harmonisierung
-- ---------------------------------------------------------------------------
-- Lager-Children nutzen Skala 60-69; id 37 (Statistiken) hat aktuell 4
-- → mischt sich mit Top-Level-Sortierung.
--
-- id 37: 4 → 60 (Statistiken am Anfang der Lager-Children)

UPDATE `mbc_navigations`
SET `sort_order` = 60
WHERE `navigations_id` = 37
  AND `sort_order` = 4;

-- ---------------------------------------------------------------------------
-- 5. Schema-Version dokumentieren
-- ---------------------------------------------------------------------------
-- mbc_schema_versions.module ist Primary Key → bei Re-Run die Version überschreiben.
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.1.0', 'Pre-flight cleanup: typo, parent_id, is_active, sort_order')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- ============================================================================
-- Verifizierung (nach Ausführung manuell prüfen)
-- ============================================================================
--
-- 1. Tipfehler weg:
--    SELECT navigations_id, route FROM mbc_navigations
--    WHERE route LIKE '%warehaouse%';
--    → erwartet: 0 Zeilen
--
-- 2. Logs unter Admin:
--    SELECT navigations_id, parent_id, title FROM mbc_navigations
--    WHERE navigations_id = 13;
--    → erwartet: parent_id = 27
--
-- 3. Tote Routen inaktiv:
--    SELECT navigations_id, route, is_active FROM mbc_navigations
--    WHERE navigations_id IN (3, 13, 28);
--    → erwartet: alle is_active = 0
--
-- 4. Statistiken sortiert:
--    SELECT navigations_id, title, sort_order FROM mbc_navigations
--    WHERE navigations_id = 37;
--    → erwartet: sort_order = 60
--
-- ============================================================================


-- >>> aus: 21_navigation_logs_reactivate.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Navigation - Logs Route reaktivieren
-- ============================================================================
-- Erstellt: 2026-05-03
-- Quelle: docs/archive/refactoring-navigation-layouts.md §3 Item #8
--
-- Beschreibung: id 13 (/admin/logs) wieder aktivieren, nachdem die Route
-- in src/app/app.routes.ts implementiert wurde (LogsViewerComponent).
--
-- Voraussetzung:
--   - 19_navigation_cleanup.sql bereits ausgeführt (parent_id 27, is_active 0)
--   - app.routes.ts enthält Eintrag 'admin/logs' → LogsViewerComponent
-- ============================================================================

START TRANSACTION;

UPDATE `mbc_navigations`
SET `is_active` = 1
WHERE `navigations_id` = 13;

-- mbc_schema_versions.module ist Primary Key → bei Re-Run die Version überschreiben.
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.2.0', 'Reactivate /admin/logs after LogsViewerComponent impl')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- Verifizierung:
--   SELECT navigations_id, route, is_active, parent_id FROM mbc_navigations
--   WHERE navigations_id = 13;
--   → erwartet: route=/admin/logs, is_active=1, parent_id=27


-- >>> aus: 22_navigation_statistics_reactivate.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Navigation - Lager-Statistiken Route reaktivieren
-- ============================================================================
-- Erstellt: 2026-05-03
-- Quelle: docs/archive/refactoring-navigation-layouts.md §3 Item #16
--
-- Beschreibung: id 37 (/warehouse/statistics) wieder aktivieren, nachdem die
-- Route in projects/warehouse/.../warehouse.routes.ts implementiert wurde
-- (StatisticsHomeComponent mit Dashboard-Layout).
--
-- Voraussetzung:
--   - 19_navigation_cleanup.sql bereits ausgeführt
--     (Tipfehler /warehaouse/ → /warehouse/, sort_order 4 → 60, is_active 0)
--   - WAREHOUSE_ROUTES enthält Eintrag 'statistics' → StatisticsHomeComponent
-- ============================================================================

START TRANSACTION;

UPDATE `mbc_navigations`
SET `is_active` = 1
WHERE `navigations_id` = 37;

-- mbc_schema_versions.module ist Primary Key → bei Re-Run die Version überschreiben.
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.3.0', 'Reactivate /warehouse/statistics after StatisticsHomeComponent impl')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- Verifizierung:
--   SELECT navigations_id, route, is_active, sort_order FROM mbc_navigations
--   WHERE navigations_id = 37;
--   → erwartet: route=/warehouse/statistics, is_active=1, sort_order=60


-- >>> aus: 24_navigation_description.sql [seed-Teil: UPDATEs] ------------------------------------------------------------
-- (seed-Teil aus 24_navigation_description.sql)
START TRANSACTION;
-- 2) Lager-Sub-Einträge mit Beschreibungen versehen (Parent id 33).
--    IDs aus dem Live-Bestand; route zur Absicherung gegen ID-Drift.

-- „Stock" — id 67, /warehouse. Titel von „Warehouse" auf „Bestand"
-- normalisiert (konsistent zu den deutschen Geschwistern Packlisten/Vorlagen).
UPDATE `mbc_navigations`
SET `title` = 'Bestand',
    `description` = 'Standorte, Objekte und Bestände'
WHERE `navigations_id` = 67 AND `route` = '/warehouse';

-- „Packing lists" — id 65, /warehouse/packlists
UPDATE `mbc_navigations`
SET `description` = 'Leihservice: packen, ausgeben, zurücknehmen'
WHERE `navigations_id` = 65 AND `route` = '/warehouse/packlists';

-- „Templates" — id 66, /warehouse/templates
UPDATE `mbc_navigations`
SET `description` = 'Wiederverwendbare Artikel-Sets'
WHERE `navigations_id` = 66 AND `route` = '/warehouse/templates';
COMMIT;


-- >>> aus: 25_navigation_descriptions_mega.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Navigation - Mega-Menü-Sub-Texte für Admin / E-Mail / IoT / Gym
-- ============================================================================
-- Erstellt: 2026-07-13
-- Quelle: design_handoff_mbc_admin_4 (data.jsx — Mega-Menü-Beschreibungen)
--
-- Beschreibung: Setzt `description` (Sub-Text unter dem Link-Label) für die
-- Kinder der übrigen Mega-Parents. Texte weitgehend aus dem Handoff-Prototyp;
-- fehlende (Permissions/Rollen/Gesendet) sinngemäß ergänzt.
--
-- Voraussetzung: 24_navigation_description.sql (legt die Spalte an).
-- Idempotent: reine UPDATEs, per navigations_id + route abgesichert.
-- Inaktive Einträge (Berichte id 3, Content-Baum) bewusst ausgelassen.
-- ============================================================================

START TRANSACTION;

-- ---- Admin (Parent id 27) -------------------------------------------------
UPDATE `mbc_navigations` SET `description` = 'Konten, Rollen & Berechtigungen'
  WHERE `navigations_id` = 10 AND `route` = '/admin/users';
UPDATE `mbc_navigations` SET `description` = 'Navigations-Hierarchie editieren'
  WHERE `navigations_id` = 2  AND `route` = 'navigation';
UPDATE `mbc_navigations` SET `description` = 'Tabellen & Foreign Keys inspizieren'
  WHERE `navigations_id` = 14 AND `route` = 'sql-schema';
UPDATE `mbc_navigations` SET `description` = 'Rollen & ihre Berechtigungen verwalten'
  WHERE `navigations_id` = 11 AND `route` = '/admin/roles';
UPDATE `mbc_navigations` SET `description` = 'Berechtigungen definieren & zuweisen'
  WHERE `navigations_id` = 12 AND `route` = '/admin/permissions';
UPDATE `mbc_navigations` SET `description` = 'Audit Trail & Fehlerprotokoll'
  WHERE `navigations_id` = 13 AND `route` = '/admin/logs';

-- ---- E-Mail (Parent id 38) ------------------------------------------------
UPDATE `mbc_navigations` SET `description` = 'Eingehende Nachrichten lesen'
  WHERE `navigations_id` = 39 AND `route` = '/emails/folder';
UPDATE `mbc_navigations` SET `description` = 'Versendete Nachrichten einsehen'
  WHERE `navigations_id` = 40 AND `route` = '/emails/folder/2';

-- ---- IoT (Parent id 41) ---------------------------------------------------
UPDATE `mbc_navigations` SET `description` = 'Registrierte IoT-Endpunkte verwalten'
  WHERE `navigations_id` = 63 AND `route` = '/iot/devices';
UPDATE `mbc_navigations` SET `description` = 'VLANs & PKS-Subnetze'
  WHERE `navigations_id` = 64 AND `route` = '/iot/networks';

-- ---- Gym (Parent id 42) ---------------------------------------------------
UPDATE `mbc_navigations` SET `description` = 'Live-Tracking der laufenden Session'
  WHERE `navigations_id` = 43 AND `route` = '/gym/workouts/active';
UPDATE `mbc_navigations` SET `description` = 'Alle Sessions chronologisch'
  WHERE `navigations_id` = 44 AND `route` = '/gym/workouts';
UPDATE `mbc_navigations` SET `description` = 'Übungs-Katalog & eigene Übungen'
  WHERE `navigations_id` = 45 AND `route` = '/gym/exercises';
UPDATE `mbc_navigations` SET `description` = 'Trainingspläne mit Tagen & Soll-Werten'
  WHERE `navigations_id` = 46 AND `route` = '/gym/plans';
UPDATE `mbc_navigations` SET `description` = 'Persönliche Bestleistungen'
  WHERE `navigations_id` = 47 AND `route` = '/gym/records';
UPDATE `mbc_navigations` SET `description` = 'Gewicht, Maße & Zusammensetzung'
  WHERE `navigations_id` = 50 AND `route` = '/gym/body';
UPDATE `mbc_navigations` SET `description` = 'Volumen, 1RM-Verlauf & Adherence'
  WHERE `navigations_id` = 51 AND `route` = '/gym/analytics';
UPDATE `mbc_navigations` SET `description` = 'Lauf-, Rad- & Schwimm-Sessions'
  WHERE `navigations_id` = 52 AND `route` = '/gym/cardio';
UPDATE `mbc_navigations` SET `description` = 'Tages-Tagebuch & Makros'
  WHERE `navigations_id` = 53 AND `route` = '/gym/nutrition';

-- Schema-Version protokollieren (Primary Key = module → Re-Run überschreibt).
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.4.0', 'Mega-menu sub-texts for Admin/E-Mail/IoT/Gym')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;


-- >>> aus: 26_navigation_icon_iot_devices.sql ------------------------------------------------------------
-- ============================================================================
-- MBC Navigation - Icon für /iot/devices
-- ============================================================================
-- Erstellt: 2026-08-16
--
-- Beschreibung: Setzt `icon` = 'devices' für den IoT-Sub-Eintrag /iot/devices.
--
-- Hintergrund: Das Mega-Menü ist seit dem zweispaltigen Umbau icon-gesteuert
-- (siehe docs/design/navigation-topnav.md): Children **mit** Icon rendern links
-- als Tile-Link (Icon + Label + Description), Children **ohne** Icon rechts als
-- kompakte Kategorie-Liste ohne Description. /iot/devices hat bereits eine
-- description ('Registrierte IoT-Endpunkte verwalten', siehe 25er-Block oben),
-- die ohne Icon gar nicht angezeigt würde.
--
-- Icon-Wert: 'devices' ist ein bestehender Alias im Frontend-ICON_MAP
-- (projects/ui/src/lib/icon/icon-map.ts) → Carbon `devices/20`. Nicht
-- gemappte Namen fallen auf `undefined` zurück und rendern kein Icon —
-- der Eintrag würde dann still in der rechten Spalte landen.
--
-- Match über `route` statt `navigations_id` (Live-Bestand: id 63), weil die
-- Route eindeutig und drift-sicher ist — analog zur Icon-Korrektur in
-- 09_warehouse_seed.sql.
-- ============================================================================

START TRANSACTION;

UPDATE `mbc_navigations` SET `icon` = 'devices' WHERE `route` = '/iot/devices';

-- Schema-Version protokollieren (Primary Key = module → Re-Run überschreibt).
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.6.0', 'Icon for /iot/devices (mega-menu featured column)')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- Verifizierung:
--   SELECT navigations_id, route, icon, description FROM mbc_navigations
--   WHERE route = '/iot/devices';
--   → erwartet: icon='devices', description='Registrierte IoT-Endpunkte verwalten'

-- Verifizierung:
--   SELECT navigations_id, title, route, description
--   FROM mbc_navigations
--   WHERE parent_id IN (27, 38, 41, 42) AND is_active = 1
--   ORDER BY parent_id, sort_order;

