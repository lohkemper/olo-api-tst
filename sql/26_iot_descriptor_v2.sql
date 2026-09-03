-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

-- ============================================================================
-- EPIC-10 / STORY-10.3 — Geraete-Descriptor Schema v2: Value-Ebene
-- ============================================================================
-- v2-Anmeldungen deklarieren typisierte Values pro Sensor/Aktor und ihre
-- Identitaet als mqtt_base (siehe contracts/descriptor-v2.json im
-- PKS-Repository). Die bestehenden Tabellen mbc_iot_sensoren/mbc_iot_aktoren
-- bleiben unveraendert die Komponenten-Ebene — bestehende v1-Zeilen bleiben
-- lesbar. Die Values kommen als neue Kind-Tabelle darunter.
--
-- Migration ist idempotent: mehrfaches Ausfuehren aendert nichts.
-- ============================================================================

-- Schritt 1: Geraete tragen ihre Schemaversion und die deklarierte Identitaet.
-- schema_version 1 = Alt-Registrierung (mqtt_base bleibt NULL),
-- schema_version 2 = v2-Descriptor (mqtt_base = 'pks/{projekt}/{geraet}').
ALTER TABLE `mbc_iot_devices`
    ADD COLUMN IF NOT EXISTS `schema_version` TINYINT NOT NULL DEFAULT 1
        AFTER `firmware_version`,
    ADD COLUMN IF NOT EXISTS `mqtt_base` VARCHAR(150) NULL DEFAULT NULL
        AFTER `schema_version`;

-- Schritt 2: Die Value-Ebene. Eine Zeile pro deklarierter Value einer
-- Komponente; mqtt_topic ist das ABGELEITETE Topic
-- {mqtt_base}/{komponente_key}/{value_key} — gespeichert, damit Abfragen
-- nicht ableiten muessen, aber nie eine zweite Wahrheit: die Ableitung ist
-- per Kontrakt fixiert.
CREATE TABLE IF NOT EXISTS `mbc_iot_values` (
    `iot_values_id` INT AUTO_INCREMENT PRIMARY KEY,
    `mbc_iot_devices` INT NOT NULL,
    `komponente` ENUM('sensor', 'aktor') NOT NULL,
    `komponente_key` VARCHAR(50) NOT NULL,
    `value_key` VARCHAR(50) NOT NULL,
    `typ` ENUM('number', 'string', 'boolean') NOT NULL,
    `einheit` VARCHAR(20) DEFAULT NULL,
    `intervall_sekunden` INT DEFAULT NULL,
    `ist_readonly` TINYINT(1) NOT NULL DEFAULT 0,
    `ist_retained` TINYINT(1) NOT NULL DEFAULT 0,
    `enum_werte` JSON DEFAULT NULL,
    `min_wert` DECIMAL(12,4) DEFAULT NULL,
    `max_wert` DECIMAL(12,4) DEFAULT NULL,
    `mqtt_topic` VARCHAR(200) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`mbc_iot_devices`) REFERENCES `mbc_iot_devices`(`iot_devices_id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_value` (`mbc_iot_devices`, `komponente_key`, `value_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
