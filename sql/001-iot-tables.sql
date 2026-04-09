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
