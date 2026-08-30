-- ============================================================================
-- MBC Trade Module - Struktur (V1: Lernpfad + Markt)
-- ============================================================================
-- Version: 1.0.0
-- Erstellt: 2026-08-30
-- Beschreibung: Neue Domäne /trade — spielerisch Aktienhandel lernen.
--               V1 = Lektionen + Quiz (Content in der DB) + simulierte
--               Tageskurse (deterministisch aus Seed, lazy im Backend
--               berechnet — deshalb KEINE Preis-Tabelle).
--               Bewusst KEINE Tabelle mbc_trade (würde das generische
--               Tabellen-Routing für /trade aktivieren).
--               V2-Ausblick (nicht angelegt): mbc_trade_portfolios /
--               _positions / _orders / _watchlist / _prices (für source='live').
-- Idempotent: kann mehrfach ausgeführt werden.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_trade_securities
-- Beschreibung: Fiktive Wertpapiere (globale Stammdaten, kein user_id).
--               Simulationsparameter erzeugen deterministische Tageskurse.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_securities (
  securities_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  symbol VARCHAR(8)   NOT NULL COMMENT 'Fiktives Kürzel, z.B. NWE',
  name   VARCHAR(120) NOT NULL,
  description TEXT NULL COMMENT 'Firmenstory mit Lern-Hinweis (DE, Markdown)',
  sector VARCHAR(60) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',

  source ENUM('sim','live') NOT NULL DEFAULT 'sim'
    COMMENT 'sim = deterministische Simulation; live = spätere echte Quelle (mbc_trade_prices)',

  start_price DECIMAL(12,4) NOT NULL COMMENT 'Kurs am sim_start_date',
  drift       DECIMAL(9,6)  NOT NULL DEFAULT 0 COMMENT 'Tages-Drift, z.B. 0.000400 ≈ +10%/Jahr',
  volatility  DECIMAL(9,6)  NOT NULL COMMENT 'Tages-Volatilität, z.B. 0.012000',
  seed        INT UNSIGNED  NOT NULL COMMENT 'PRNG-Seed (mulberry32)',
  sim_start_date DATE NOT NULL DEFAULT '2024-01-01' COMMENT 'Anker des deterministischen Random Walk',

  is_active TINYINT(1) NOT NULL DEFAULT 1,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_trade_securities_symbol (symbol)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fiktive Wertpapiere mit Simulationsparametern (Trade-Modul)';


-- ============================================================================
-- Tabelle: mbc_trade_lessons
-- Beschreibung: Lernpfad-Lektionen; sequenzielle Freischaltung über
--               required_lesson_id, Feature-Freischaltung über unlocks_feature.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_lessons (
  lessons_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  slug  VARCHAR(60)  NOT NULL COMMENT 'z.B. was-ist-eine-aktie',
  title VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL COMMENT 'Teaser für die Lernpfad-Karte',
  sort_order INT NOT NULL DEFAULT 0,

  required_lesson_id INT UNSIGNED NULL COMMENT 'Vorgänger-Lektion (NULL = frei)',
  unlocks_feature VARCHAR(40) NULL COMMENT 'Feature-Key: market | trading | analysis',
  pass_threshold TINYINT UNSIGNED NOT NULL DEFAULT 70 COMMENT 'Quiz-Bestehensgrenze in %',

  is_active TINYINT(1) NOT NULL DEFAULT 1,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_trade_lessons_required
    FOREIGN KEY (required_lesson_id)
    REFERENCES mbc_trade_lessons(lessons_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  UNIQUE KEY uniq_trade_lessons_slug (slug)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lernpfad-Lektionen (Trade-Modul)';


-- ============================================================================
-- Tabelle: mbc_trade_lesson_sections
-- Beschreibung: Lektions-Abschnitte für den Stepper im UI; kind mappt auf
--               die InfoCallout-Variante, content ist Markdown.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_lesson_sections (
  lesson_sections_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  lesson_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,

  title VARCHAR(160) NOT NULL,
  kind ENUM('text','info','tip','warning','example') NOT NULL DEFAULT 'text',
  content MEDIUMTEXT NOT NULL COMMENT 'Markdown (DE)',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_trade_sections_lesson
    FOREIGN KEY (lesson_id)
    REFERENCES mbc_trade_lessons(lessons_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Natural Key für idempotente Seeds (Upsert über lesson_id + sort_order)
  UNIQUE KEY uniq_trade_sections_lesson_order (lesson_id, sort_order)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lektions-Abschnitte (Trade-Modul)';


-- ============================================================================
-- Tabelle: mbc_trade_quiz_questions
-- Beschreibung: Single-Choice-Fragen je Lektion. options enthält NUR die
--               Antworttexte; correct_key + explanation stehen separat, damit
--               der GET-Handler sie strukturell weglassen kann (kein Leak der
--               Lösung — Bewertung ausschließlich serverseitig).
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_quiz_questions (
  quiz_questions_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  lesson_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,

  question VARCHAR(500) NOT NULL,
  options JSON NOT NULL COMMENT '[{"key":"a","text":"…"},…] — nur Texte, keine Lösung',
  correct_key CHAR(1) NOT NULL,
  explanation VARCHAR(800) NOT NULL COMMENT 'Erklärung nach der Antwort (Lern-Charakter)',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_trade_questions_lesson
    FOREIGN KEY (lesson_id)
    REFERENCES mbc_trade_lessons(lessons_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  UNIQUE KEY uniq_trade_questions_lesson_order (lesson_id, sort_order),
  INDEX idx_trade_questions_lesson (lesson_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Quizfragen je Lektion (Trade-Modul)';


-- ============================================================================
-- Tabelle: mbc_trade_lesson_progress
-- Beschreibung: Lernfortschritt pro User+Lektion (Upsert-Ziel).
--               Freigeschaltete Features ergeben sich aus
--               status='completed' JOIN lessons.unlocks_feature.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_lesson_progress (
  lesson_progress_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id   INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,

  status ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  best_score TINYINT UNSIGNED NULL COMMENT 'Bestes Quiz-Ergebnis in %',
  last_score TINYINT UNSIGNED NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_trade_progress_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_trade_progress_lesson
    FOREIGN KEY (lesson_id)
    REFERENCES mbc_trade_lessons(lessons_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  UNIQUE KEY uniq_trade_progress_user_lesson (user_id, lesson_id),
  INDEX idx_trade_progress_user (user_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lernfortschritt pro User und Lektion (Trade-Modul)';


-- ============================================================================
-- Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('trade.securities.read',       'trade_securities', 'read',   NULL,  'Wertpapiere & Kurse anzeigen'),
('trade.lessons.read',          'trade_lessons',    'read',   NULL,  'Lektionen & Quizfragen anzeigen'),
('trade.progress.read.own',     'trade_progress',   'read',   'own', 'Eigenen Lernfortschritt anzeigen'),
('trade.progress.read.any',     'trade_progress',   'read',   'any', 'Beliebigen Lernfortschritt anzeigen (Admin)'),
('trade.progress.create',       'trade_progress',   'create', NULL,  'Lektionen starten / Quiz einreichen'),
('trade.progress.update.own',   'trade_progress',   'update', 'own', 'Eigenen Lernfortschritt aktualisieren');

-- User + Moderator: lernen + eigener Fortschritt
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('user', 'moderator')
AND p.name IN (
  'trade.securities.read',
  'trade.lessons.read',
  'trade.progress.read.own',
  'trade.progress.create',
  'trade.progress.update.own'
);

-- Admin / Super Admin: alle Trade-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('trade_securities', 'trade_lessons', 'trade_progress');


-- ============================================================================
-- Navigation: Top-Level 'Trade' (/trade) + Mega-Menü-Kinder
-- ============================================================================

-- Top-Level (NOT-EXISTS-über-Derived-Table statt INSERT IGNORE, da
-- mbc_navigations keinen UNIQUE-Key auf route hat)
INSERT INTO `mbc_navigations`
  (`parent_id`, `title`, `route`, `description`, `icon`, `sort_order`, `is_active`)
SELECT * FROM (
  SELECT NULL AS parent_id, 'Trade' AS title, '/trade' AS route,
         'Aktienhandel spielerisch lernen' AS description,
         'chart_line' AS icon, 80 AS sort_order, 1 AS is_active
) v
WHERE NOT EXISTS (
  SELECT 1 FROM (SELECT route, parent_id FROM mbc_navigations) m
  WHERE m.route = '/trade' AND m.parent_id IS NULL
);

INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route LIKE '/trade%'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');

SET @trade_parent := (
  SELECT navigations_id FROM mbc_navigations
  WHERE route = '/trade' AND parent_id IS NULL
  ORDER BY navigations_id LIMIT 1
);

-- Mega-Menü-Kind: Lernpfad
INSERT INTO `mbc_navigations`
  (`parent_id`, `title`, `route`, `description`, `icon`, `sort_order`, `is_active`)
SELECT * FROM (
  SELECT @trade_parent AS parent_id, 'Lernpfad' AS title, '/trade' AS route,
         'Lektionen & Quiz — Schritt für Schritt' AS description,
         'article' AS icon, 1 AS sort_order, 1 AS is_active
) v
WHERE @trade_parent IS NOT NULL
AND NOT EXISTS (
  SELECT 1 FROM (SELECT parent_id, route FROM mbc_navigations) m
  WHERE m.parent_id = @trade_parent AND m.route = '/trade'
);

-- Mega-Menü-Kind: Markt
INSERT INTO `mbc_navigations`
  (`parent_id`, `title`, `route`, `description`, `icon`, `sort_order`, `is_active`)
SELECT * FROM (
  SELECT @trade_parent AS parent_id, 'Markt' AS title, '/trade/market' AS route,
         'Simulierte Tageskurse & Charts' AS description,
         'chart_line' AS icon, 2 AS sort_order, 1 AS is_active
) v
WHERE @trade_parent IS NOT NULL
AND NOT EXISTS (
  SELECT 1 FROM (SELECT parent_id, route FROM mbc_navigations) m
  WHERE m.parent_id = @trade_parent AND m.route = '/trade/market'
);

-- Rollen auch für die (ggf. gerade eingefügten) Kinder
INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.parent_id = @trade_parent
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('trade', '1.0.0', 'Trade V1 — Struktur: Securities (Sim-Parameter), Lektionen, Abschnitte, Quiz, Progress + Permissions + Nav')
ON DUPLICATE KEY UPDATE
  version = '1.0.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Trade V1 — Struktur: Securities (Sim-Parameter), Lektionen, Abschnitte, Quiz, Progress + Permissions + Nav';
