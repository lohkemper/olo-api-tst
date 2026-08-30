-- ============================================================================
-- MBC Trade Module - V2: Papertrading (Depot + Orders)
-- ============================================================================
-- Version: 2.0.0
-- Erstellt: 2026-08-30
-- Beschreibung: Papertrading mit 10.000 EUR virtuellem Startkapital.
--               Ein Depot pro User (UNIQUE user_id), Positionen in GANZEN
--               Stücken, Orders mit 1 EUR Flat-Gebühr, Ausführung zum
--               letzten simulierten Tagesschlusskurs. Depot-Reset jederzeit
--               (Positionen+Orders weg, Cash zurück auf 10.000).
--               Depotwert-VERLAUF wird NICHT gespeichert — er ist aus
--               Order-Historie x deterministischer Kursserie jederzeit
--               exakt rekonstruierbar (get-trade-portfolio-history.php).
--               Trading ist hart hinter unlocks_feature='trading'
--               (Lektion 2) gegatet — Prüfung serverseitig im Order-Handler.
-- Idempotent: kann mehrfach ausgeführt werden.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Tabelle: mbc_trade_portfolios
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_portfolios (
  portfolios_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  user_id INT UNSIGNED NOT NULL,

  cash          DECIMAL(14,2) NOT NULL DEFAULT 10000.00 COMMENT 'Verfügbares Spielgeld',
  starting_cash DECIMAL(14,2) NOT NULL DEFAULT 10000.00 COMMENT 'Startkapital (G/V-Basis)',
  reset_count   INT UNSIGNED  NOT NULL DEFAULT 0,
  last_reset_at DATETIME NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_trade_portfolios_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Ein Depot pro User (get-or-create im Handler)
  UNIQUE KEY uniq_trade_portfolios_user (user_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Papertrading-Depots, eins pro User (Trade-Modul V2)';


-- ============================================================================
-- Tabelle: mbc_trade_positions
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_positions (
  positions_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  portfolio_id INT UNSIGNED NOT NULL,
  security_id  INT UNSIGNED NOT NULL,

  quantity      INT UNSIGNED  NOT NULL COMMENT 'GANZE Stücke (Produktentscheidung)',
  avg_buy_price DECIMAL(12,4) NOT NULL COMMENT 'Durchschnittlicher Einstandskurs (ohne Gebühr)',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_trade_positions_portfolio
    FOREIGN KEY (portfolio_id)
    REFERENCES mbc_trade_portfolios(portfolios_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_trade_positions_security
    FOREIGN KEY (security_id)
    REFERENCES mbc_trade_securities(securities_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  UNIQUE KEY uniq_trade_positions_portfolio_security (portfolio_id, security_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Depot-Positionen (Trade-Modul V2)';


-- ============================================================================
-- Tabelle: mbc_trade_orders
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_trade_orders (
  orders_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  portfolio_id INT UNSIGNED NOT NULL,
  security_id  INT UNSIGNED NOT NULL,

  side     ENUM('buy','sell') NOT NULL,
  quantity INT UNSIGNED       NOT NULL,
  price    DECIMAL(12,4)      NOT NULL COMMENT 'Ausführungskurs = letzter Sim-Tagesschlusskurs',
  fee      DECIMAL(6,2)       NOT NULL DEFAULT 1.00 COMMENT '1 EUR Flat-Ordergebühr',
  total    DECIMAL(14,2)      NOT NULL COMMENT 'Cash-Bewegung: buy = -(qty*price+fee), sell = +(qty*price-fee)',

  executed_at DATETIME NOT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_trade_orders_portfolio
    FOREIGN KEY (portfolio_id)
    REFERENCES mbc_trade_portfolios(portfolios_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_trade_orders_security
    FOREIGN KEY (security_id)
    REFERENCES mbc_trade_securities(securities_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_trade_orders_portfolio_time (portfolio_id, executed_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ausgeführte Papertrading-Orders (Trade-Modul V2)';


-- ============================================================================
-- Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
('trade.portfolio.read.own',   'trade_portfolio', 'read',   'own', 'Eigenes Depot anzeigen'),
('trade.portfolio.read.any',   'trade_portfolio', 'read',   'any', 'Beliebige Depots anzeigen (Admin)'),
('trade.portfolio.update.own', 'trade_portfolio', 'update', 'own', 'Eigenes Depot zurücksetzen'),
('trade.orders.create',        'trade_orders',    'create', NULL,  'Orders aufgeben (Papertrading)'),
('trade.orders.read.own',      'trade_orders',    'read',   'own', 'Eigene Order-Historie anzeigen');

INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('user', 'moderator')
AND p.name IN (
  'trade.portfolio.read.own',
  'trade.portfolio.update.own',
  'trade.orders.create',
  'trade.orders.read.own'
);

INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('trade_portfolio', 'trade_orders');


-- ============================================================================
-- Navigation: Mega-Menü-Kind 'Depot' (/trade/portfolio)
-- ============================================================================

SET @trade_parent := (
  SELECT navigations_id FROM mbc_navigations
  WHERE route = '/trade' AND parent_id IS NULL
  ORDER BY navigations_id LIMIT 1
);

INSERT INTO `mbc_navigations`
  (`parent_id`, `title`, `route`, `description`, `icon`, `sort_order`, `is_active`)
SELECT * FROM (
  SELECT @trade_parent AS parent_id, 'Depot' AS title, '/trade/portfolio' AS route,
         'Papertrading mit 10.000 € Spielgeld' AS description,
         'dashboard' AS icon, 3 AS sort_order, 1 AS is_active
) v
WHERE @trade_parent IS NOT NULL
AND NOT EXISTS (
  SELECT 1 FROM (SELECT parent_id, route FROM mbc_navigations) m
  WHERE m.parent_id = @trade_parent AND m.route = '/trade/portfolio'
);

INSERT IGNORE INTO `mbc_navigation_roles` (`navigations_id`, `role_id`)
SELECT n.navigations_id, r.roles_id
FROM `mbc_navigations` n, `mbc_roles` r
WHERE n.route = '/trade/portfolio'
AND r.name IN ('user', 'moderator', 'admin', 'super_admin');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('trade', '2.0.0', 'Trade V2 — Papertrading: Portfolios (10.000 EUR + Reset), Positionen (ganze Stücke), Orders (1 EUR Flat-Fee) + Permissions + Nav Depot')
ON DUPLICATE KEY UPDATE
  version = '2.0.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Trade V2 — Papertrading: Portfolios (10.000 EUR + Reset), Positionen (ganze Stücke), Orders (1 EUR Flat-Fee) + Permissions + Nav Depot';
