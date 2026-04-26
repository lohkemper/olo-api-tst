-- SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
--
-- TASK-2.6.2 — Cleanup obsoleter Test-Devices auf Live-DB
--
-- Entfernt drei Devices, die während der Story-2.5-/Task-2.5.2-Entwicklung registriert
-- wurden, aber nie produktiv genutzt wurden. Die Cascade auf
-- mbc_iot_sensoren / mbc_iot_aktoren / mbc_iot_sensor_data räumt alle abhängigen Zeilen mit.
--
-- ABLAUF:
--   1. Block A (SELECT) ausführen, Zahlen prüfen.
--   2. Wenn die Zahlen plausibel sind: Block B ausführen.
--   3. Block C ausführen, um zu verifizieren, dass die Devices weg sind.

-- ============================================================================
-- Block A — Vor-Check (was wird gelöscht?)
-- ============================================================================

SELECT
    d.iot_devices_id,
    d.chip_id,
    d.name,
    d.typ,
    d.firmware_version,
    d.last_heartbeat,
    (SELECT COUNT(*) FROM mbc_iot_sensoren    s WHERE s.mbc_iot_devices = d.iot_devices_id) AS sensor_count,
    (SELECT COUNT(*) FROM mbc_iot_aktoren     a WHERE a.mbc_iot_devices = d.iot_devices_id) AS aktor_count,
    (SELECT COUNT(*) FROM mbc_iot_sensor_data sd WHERE sd.mbc_iot_devices = d.iot_devices_id) AS data_rows
FROM mbc_iot_devices d
WHERE d.chip_id IN ('security-test', 'test-task252', 'testchip-2-2-6');


-- ============================================================================
-- Block B — Löschen (in Transaction, mit Bestätigung)
-- ============================================================================
-- Erst nach erfolgreichem Block A und Sichtung der Zahlen ausführen.
-- Wenn unerwartet viele data_rows betroffen wären, abbrechen und nachforschen.

START TRANSACTION;

DELETE FROM mbc_iot_devices
WHERE chip_id IN ('security-test', 'test-task252', 'testchip-2-2-6');

-- Vor dem COMMIT noch einmal prüfen, dass nur 3 (oder weniger) Zeilen betroffen sind:
-- die obige DELETE-Statement gibt die Anzahl in der MySQL-Antwort zurück.
-- Nur wenn affected_rows ≤ 3, dann:
COMMIT;
-- Bei Zweifel stattdessen:
-- ROLLBACK;


-- ============================================================================
-- Block C — Verifikation (sollte 0 Zeilen liefern)
-- ============================================================================

SELECT iot_devices_id, chip_id, name
FROM mbc_iot_devices
WHERE chip_id IN ('security-test', 'test-task252', 'testchip-2-2-6');

-- Zur Bestätigung: nach dem Cleanup sollten nur die produktiven Devices übrig sein:
SELECT iot_devices_id, chip_id, name, typ, last_heartbeat
FROM mbc_iot_devices
ORDER BY name;
