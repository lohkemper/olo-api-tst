-- ============================================================================
-- MBC - Wartung - Cypress-Testaccount zurücksetzen
-- ============================================================================
-- Der E2E-Testaccount aus cypress.config.ts (testaccount@nhd.com / A92jVYds)
-- wird von Prod mit "Invalid email or password" abgelehnt (Stand 2026-08-02) —
-- die Cypress-Suiten können sich dadurch nicht mehr einloggen.
--
-- Befund vom ersten Lauf (#1062 Duplicate 'testaccount' für Key 'username'):
-- der Account existiert auf Prod bereits, aber mit ABWEICHENDER E-Mail.
-- Das erklärt auch den Login-Fehler — der Login matcht über die E-Mail.
--
-- Dieses Skript ist idempotent und kann gefahrlos mehrfach laufen:
--  1. zieht einen bestehenden Account (gefunden über username ODER email)
--     auf den Soll-Zustand (E-Mail, Passwort-Hash, is_active)
--  2. legt den Account nur an, wenn weder username noch email existieren
--  3. weist die Rolle 'user' zu, falls die Zuordnung fehlt
--
-- Hash = bcrypt("A92jVYds", cost 10) — kompatibel zu password_verify() in
-- post-login.php. Manuell auf Prod ausführen (kein Auto-Deploy).
-- ============================================================================

-- 1a. Bestehenden Account über den Username finden und E-Mail korrigieren.
--     Guard: nur wenn kein ANDERER Account die Ziel-E-Mail bereits belegt
--     (sonst würde das Unique-Constraint auf email verletzt). Der Umweg über
--     die Derived Table ist nötig, weil MySQL die Zieltabelle eines UPDATE
--     nicht direkt in dessen Subquery erlaubt (#1093).
UPDATE `mbc_users`
   SET `email` = 'testaccount@nhd.com',
       `password_hash` = '$2b$10$oHTjUUyqQ41XaHBzNZh2Neg0WbbBbzy.WYDwQh1Mb/CXBXZfeleoW',
       `is_active` = 1
 WHERE `username` = 'testaccount'
   AND NOT EXISTS (
       SELECT 1 FROM (
           SELECT 1 FROM `mbc_users`
            WHERE `email` = 'testaccount@nhd.com'
              AND `username` <> 'testaccount'
       ) AS other_row
   );

-- 1b. Account mit korrekter E-Mail (existierend oder soeben umbenannt)
--     auf Passwort + Aktiv-Flag erzwingen.
UPDATE `mbc_users`
   SET `password_hash` = '$2b$10$oHTjUUyqQ41XaHBzNZh2Neg0WbbBbzy.WYDwQh1Mb/CXBXZfeleoW',
       `is_active` = 1
 WHERE `email` = 'testaccount@nhd.com';

-- 2. Account anlegen — nur wenn weder Username noch E-Mail existieren
INSERT INTO `mbc_users` (`email`, `username`, `password_hash`, `first_name`, `last_name`, `is_active`)
SELECT 'testaccount@nhd.com',
       'testaccount',
       '$2b$10$oHTjUUyqQ41XaHBzNZh2Neg0WbbBbzy.WYDwQh1Mb/CXBXZfeleoW',
       'Cypress',
       'Testaccount',
       1
WHERE NOT EXISTS (
    SELECT 1 FROM `mbc_users`
     WHERE `email` = 'testaccount@nhd.com'
        OR `username` = 'testaccount'
);

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

-- 4. Rolle 'super_admin' zuweisen, falls die Zuordnung fehlt.
--    Die A-Z-Cypress-Suiten (az-navigation/az-content) besuchen auch die
--    /admin/*-Routen (roleGuard: admin|super_admin) — ohne Admin-Rolle
--    bouncen diese nach /unauthorized und die Suiten schlagen fehl.
INSERT INTO `mbc_user_roles` (`user_id`, `role_id`)
SELECT u.`users_id`, r.`roles_id`
  FROM `mbc_users` u
  JOIN `mbc_roles` r ON r.`name` = 'super_admin'
 WHERE u.`email` = 'testaccount@nhd.com'
   AND NOT EXISTS (
       SELECT 1 FROM `mbc_user_roles` ur
        WHERE ur.`user_id` = u.`users_id`
          AND ur.`role_id` = r.`roles_id`
   );

-- Kontrolle:
-- SELECT u.users_id, u.email, u.username, u.is_active, r.name AS role
--   FROM mbc_users u
--   LEFT JOIN mbc_user_roles ur ON ur.user_id = u.users_id
--   LEFT JOIN mbc_roles r ON r.roles_id = ur.role_id
--  WHERE u.username = 'testaccount' OR u.email = 'testaccount@nhd.com';
