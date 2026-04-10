-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

-- ============================================================================
-- Seed: Erste aktive IoT-Netzwerk-Konfiguration
--
-- Wird benötigt, damit POST /iot/register überhaupt erfolgreich sein kann.
-- Der Endpoint (rest/request/post-iot-register.php) sucht einen Eintrag mit
-- is_active = 1 und liefert dessen IoT-WLAN-Daten an den ESP zurück.
--
-- Architektur-Entscheidung (2026-04-10):
--   Variante A — Raspberry Pi spannt ein eigenes Subnetz 192.168.50.0/24 auf
--   und ist Gateway auf .1. ESPs leben in diesem isolierten IoT-Netz. Das
--   entspricht dem Dual-WiFi-Konzept aus docs/KONZEPT-REGISTRIERUNG.md.
--
-- Nach Insert einmal verifizieren:
--   SELECT * FROM mbc_iot_networks WHERE is_active = 1;
-- ============================================================================

INSERT INTO `mbc_iot_networks`
    (`name`, `iot_ssid`, `iot_password`, `pi_local_ip`, `mqtt_port`, `is_active`)
VALUES
    ('PKS Hauptnetz', 'PKS-IoT', '9n7KDG7LbA5ENkRzr5cRO4Lnm7OeOpM0', '192.168.50.1', 1883, 1);
