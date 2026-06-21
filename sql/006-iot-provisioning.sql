-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

-- ============================================================================
-- IoT-Provisioning — Fleet-Token + Admin-Claim
-- ============================================================================
-- Behebt die Lücken aus docs/design/iot-device-auth.md:
--   L1  Geräte-Takeover/DoS via anonymer Re-Registration
--   L2  WLAN-Credential-Leak in der Register-Response
--   L3  unautorisierte Geräte-Registrierung
--
-- Modell: Ein geteiltes Fleet-Provisioning-Token (pro Charge) autorisiert die
-- Erst-Registrierung. Neue Geräte landen als `pending` und müssen von einem
-- Admin freigegeben werden, bevor sie Daten senden oder WLAN-Credentials
-- erhalten.
--
-- Migration ist idempotent (mehrfaches Ausführen ändert nichts).
-- ============================================================================

-- Schritt 1: Fleet-Provisioning-Tokens. Es wird ausschließlich der SHA-256-Hash
-- gespeichert; der Klartext existiert nach der Ausgabe nirgends mehr.
CREATE TABLE IF NOT EXISTS `mbc_iot_fleet_tokens` (
    `iot_fleet_tokens_id` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(100) NOT NULL,
    `token_hash` CHAR(64) NOT NULL UNIQUE,
    `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_fleet_token_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schritt 2: Approval-Lifecycle an den Geräten. Neue Geräte sind per Default
-- `pending`; Freigabe/Sperre erfolgt über das Admin-UI.
ALTER TABLE `mbc_iot_devices`
    ADD COLUMN IF NOT EXISTS `provisioning_status`
        ENUM('pending', 'approved', 'revoked') NOT NULL DEFAULT 'pending'
        AFTER `online_status`,
    ADD COLUMN IF NOT EXISTS `approved_at` TIMESTAMP NULL DEFAULT NULL
        AFTER `provisioning_status`;

-- Schritt 3: Backfill. Bereits registrierte Geräte sind im Betrieb und bleiben
-- vertrauenswürdig — sie werden NICHT auf `pending` gesperrt, sondern direkt
-- als `approved` markiert (verhindert, dass Live-Geräte durch die Migration
-- ihren Datenpfad verlieren).
UPDATE `mbc_iot_devices`
    SET `provisioning_status` = 'approved',
        `approved_at` = COALESCE(`registered_at`, NOW())
    WHERE `approved_at` IS NULL
      AND `provisioning_status` = 'pending';

-- Schritt 4: Index für die Status-Filter im Admin-UI (idempotent über
-- INFORMATION_SCHEMA, da MySQL/MariaDB kein CREATE INDEX IF NOT EXISTS kennt).
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mbc_iot_devices'
      AND index_name = 'idx_provisioning_status'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_provisioning_status ON mbc_iot_devices(provisioning_status)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
