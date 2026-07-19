-- ============================================================================
-- MBC - Navigation - Struktur
-- ============================================================================
-- Navigations-Tabelle + Navigation-Roles + description-Spalte.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- >>> aus: 07_create_navigation_table.sql ------------------------------------------------------------
-- phpMyAdmin SQL Dump
-- version 4.9.11
-- https://www.phpmyadmin.net/
--
-- Host: db5019018523.hosting-data.io
-- Erstellungszeit: 20. Nov 2025 um 16:12
-- Server-Version: 10.11.14-MariaDB-log
-- PHP-Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Datenbank: `dbs14970405`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `mbc_navigations`
--

CREATE TABLE `mbc_navigations` (
  `navigations_id` int(10) UNSIGNED NOT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Parent Navigation Item (NULL = Root-Level)',
  `title` varchar(100) NOT NULL COMMENT 'Anzeige-Titel',
  `route` varchar(255) DEFAULT NULL COMMENT 'Angular Route (z.B. /dashboard)',
  `icon` varchar(50) DEFAULT NULL COMMENT 'Icon-Klasse (z.B. material-icons)',
  `permission_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Erforderliche Permission für Sichtbarkeit',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sortierung innerhalb der Ebene',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sichtbar/Aktiv',
  `is_external` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Externer Link',
  `target` varchar(20) DEFAULT NULL COMMENT 'Link Target (_blank, _self, etc.)',
  `css_class` varchar(100) DEFAULT NULL COMMENT 'Zusätzliche CSS-Klassen',
  `badge_text` varchar(20) DEFAULT NULL COMMENT 'Badge-Text (z.B. "NEU", "BETA")',
  `badge_class` varchar(50) DEFAULT NULL COMMENT 'Badge CSS-Klasse',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Hierarchische Navigation mit Permission-basierter Sichtbarkeit';

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `mbc_navigations`
--
ALTER TABLE `mbc_navigations`
  ADD PRIMARY KEY (`navigations_id`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_permission_id` (`permission_id`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_sort_order` (`sort_order`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `mbc_navigations`
--
ALTER TABLE `mbc_navigations`
  MODIFY `navigations_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `mbc_navigations`
--
ALTER TABLE `mbc_navigations`
  ADD CONSTRAINT `fk_navigations_parent` FOREIGN KEY (`parent_id`) REFERENCES `mbc_navigations` (`navigations_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_navigations_permission` FOREIGN KEY (`permission_id`) REFERENCES `mbc_permissions` (`permissions_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;


-- >>> aus: 08_create_navigation_roles_table.sql ------------------------------------------------------------
-- ======================================================================
-- Tabelle: mbc_navigation_roles
-- Beschreibung: Many-to-Many Beziehung zwischen Navigation und Rollen
--               Definiert welche Rollen Zugriff auf eine Navigation haben
-- Voraussetzung: mbc_navigation und mbc_roles müssen existieren
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_navigation_roles` (
  `navigations_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` INT UNSIGNED NULL COMMENT 'User-ID des Zuweisers (optional)',
  PRIMARY KEY (`navigations_id`, `role_id`),
  CONSTRAINT `fk_navigation_roles_navigation` FOREIGN KEY (`navigations_id`)
    REFERENCES `mbc_navigations`(`navigations_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_navigation_roles_role` FOREIGN KEY (`role_id`)
    REFERENCES `mbc_roles`(`roles_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  INDEX `idx_navigations_id` (`navigations_id`),
  INDEX `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Navigation-Rollen-Zuweisungen - Definiert Sichtbarkeit nach Rolle (Many-to-Many)';


-- >>> aus: 24_navigation_description.sql [struct-Teil: ALTER] ------------------------------------------------------------
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

-- 3) Schema-Version protokollieren (Primary Key = module → Re-Run überschreibt).
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('navigation', '1.3.0', 'Add description column + Lager mega-menu sub-texts')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `description` = VALUES(`description`);

COMMIT;

