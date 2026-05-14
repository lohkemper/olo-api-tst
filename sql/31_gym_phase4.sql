-- ============================================================================
-- MBC Gym Module - Equipment-Link + Coach-Rolle (Phase 4)
-- ============================================================================
-- Version: 0.6.0
-- Erstellt: 2026-05-06
-- Beschreibung: Übungen können auf Warehouse-Items als Equipment-Referenz
--               verlinken. Neue Rolle gym_coach kann Pläne an User zuweisen.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- FK von mbc_gym_exercises.equipment_article_id auf mbc_warehouse_items.items_id
-- (Spalte als signed INT bewusst gewählt für FK-Kompatibilität — siehe 25_gym-schema.sql)
-- Idempotent durch DROP IF EXISTS davor.
-- ============================================================================

ALTER TABLE mbc_gym_exercises
  DROP FOREIGN KEY IF EXISTS fk_gym_exercise_equipment;

ALTER TABLE mbc_gym_exercises
  ADD CONSTRAINT fk_gym_exercise_equipment
    FOREIGN KEY (equipment_article_id)
    REFERENCES mbc_warehouse_items(items_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;


-- ============================================================================
-- Tabelle: mbc_gym_plan_assignments
-- Beschreibung: Coach weist einem User einen Plan zu.
--   - assignee_user_id: der trainierende User
--   - assigned_by_user_id: der Coach (oder selbst-Zuweisung wenn = assignee)
--   - status: pending → accepted → completed | declined
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_gym_plan_assignments (
  plan_assignments_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  assignee_user_id    INT UNSIGNED NOT NULL,
  assigned_by_user_id INT UNSIGNED NOT NULL,
  plan_id             INT UNSIGNED NOT NULL,

  status ENUM('pending','accepted','declined','completed') NOT NULL DEFAULT 'pending',
  message TEXT DEFAULT NULL COMMENT 'Optional: Coach-Nachricht beim Zuweisen',
  start_at DATE DEFAULT NULL COMMENT 'Optional: Wann der User starten soll',

  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME DEFAULT NULL COMMENT 'Wann assignee accepted/declined hat',

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_gym_assign_assignee
    FOREIGN KEY (assignee_user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_assign_coach
    FOREIGN KEY (assigned_by_user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_gym_assign_plan
    FOREIGN KEY (plan_id)
    REFERENCES mbc_gym_plans(plans_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Pro (Coach, Plan, Assignee) gibt es nur eine aktive Assignment.
  -- Wenn ein User dieselbe Plan-Assignment "neu" bekommt, soll der bestehende
  -- Eintrag UPDATEd werden (Status zurück auf pending) statt dupliziert.
  UNIQUE KEY uniq_gym_assign (assigned_by_user_id, plan_id, assignee_user_id),

  INDEX idx_gym_assign_assignee (assignee_user_id),
  INDEX idx_gym_assign_coach (assigned_by_user_id),
  INDEX idx_gym_assign_plan (plan_id),
  INDEX idx_gym_assign_status (status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plan-Zuweisungen Coach → User (Gym-Modul, Phase 4)';


-- ============================================================================
-- Rolle: gym_coach
-- ============================================================================

INSERT IGNORE INTO `mbc_roles` (`name`, `display_name`, `description`)
VALUES ('gym_coach', 'Gym Coach', 'Kann Pläne an andere User zuweisen und deren Fortschritt verfolgen');


-- ============================================================================
-- Phase-4 Permissions
-- ============================================================================

INSERT IGNORE INTO `mbc_permissions` (`name`, `resource`, `action`, `scope`, `description`) VALUES
-- Equipment-bezogene Read-Permissions (Warehouse-Items im Gym-Kontext lesen)
('gym.warehouse_items.read.own',     'gym_warehouse_items',   'read',   'own',  'Eigene Warehouse-Items als Equipment-Optionen lesen'),

-- Plan-Assignment-Permissions
('gym.plan_assignments.read.own',    'gym_plan_assignments',  'read',   'own',  'Eigene zugewiesene Pläne sehen'),
('gym.plan_assignments.read.coach',  'gym_plan_assignments',  'read',   'coach','Selbst zugewiesene Pläne als Coach sehen'),
('gym.plan_assignments.create',      'gym_plan_assignments',  'create', NULL,   'Pläne anderen Usern zuweisen (Coach-Aktion)'),
('gym.plan_assignments.respond',     'gym_plan_assignments',  'respond','own',  'Zuweisung akzeptieren/ablehnen'),
('gym.plan_assignments.delete.coach','gym_plan_assignments',  'delete', 'coach','Eigene Coach-Zuweisungen zurückziehen');


-- User: Equipment lesen + eigene Assignments lesen + responden
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'user'
AND p.name IN (
  'gym.warehouse_items.read.own',
  'gym.plan_assignments.read.own',
  'gym.plan_assignments.respond'
);

-- Moderator: identisch zu User
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'moderator'
AND p.name IN (
  'gym.warehouse_items.read.own',
  'gym.plan_assignments.read.own',
  'gym.plan_assignments.respond'
);

-- gym_coach: Coach-Aktionen + alle User-Permissions zusätzlich
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name = 'gym_coach'
AND p.name IN (
  'gym.warehouse_items.read.own',
  'gym.plan_assignments.read.own',
  'gym.plan_assignments.read.coach',
  'gym.plan_assignments.create',
  'gym.plan_assignments.respond',
  'gym.plan_assignments.delete.coach',
  -- Coach hat auch volle Plan-Lese-/Schreibrechte (vererbt sonst von 'user')
  'gym.plans.read.own',
  'gym.plans.create',
  'gym.plans.update.own',
  'gym.plans.delete.own',
  'gym.plan_days.manage.own',
  'gym.plan_exercises.manage.own'
);

-- Admin / Super Admin: alle Phase-4-Berechtigungen
INSERT IGNORE INTO `mbc_role_permissions` (`role_id`, `permission_id`)
SELECT r.roles_id, p.permissions_id
FROM `mbc_roles` r, `mbc_permissions` p
WHERE r.name IN ('admin', 'super_admin')
AND p.resource IN ('gym_warehouse_items', 'gym_plan_assignments');


COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('gym', '0.6.0', 'Phase 4 — Equipment-Link + Coach-Rolle')
ON DUPLICATE KEY UPDATE
  version = '0.6.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Phase 4 — Equipment-Link + Coach-Rolle';
