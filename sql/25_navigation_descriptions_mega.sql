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

-- Verifizierung:
--   SELECT navigations_id, title, route, description
--   FROM mbc_navigations
--   WHERE parent_id IN (27, 38, 41, 42) AND is_active = 1
--   ORDER BY parent_id, sort_order;
