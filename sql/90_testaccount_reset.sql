-- ============================================================================
-- MBC - Wartung - Cypress-Testaccount zurücksetzen
-- ============================================================================
-- Der E2E-Testaccount aus cypress.config.ts (testaccount@nhd.com / A92jVYds)
-- wird von Prod mit "Invalid email or password" abgelehnt (Stand 2026-08-02) —
-- die Cypress-Suiten können sich dadurch nicht mehr einloggen.
--
-- Dieses Skript ist idempotent und kann gefahrlos mehrfach laufen:
--  1. legt den Account an, falls er fehlt
--  2. setzt Passwort-Hash + is_active auf den Soll-Zustand
--  3. weist die Rolle 'user' zu, falls die Zuordnung fehlt
--
-- Hash = bcrypt("A92jVYds", cost 10) — kompatibel zu password_verify() in
-- post-login.php. Manuell auf Prod ausführen (kein Auto-Deploy).
-- ============================================================================

-- 1. Account anlegen, falls er fehlt
INSERT INTO `mbc_users` (`email`, `username`, `password_hash`, `first_name`, `last_name`, `is_active`)
SELECT 'testaccount@nhd.com',
       'testaccount',
       '$2b$10$oHTjUUyqQ41XaHBzNZh2Neg0WbbBbzy.WYDwQh1Mb/CXBXZfeleoW',
       'Cypress',
       'Testaccount',
       1
WHERE NOT EXISTS (
    SELECT 1 FROM `mbc_users` WHERE `email` = 'testaccount@nhd.com'
);

-- 2. Passwort + Aktiv-Flag erzwingen (falls Account existiert, aber abweicht)
UPDATE `mbc_users`
   SET `password_hash` = '$2b$10$oHTjUUyqQ41XaHBzNZh2Neg0WbbBbzy.WYDwQh1Mb/CXBXZfeleoW',
       `is_active` = 1
 WHERE `email` = 'testaccount@nhd.com';

-- 3. Rolle 'user' zuweisen, falls die Zuordnung fehlt
INSERT INTO `mbc_user_roles` (`user_id`, `role_id`)
SELECT u.`users_id`, r.`roles_id`
  FROM `mbc_users` u
  JOIN `mbc_roles` r ON r.`name` = 'user'
 WHERE u.`email` = 'testaccount@nhd.com'
   AND NOT EXISTS (
       SELECT 1 FROM `mbc_user_roles` ur
        WHERE ur.`user_id` = u.`users_id`
          AND ur.`role_id` = r.`roles_id`
   );

-- Kontrolle:
-- SELECT u.users_id, u.email, u.is_active, r.name AS role
--   FROM mbc_users u
--   LEFT JOIN mbc_user_roles ur ON ur.user_id = u.users_id
--   LEFT JOIN mbc_roles r ON r.roles_id = ur.role_id
--  WHERE u.email = 'testaccount@nhd.com';
