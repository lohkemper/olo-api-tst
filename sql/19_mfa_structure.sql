-- ============================================================================
-- MBC - MFA/2FA - Kern-Struktur (Registry, TOTP, Backup-Codes, Trusted Devices)
-- ============================================================================
-- Plan: docs/planning/mfa-2fa.md. Idempotent (Prod-Migrationen manuell).
-- FK-Konvention: user_id INT UNSIGNED exakt gegen mbc_users.users_id (errno 1005).
-- MFA aktiv <=> es existiert eine Registry-Zeile mit is_confirmed=1
-- (bewusst KEIN mbc_users.mfa_enabled-Flag - kein Dual-Write-Desync).
-- ============================================================================

-- ---------------------------------------------------------------------
-- 1) Methoden-Registry (steckbar: totp | email | webauthn)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_mfa_methods` (
  `user_mfa_methods_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `method`       VARCHAR(20) NOT NULL COMMENT 'totp | email | webauthn',
  `label`        VARCHAR(100) DEFAULT NULL COMMENT 'Anzeigename, z.B. "Authenticator-App"',
  `is_confirmed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enrollment abgeschlossen?',
  `confirmed_at` DATETIME DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_mfa_methods_id`),
  UNIQUE KEY `uq_user_method` (`user_id`, `method`),
  CONSTRAINT `fk_mfa_methods_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) TOTP-Details (1:1 zur Registry-Zeile method='totp')
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_totp` (
  `user_totp_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED NOT NULL,
  `secret_encrypted` VARBINARY(512) NOT NULL COMMENT 'base64(nonce || sodium_secretbox(20-Byte-Secret))',
  `last_time_step`   BIGINT UNSIGNED DEFAULT NULL COMMENT 'Replay-Schutz: letzter akzeptierter 30s-Step',
  `created_at`       TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_totp_id`),
  UNIQUE KEY `uq_totp_user` (`user_id`),
  CONSTRAINT `fk_totp_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Backup-Codes (10 Zeilen pro Generation, Einmal-Verwendung)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_mfa_backup_codes` (
  `backup_codes_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `code_hash`  VARCHAR(255) NOT NULL COMMENT 'password_hash BCRYPT',
  `used_at`    DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`backup_codes_id`),
  KEY `idx_backup_user` (`user_id`),
  CONSTRAINT `fk_backup_codes_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) Trusted Devices ("Geraet merken" 30 Tage) - DB haelt nur den Hash
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_trusted_devices` (
  `trusted_devices_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64) NOT NULL COMMENT 'sha256(hex) des Cookie-Tokens',
  `label`        VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'aus User-Agent, z.B. "Chrome - Windows"',
  `created_at`   TIMESTAMP NULL DEFAULT current_timestamp(),
  `last_used_at` DATETIME DEFAULT NULL,
  `expires_at`   DATETIME NOT NULL,
  PRIMARY KEY (`trusted_devices_id`),
  UNIQUE KEY `uq_trusted_token` (`token_hash`),
  KEY `idx_trusted_user` (`user_id`),
  KEY `idx_trusted_expires` (`expires_at`),
  CONSTRAINT `fk_trusted_devices_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
