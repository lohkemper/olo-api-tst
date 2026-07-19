-- ============================================================================
-- MBC - Articles - Struktur
-- ============================================================================
-- Artikel-Tabelle(n).
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- >>> aus: 12_create_articles_table.sql ------------------------------------------------------------
-- phpMyAdmin SQL Dump
-- version 4.9.11
-- https://www.phpmyadmin.net/
--
-- Host: db5019018523.hosting-data.io
-- Erstellungszeit: 26. Nov 2025 um 17:30
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
-- Tabellenstruktur für Tabelle `mbc_articles`
--

CREATE TABLE `mbc_articles` (
  `articles_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'Autor des Artikels',
  `title` varchar(255) NOT NULL COMMENT 'Artikel-Titel',
  `description` text DEFAULT NULL COMMENT 'Kurzbeschreibung/Teaser',
  `body` longtext NOT NULL COMMENT 'Artikel-Inhalt (Markdown)',
  `slug` varchar(255) DEFAULT NULL COMMENT 'URL-freundlicher Slug',
  `favorited` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Favorisiert durch aktuellen User',
  `favorites_count` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Anzahl Favorisierungen',
  `is_published` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Veröffentlicht',
  `published_at` timestamp NULL DEFAULT NULL COMMENT 'Veröffentlichungszeitpunkt',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog-Artikel mit Markdown-Support';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `mbc_article_tags`
--

CREATE TABLE `mbc_article_tags` (
  `articles_id` int(10) UNSIGNED NOT NULL,
  `tag_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Many-to-Many Zuordnung Artikel ↔ Tags';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `mbc_tags`
--

CREATE TABLE `mbc_tags` (
  `tags_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL COMMENT 'Tag-Name',
  `slug` varchar(50) NOT NULL COMMENT 'URL-freundlicher Slug',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tags für Artikel-Kategorisierung';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `mbc_article_favorites`
--

CREATE TABLE `mbc_article_favorites` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `articles_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-Favoriten für Artikel';

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `mbc_articles`
--
ALTER TABLE `mbc_articles`
  ADD PRIMARY KEY (`articles_id`),
  ADD UNIQUE KEY `uk_articles_slug` (`slug`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_published` (`is_published`),
  ADD KEY `idx_published_at` (`published_at`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indizes für die Tabelle `mbc_article_tags`
--
ALTER TABLE `mbc_article_tags`
  ADD PRIMARY KEY (`articles_id`,`tag_id`),
  ADD KEY `idx_tag_id` (`tag_id`);

--
-- Indizes für die Tabelle `mbc_tags`
--
ALTER TABLE `mbc_tags`
  ADD PRIMARY KEY (`tags_id`),
  ADD UNIQUE KEY `uk_tags_name` (`name`),
  ADD UNIQUE KEY `uk_tags_slug` (`slug`);

--
-- Indizes für die Tabelle `mbc_article_favorites`
--
ALTER TABLE `mbc_article_favorites`
  ADD PRIMARY KEY (`user_id`,`articles_id`),
  ADD KEY `idx_articles_id` (`articles_id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `mbc_articles`
--
ALTER TABLE `mbc_articles`
  MODIFY `articles_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `mbc_tags`
--
ALTER TABLE `mbc_tags`
  MODIFY `tags_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `mbc_articles`
--
ALTER TABLE `mbc_articles`
  ADD CONSTRAINT `fk_articles_user` FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints der Tabelle `mbc_article_tags`
--
ALTER TABLE `mbc_article_tags`
  ADD CONSTRAINT `fk_article_tags_article` FOREIGN KEY (`articles_id`) REFERENCES `mbc_articles` (`articles_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_article_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `mbc_tags` (`tags_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints der Tabelle `mbc_article_favorites`
--
ALTER TABLE `mbc_article_favorites`
  ADD CONSTRAINT `fk_article_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_article_favorites_article` FOREIGN KEY (`articles_id`) REFERENCES `mbc_articles` (`articles_id`) ON DELETE CASCADE ON UPDATE CASCADE;

COMMIT;

