-- ======================================================================
-- Tabelle: mbc_logs
-- Beschreibung: Persistiert Frontend-Logs des WebApi-Publishers aus
--               @olo/core/logs (POST /log). Dient gleichzeitig als
--               Audit-Trail-Senke (ISO 25010 / security.md).
--
-- Hinweis: user_id ist INT UNSIGNED, um exakt auf mbc_users.users_id
--          (UNSIGNED) zu passen — sonst MariaDB-FK-Fehler 1005.
-- ======================================================================

CREATE TABLE IF NOT EXISTS `mbc_logs` (
  `logs_id`    INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `level`      VARCHAR(10)  NOT NULL DEFAULT 'INFO' COMMENT 'DEBUG|INFO|WARN|ERROR|FATAL',
  `message`    TEXT         NOT NULL COMMENT 'Vorformatierter Log-String (entry.buildLogString())',
  `user_id`    INT UNSIGNED NULL COMMENT 'Auth-User falls eingeloggt, sonst NULL',
  `source`     VARCHAR(20)  NOT NULL DEFAULT 'web' COMMENT 'Herkunft des Logs (web, ...)',
  `ip_address` VARCHAR(45)  NULL COMMENT 'Client-IP (IPv4/IPv6)',
  `user_agent` VARCHAR(255) NULL COMMENT 'User-Agent des Clients',
  `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_level` (`level`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_user_id` (`user_id`),
  CONSTRAINT `fk_logs_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Persistierte Frontend-Logs (WebApi-Publisher) + Audit-Trail';
