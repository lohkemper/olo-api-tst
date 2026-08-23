-- ============================================================================
-- MBC - Social-Login (Google + Facebook) - Struktur
-- ============================================================================
-- Identitäten externer Provider + password_hash NULL-fähig (Social-only-Konten).
-- Idempotent: gefahrlos wiederholbar (Prod-Migrationen werden manuell angewandt).
--
-- FK-Typ-Konvention: user_id INT UNSIGNED → mbc_users.users_id (UNSIGNED),
-- exakter Typ-Match, sonst MariaDB errno 1005/150.
-- ============================================================================

-- ---------------------------------------------------------------------
-- 1) Externe Identitäten (ein User kann mehrere Provider verknüpfen)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_identities` (
  `user_identities_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            INT UNSIGNED NOT NULL,
  `provider`           VARCHAR(20) NOT NULL COMMENT 'google | facebook',
  `provider_user_id`   VARCHAR(191) NOT NULL COMMENT 'sub (Google) bzw. id (Facebook)',
  `email`              VARCHAR(255) DEFAULT NULL COMMENT 'E-Mail laut Provider zum Zeitpunkt der Verknüpfung',
  `display_name`       VARCHAR(255) DEFAULT NULL,
  `avatar_url`         VARCHAR(500) DEFAULT NULL,
  `created_at`         TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_identities_id`),
  UNIQUE KEY `uq_identity` (`provider`, `provider_user_id`),
  KEY `idx_identity_user` (`user_id`),
  CONSTRAINT `fk_user_identities_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) password_hash NULL-fähig — Social-only-Konten haben kein Passwort.
--    MODIFY ist rerun-safe (Spalte existiert immer, Ziel-Definition stabil).
-- ---------------------------------------------------------------------
ALTER TABLE `mbc_users` MODIFY `password_hash` VARCHAR(255) NULL DEFAULT NULL;
