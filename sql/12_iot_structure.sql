-- ============================================================================
-- MBC - IoT - Struktur
-- ============================================================================
-- IoT-Tabellen, Sensor-Data-Unique, API-Key-Hash, Rate-Limit-Unique, Provisioning.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- >>> aus: 001-iot-tables.sql ------------------------------------------------------------
-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

-- ============================================================================
-- IoT-Tabellen für Geräteregistrierung und Sensordaten
-- Prefix: mbc_ (gleiche Datenbank wie bestehendes Projekt)
-- ============================================================================

-- Netzwerk-Konfigurationen (IoT-WLAN + Internet-WLAN + Pi-Info)
CREATE TABLE IF NOT EXISTS `mbc_iot_networks` (
    `iot_networks_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `iot_ssid` VARCHAR(64) NOT NULL,
    `iot_password` VARCHAR(128) NOT NULL,
    `inet_ssid` VARCHAR(64) DEFAULT NULL,
    `inet_password` VARCHAR(128) DEFAULT NULL,
    `pi_local_ip` VARCHAR(45) NOT NULL,
    `mqtt_port` INT DEFAULT 1883,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrierte Geräte (Pi + ESPs)
CREATE TABLE IF NOT EXISTS `mbc_iot_devices` (
    `iot_devices_id` INT AUTO_INCREMENT PRIMARY KEY,
    `chip_id` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `typ` ENUM('pi', 'esp32', 'esp8266') NOT NULL,
    `firmware_version` VARCHAR(20) DEFAULT NULL,
    `mbc_iot_networks` INT NOT NULL,
    `api_key` VARCHAR(64) NOT NULL,
    `last_heartbeat` TIMESTAMP NULL,
    `online_status` ENUM('online', 'offline', 'unbekannt') DEFAULT 'unbekannt',
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`mbc_iot_networks`) REFERENCES `mbc_iot_networks`(`iot_networks_id`),
    INDEX `idx_chip_id` (`chip_id`),
    INDEX `idx_online_status` (`online_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sensoren pro Gerät (Selbstbeschreibung)
CREATE TABLE IF NOT EXISTS `mbc_iot_sensoren` (
    `iot_sensoren_id` INT AUTO_INCREMENT PRIMARY KEY,
    `mbc_iot_devices` INT NOT NULL,
    `sensor_key` VARCHAR(50) NOT NULL,
    `typ` VARCHAR(50) NOT NULL,
    `einheit` VARCHAR(20) NOT NULL,
    `modell` VARCHAR(50) DEFAULT NULL,
    `intervall_sekunden` INT DEFAULT 30,
    `mqtt_topic` VARCHAR(200) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`mbc_iot_devices`) REFERENCES `mbc_iot_devices`(`iot_devices_id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_sensor` (`mbc_iot_devices`, `sensor_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktoren pro Gerät (Selbstbeschreibung)
CREATE TABLE IF NOT EXISTS `mbc_iot_aktoren` (
    `iot_aktoren_id` INT AUTO_INCREMENT PRIMARY KEY,
    `mbc_iot_devices` INT NOT NULL,
    `aktor_key` VARCHAR(50) NOT NULL,
    `typ` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `zustaende` JSON NOT NULL,
    `mqtt_topic_set` VARCHAR(200) NOT NULL,
    `mqtt_topic_status` VARCHAR(200) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`mbc_iot_devices`) REFERENCES `mbc_iot_devices`(`iot_devices_id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_aktor` (`mbc_iot_devices`, `aktor_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kumulierte Sensordaten (vom Pi periodisch gesendet)
CREATE TABLE IF NOT EXISTS `mbc_iot_sensor_data` (
    `iot_sensor_data_id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `mbc_iot_devices` INT NOT NULL,
    `sensor_key` VARCHAR(50) NOT NULL,
    `wert` DECIMAL(10,2) NOT NULL,
    `min_wert` DECIMAL(10,2) DEFAULT NULL,
    `max_wert` DECIMAL(10,2) DEFAULT NULL,
    `avg_wert` DECIMAL(10,2) DEFAULT NULL,
    `anzahl_messungen` INT DEFAULT 1,
    `zeitstempel` TIMESTAMP NOT NULL,
    FOREIGN KEY (`mbc_iot_devices`) REFERENCES `mbc_iot_devices`(`iot_devices_id`) ON DELETE CASCADE,
    INDEX `idx_device_sensor_zeit` (`mbc_iot_devices`, `sensor_key`, `zeitstempel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- >>> aus: 003-iot-sensor-data-unique.sql ------------------------------------------------------------
-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
--
-- STORY-2.4 / TASK-2.4.4
-- Add UNIQUE constraint on (device, sensor_key, zeitstempel) so retried
-- pi-sync calls do not create duplicates. Combined with
-- INSERT ... ON DUPLICATE KEY UPDATE in post-iot-pi-sync.php.
--
-- Precondition (verified 2026-04-13 via GET /iot/data/1):
-- no existing duplicates on this tuple.

ALTER TABLE `mbc_iot_sensor_data`
    ADD CONSTRAINT `uniq_device_sensor_zeit`
    UNIQUE (`mbc_iot_devices`, `sensor_key`, `zeitstempel`);


-- >>> aus: 004-iot-api-key-hash.sql ------------------------------------------------------------
-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

-- ============================================================================
-- STORY-2.5 / TASK-2.5.2 — API-Key-Hash + UNIQUE-Constraint + Rotation-Spur
-- ============================================================================
-- Ziel: API-Keys werden kuenftig nur noch als SHA-256-Hash validiert.
-- Die Klartext-Spalte `api_key` bleibt vorerst bestehen (Rollback-Sicherheit)
-- und wird in einer spaeteren Migration entfernt, sobald alle Clients auf den
-- neuen Pfad umgestellt sind.
--
-- Migration ist idempotent: mehrfaches Ausfuehren aendert nichts.
-- ============================================================================

-- Schritt 1: Neue Spalten nullable hinzufuegen (idempotent via IF NOT EXISTS)
ALTER TABLE mbc_iot_devices
    ADD COLUMN IF NOT EXISTS api_key_hash VARCHAR(64) NULL AFTER api_key,
    ADD COLUMN IF NOT EXISTS api_key_rotated_at TIMESTAMP NULL AFTER api_key_hash;

-- Schritt 2: Backfill aller bestehenden Zeilen (SHA2 = SHA-256 hex)
UPDATE mbc_iot_devices
    SET api_key_hash = SHA2(api_key, 256)
    WHERE api_key_hash IS NULL
      AND api_key IS NOT NULL
      AND api_key <> '';

-- Schritt 3: api_key_hash auf NOT NULL setzen
ALTER TABLE mbc_iot_devices
    MODIFY COLUMN api_key_hash VARCHAR(64) NOT NULL;

-- Schritt 4: UNIQUE-Index (idempotent: vorher droppen, dann erneut anlegen)
-- MariaDB/MySQL haben kein CREATE UNIQUE INDEX IF NOT EXISTS, deswegen der
-- Kunstgriff ueber INFORMATION_SCHEMA.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mbc_iot_devices'
      AND index_name = 'unique_api_key_hash'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE UNIQUE INDEX unique_api_key_hash ON mbc_iot_devices(api_key_hash)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- >>> aus: 005-rate-limit-unique.sql ------------------------------------------------------------
-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

-- ============================================================================
-- STORY-2.5 / TASK-2.5.5 — UNIQUE-Constraint auf mbc_rate_limit
-- ============================================================================
-- Die Tabelle wurde ohne UNIQUE-Constraint angelegt, dadurch triggert das
-- ON DUPLICATE KEY UPDATE im RateLimiter nie und jedes hit() erzeugt eine
-- neue Zeile. check() liest per LIMIT 1 eine beliebige Zeile und sieht
-- attempts=1 — der Limiter ist dadurch faktisch wirkungslos.
--
-- Behebung: Bestandsdaten droppen (sind ephemer, maximal ein paar Minuten
-- alt) und UNIQUE-Index ergaenzen.
-- Idempotent: mehrfaches Ausfuehren aendert nichts.
-- ============================================================================

TRUNCATE TABLE mbc_rate_limit;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mbc_rate_limit'
      AND index_name = 'unique_ip_endpoint'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE mbc_rate_limit ADD UNIQUE KEY unique_ip_endpoint (ip_address, endpoint)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- >>> aus: 006-iot-provisioning.sql ------------------------------------------------------------
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

