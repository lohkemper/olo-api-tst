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
