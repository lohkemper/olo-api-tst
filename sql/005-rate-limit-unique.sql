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
