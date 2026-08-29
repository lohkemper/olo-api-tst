-- ============================================================================
-- MBC - Grow - Struktur
-- ============================================================================
-- Grow-Schema + Preparation-Fields.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- =====================================================================
-- Modul "Grow" — Pflanzen-Aufzucht von Aussaat bis Ernte
--
-- FK-Typ-Konvention (MariaDB errno 1005/150 vermeiden — exakte Typ-Matches).
--   ACHTUNG: externe Tabellen unterscheiden sich in der Live-DB!
--   * user_id        INT UNSIGNED  → mbc_users.users_id            (UNSIGNED, Migr. 11)
--   * seed_item_id   INT UNSIGNED  → mbc_warehouse_items.items_id  (UNSIGNED, migriert)
--   * warehouse_item_id INT UNSIGNED → mbc_warehouse_items.items_id (UNSIGNED, migriert)
--   * device_id      INT (signed)  → mbc_iot_devices.iot_devices_id (SIGNED, NICHT migriert)
--   * eigene PKs + interne FKs: INT UNSIGNED (in sich konsistent)
--
-- Reihenfolge wegen FK-Abhängigkeiten: cycles → plants → (devices,
-- preparations) → schedule/events → measurements → harvests → settings.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Aufzucht-Durchlauf
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_cycles` (
  `grow_cycles_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`               INT UNSIGNED NOT NULL COMMENT 'Besitzer',
  `name`                  VARCHAR(255) NOT NULL,
  `description`           TEXT DEFAULT NULL,
  `start_date`            DATE NOT NULL COMMENT 'Aussaat-/Startdatum',
  `flowering_weeks`       TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Reifezeit 8-12 Wochen',
  `expected_harvest_date` DATE DEFAULT NULL,
  `status`                ENUM('planned','active','harvested','archived') NOT NULL DEFAULT 'planned',
  `phase_config`          JSON DEFAULT NULL COMMENT 'Override der Phasen-Dauern',
  `meta`                  JSON DEFAULT NULL,
  `created_at`            TIMESTAMP NULL DEFAULT current_timestamp(),
  `updated_at`            TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grow_cycles_id`),
  KEY `idx_grow_cycles_user` (`user_id`),
  KEY `idx_grow_cycles_status` (`status`),
  CONSTRAINT `fk_grow_cycles_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: Aufzucht-Durchlauf (3-6 Pflanzen bis zur Ernte)';

-- ---------------------------------------------------------------------
-- 2) Einzelpflanze
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_plants` (
  `grow_plants_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id`              INT UNSIGNED NOT NULL,
  `user_id`               INT UNSIGNED NOT NULL,
  `label`                 VARCHAR(255) NOT NULL COMMENT 'z.B. "Pflanze 1 / Nordwest"',
  `strain`                VARCHAR(255) DEFAULT NULL COMMENT 'Sorte',
  `seed_item_id`          INT UNSIGNED DEFAULT NULL COMMENT 'FK Warenlager-Item (Samen)',
  `planted_date`          DATE DEFAULT NULL,
  `current_phase`         ENUM('germination','seedling','vegetative','flowering','harvest','cure') NOT NULL DEFAULT 'germination',
  `expected_harvest_date` DATE DEFAULT NULL,
  `actual_harvest_date`   DATE DEFAULT NULL,
  `status`                ENUM('active','harvested','dead','archived') NOT NULL DEFAULT 'active',
  `meta`                  JSON DEFAULT NULL,
  `created_at`            TIMESTAMP NULL DEFAULT current_timestamp(),
  `updated_at`            TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grow_plants_id`),
  KEY `idx_grow_plants_cycle` (`cycle_id`),
  KEY `idx_grow_plants_seed` (`seed_item_id`),
  KEY `idx_grow_plants_user` (`user_id`),
  CONSTRAINT `fk_grow_plants_cycle`
    FOREIGN KEY (`cycle_id`) REFERENCES `mbc_grow_cycles` (`grow_cycles_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grow_plants_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grow_plants_seed`
    FOREIGN KEY (`seed_item_id`) REFERENCES `mbc_warehouse_items` (`items_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: Einzelpflanze innerhalb eines Durchlaufs';

-- ---------------------------------------------------------------------
-- 3) Pflanze <-> IoT-Gerät (M:N) inkl. Sensor-Rollen-Mapping
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_plant_devices` (
  `grow_plant_devices_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plant_id`              INT UNSIGNED NOT NULL,
  `device_id`             INT NOT NULL COMMENT 'FK mbc_iot_devices (signed, iot nicht migriert)',
  `sensor_roles`          JSON DEFAULT NULL COMMENT '{"soil_moisture":"<sensorKey>","temp":"...","humidity":"..."}',
  `assigned_at`           TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`grow_plant_devices_id`),
  UNIQUE KEY `uk_grow_plant_device` (`plant_id`,`device_id`),
  KEY `idx_grow_plant_devices_device` (`device_id`),
  CONSTRAINT `fk_grow_plant_devices_plant`
    FOREIGN KEY (`plant_id`) REFERENCES `mbc_grow_plants` (`grow_plants_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grow_plant_devices_device`
    FOREIGN KEY (`device_id`) REFERENCES `mbc_iot_devices` (`iot_devices_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: IoT-Geräte-Zuordnung je Pflanze';

