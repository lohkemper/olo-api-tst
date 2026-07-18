-- ============================================================================
-- MBC Navigation - Beschreibungs-Spalte + Lager-Mega-Menü-Sub-Texte
-- ============================================================================
-- Erstellt: 2026-07-13
-- Quelle: design_handoff_mbc_admin_4 (Punkt 2 — Lager-Tabs → Top-Navigation)
--
-- Beschreibung:
--   1. Neue Spalte `description` in `mbc_navigations` (optionaler Sub-Text,
--      wird im Mega-/Dropdown-Menü unter dem Link-Label angezeigt).
--   2. Sub-Texte für die drei Lager-Einträge (Parent id 33) setzen.
--
-- Frontend-Gegenstück:
--   - Navigation-Model/-Class/-EditForm um `description` erweitert.
--   - get-navigations.php gibt `description` jetzt mit aus (SELECT + Mapping).
--   - TopNav rendert `.desc` je Mega-Link.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS + UPDATEs sind gefahrlos wiederholbar.
-- MariaDB 10.11 (unterstützt ADD COLUMN IF NOT EXISTS).
-- ============================================================================

START TRANSACTION;

-- 1) Spalte anlegen (direkt nach `title`, passend zur Model-Reihenfolge).
ALTER TABLE `mbc_navigations`
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) DEFAULT NULL
  COMMENT 'Optionaler Sub-Text (Mega-Menü-Link-Beschreibung)'
  AFTER `title`;

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

-- 3) Schema-Version protokollieren (Primary Key = module → Re-Run überschreibt).
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.3.0', 'Add description column + Lager mega-menu sub-texts')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

-- Verifizierung:
--   SELECT navigations_id, title, route, description
--   FROM mbc_navigations WHERE parent_id = 33 ORDER BY sort_order;
--   → erwartet: Bestand / Packlisten / Vorlagen jeweils mit description.
