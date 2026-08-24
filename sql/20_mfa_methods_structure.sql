-- ============================================================================
-- MBC - MFA/2FA - Methoden-Struktur (E-Mail-Codes, WebAuthn-Credentials)
-- ============================================================================
-- Plan: docs/planning/mfa-2fa.md. Idempotent. Ergaenzt 19_mfa_structure.sql.
-- ============================================================================

-- ---------------------------------------------------------------------
-- 1) E-Mail-Einmalcodes (bewusst DB statt Session: attempts/Cooldown
--    duerfen nicht durch Wegwerfen des Session-Cookies resetbar sein).
--    UNIQUE(user_id,purpose): Resend ersetzt den alten Code atomar.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_mfa_email_codes` (
  `user_mfa_email_codes_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `purpose`      VARCHAR(10) NOT NULL COMMENT 'login | enroll',
  `code_hash`    CHAR(64) NOT NULL COMMENT 'hash_hmac(sha256, code, JWT_SECRET)',
  `expires_at`   DATETIME NOT NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_sent_at` DATETIME NOT NULL,
  `created_at`   TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_mfa_email_codes_id`),
  UNIQUE KEY `uq_mfa_email_user_purpose` (`user_id`, `purpose`),
  CONSTRAINT `fk_mfa_email_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) WebAuthn-Credentials (N pro User; Registry-Zeile method='webauthn'
--    gilt als bestaetigt, solange >= 1 Credential existiert).
--    credential_id in ascii_bin: base64url-exakter, schlanker Unique-Index.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_webauthn_credentials` (
  `user_webauthn_credentials_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `credential_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                  COMMENT 'base64url der rohen Credential-ID',
  `public_key`    TEXT NOT NULL COMMENT 'PEM (COSE->PEM via lbuchs/webauthn)',
  `sign_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `transports`    VARCHAR(100) DEFAULT NULL COMMENT 'Komma-Liste: internal,hybrid,usb,nfc,ble',
  `label`         VARCHAR(100) NOT NULL DEFAULT 'Passkey',
  `created_at`    TIMESTAMP NULL DEFAULT current_timestamp(),
  `last_used_at`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`user_webauthn_credentials_id`),
  UNIQUE KEY `uq_webauthn_credential` (`credential_id`),
  KEY `idx_webauthn_user` (`user_id`),
  CONSTRAINT `fk_webauthn_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