-- ---------------------------------------------------------------------
-- 4) Düngepräparat (Katalog)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_preparations` (
  `grow_preparations_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`               INT UNSIGNED NOT NULL,
  `name`                  VARCHAR(255) NOT NULL,
  `type`                  ENUM('base','grow','bloom','additive','booster','flush') NOT NULL DEFAULT 'base',
  `default_unit`          VARCHAR(20) NOT NULL DEFAULT 'ml/L',
  `warehouse_item_id`     INT UNSIGNED DEFAULT NULL COMMENT 'optionale Verknüpfung zum Lager-Bestand',
  `meta`                  JSON DEFAULT NULL,
  `created_at`            TIMESTAMP NULL DEFAULT current_timestamp(),
  `updated_at`            TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grow_preparations_id`),
  KEY `idx_grow_preparations_user` (`user_id`),
  CONSTRAINT `fk_grow_preparations_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grow_preparations_item`
    FOREIGN KEY (`warehouse_item_id`) REFERENCES `mbc_warehouse_items` (`items_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: Düngepräparat-Katalog';

-- ---------------------------------------------------------------------
-- 5) Feeding-Schedule (Vorlage je Pflanze, optional je Phase)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_feeding_schedule` (
  `grow_feeding_schedule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plant_id`              INT UNSIGNED NOT NULL,
  `preparation_id`        INT UNSIGNED NOT NULL,
  `phase`                 ENUM('germination','seedling','vegetative','flowering','harvest','cure') DEFAULT NULL,
  `interval_days`         TINYINT UNSIGNED NOT NULL DEFAULT 7 COMMENT 'alle X Tage',
  `dosage`                DECIMAL(10,3) NOT NULL COMMENT 'Menge pro Einheit',
  `dosage_unit`           VARCHAR(20) NOT NULL DEFAULT 'ml/L',
  `start_offset_days`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ab Tag X nach Aussaat',
  `end_offset_days`       SMALLINT UNSIGNED DEFAULT NULL,
  `notes`                 VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`grow_feeding_schedule_id`),
  KEY `idx_grow_feeding_schedule_plant` (`plant_id`),
  KEY `idx_grow_feeding_schedule_prep` (`preparation_id`),
  CONSTRAINT `fk_grow_feeding_schedule_plant`
    FOREIGN KEY (`plant_id`) REFERENCES `mbc_grow_plants` (`grow_plants_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grow_feeding_schedule_prep`
    FOREIGN KEY (`preparation_id`) REFERENCES `mbc_grow_preparations` (`grow_preparations_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: Dünge-Vorlage je Pflanze';

-- ---------------------------------------------------------------------
-- 6) Konkretes, datiertes Feeding-Event (Kalender-synchron)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_feeding_events` (
  `grow_feeding_events_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plant_id`              INT UNSIGNED NOT NULL,
  `preparation_id`        INT UNSIGNED DEFAULT NULL,
  `due_date`              DATE NOT NULL,
  `dosage`                DECIMAL(10,3) DEFAULT NULL,
  `dosage_unit`           VARCHAR(20) DEFAULT 'ml/L',
  `water_amount_l`        DECIMAL(10,2) DEFAULT NULL,
  `status`                ENUM('pending','done','skipped') NOT NULL DEFAULT 'pending',
  `done_at`               TIMESTAMP NULL DEFAULT NULL,
  `calendar_event_id`     VARCHAR(255) DEFAULT NULL COMMENT 'Google-Calendar Event-ID',
  `notes`                 VARCHAR(500) DEFAULT NULL,
  `created_at`            TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`grow_feeding_events_id`),
  KEY `idx_grow_feeding_events_plant_due` (`plant_id`,`due_date`),
  KEY `idx_grow_feeding_events_prep` (`preparation_id`),
  CONSTRAINT `fk_grow_feeding_events_plant`
    FOREIGN KEY (`plant_id`) REFERENCES `mbc_grow_plants` (`grow_plants_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grow_feeding_events_prep`
    FOREIGN KEY (`preparation_id`) REFERENCES `mbc_grow_preparations` (`grow_preparations_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: konkrete datierte Dünge-Termine';

-- ---------------------------------------------------------------------
-- 7) Messung / Sensor-Snapshot
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_measurements` (
  `grow_measurements_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plant_id`              INT UNSIGNED NOT NULL,
  `device_id`             INT DEFAULT NULL COMMENT 'FK mbc_iot_devices (signed, iot nicht migriert)',
  `metric`                ENUM('soil_moisture','humidity','temperature','ph','ec','light','height') NOT NULL,
  `value`                 DECIMAL(10,3) NOT NULL,
  `unit`                  VARCHAR(20) DEFAULT NULL,
  `source`                ENUM('iot','manual') NOT NULL DEFAULT 'manual',
  `measured_at`           TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`grow_measurements_id`),
  KEY `idx_grow_measurements_plant_metric` (`plant_id`,`metric`,`measured_at`),
  KEY `idx_grow_measurements_device` (`device_id`),
  CONSTRAINT `fk_grow_measurements_plant`
    FOREIGN KEY (`plant_id`) REFERENCES `mbc_grow_plants` (`grow_plants_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grow_measurements_device`
    FOREIGN KEY (`device_id`) REFERENCES `mbc_iot_devices` (`iot_devices_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: Messwerte (IoT + manuell)';

-- ---------------------------------------------------------------------
-- 8) Ernte-Datensatz
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_grow_harvests` (
  `grow_harvests_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plant_id`              INT UNSIGNED NOT NULL,
  `harvest_date`          DATE NOT NULL,
  `wet_weight_g`          DECIMAL(10,2) DEFAULT NULL,
  `dry_weight_g`          DECIMAL(10,2) DEFAULT NULL,
  `quality_rating`        TINYINT UNSIGNED DEFAULT NULL COMMENT '1-5',
  `notes`                 TEXT DEFAULT NULL,
  `created_at`            TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`grow_harvests_id`),
  KEY `idx_grow_harvests_plant` (`plant_id`),
  CONSTRAINT `fk_grow_harvests_plant`
    FOREIGN KEY (`plant_id`) REFERENCES `mbc_grow_plants` (`grow_plants_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grow: Ernte-Dokumentation';

-- ---------------------------------------------------------------------
-- 9) Pro-User Einstellungen (generisch; Google-Kalender-Config)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbc_user_settings` (
  `user_settings_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`               INT UNSIGNED NOT NULL,
  `gcal_enabled`          TINYINT(1) NOT NULL DEFAULT 0,
  `gcal_calendar_id`      VARCHAR(255) DEFAULT NULL,
  `gcal_access_token`     TEXT DEFAULT NULL,
  `gcal_refresh_token`    VARCHAR(512) DEFAULT NULL,
  `gcal_token_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `settings`              JSON DEFAULT NULL COMMENT 'sonstige Präferenzen',
  `created_at`            TIMESTAMP NULL DEFAULT current_timestamp(),
  `updated_at`            TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_settings_id`),
  UNIQUE KEY `uk_user_settings_user` (`user_id`),
  CONSTRAINT `fk_user_settings_user`
    FOREIGN KEY (`user_id`) REFERENCES `mbc_users` (`users_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pro-User-Einstellungen inkl. Google-Kalender-OAuth';


-- =====================================================================
-- Grow — Präparate-Katalog um Handoff-Felder erweitern
--
-- Ergänzt mbc_grow_preparations um: status, color, default_dosage, phase,
-- ec_contribution, brand, notes. Ermöglicht die hi-fi „Präparate"-Seite
-- (Master/Detail) aus dem Design-Handoff.
--
-- Voraussetzung: Grow-Grundschema (weiter oben in dieser Datei).
-- Idempotent: ADD COLUMN IF NOT EXISTS (MariaDB 10.0+).
-- =====================================================================

START TRANSACTION;

ALTER TABLE `mbc_grow_preparations`
  ADD COLUMN IF NOT EXISTS `status` ENUM('active','inactive') NOT NULL DEFAULT 'active'
    COMMENT 'Aktiv-Status' AFTER `type`,
  ADD COLUMN IF NOT EXISTS `color` VARCHAR(9) DEFAULT NULL
    COMMENT 'Farb-Datum (Dot/Tile)' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `default_dosage` DECIMAL(10,3) NOT NULL DEFAULT 0
    COMMENT 'Standard-Dosierung' AFTER `default_unit`,
  ADD COLUMN IF NOT EXISTS `phase` VARCHAR(20) NOT NULL DEFAULT 'any'
    COMMENT 'PlantPhase oder any' AFTER `default_dosage`,
  ADD COLUMN IF NOT EXISTS `ec_contribution` DECIMAL(6,2) NOT NULL DEFAULT 0
    COMMENT 'EC-Beitrag mS/cm' AFTER `phase`,
  ADD COLUMN IF NOT EXISTS `brand` VARCHAR(255) DEFAULT NULL
    COMMENT 'Hersteller/Marke' AFTER `ec_contribution`,
  ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL
    COMMENT 'Anwendungs-/Dosierhinweise' AFTER `brand`;

-- Schema-Version dokumentieren.
INSERT INTO `mbc_schema_versions` (`module`, `version`, `description`)
VALUES ('grow', '0.2.0', 'Preparation catalogue fields (status/color/dosage/phase/ec/brand/notes)')
ON DUPLICATE KEY UPDATE
  `version` = VALUES(`version`),
  `applied_at` = CURRENT_TIMESTAMP,
  `description` = VALUES(`description`);

COMMIT;

-- Verifizierung:
--   SHOW COLUMNS FROM mbc_grow_preparations;

