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
