-- 35_users_ui_settings.sql
-- UI-Präferenzen pro Benutzer (Theme / Density / Accent).
-- Spec: design-refactor.md → 3.3 Theme- & User-Settings.
--
-- NULL = keine serverseitige Präferenz → der Client nutzt localStorage/Default
-- (theme=auto, density=comfortable, accent=sodium). Beim Login überschreiben
-- gesetzte Werte den lokalen Stand (UserSettingsService.hydrateFromUser).

ALTER TABLE `mbc_users`
  ADD COLUMN `theme`   VARCHAR(10) NULL DEFAULT NULL AFTER `last_login`,
  ADD COLUMN `density` VARCHAR(12) NULL DEFAULT NULL AFTER `theme`,
  ADD COLUMN `accent`  VARCHAR(12) NULL DEFAULT NULL AFTER `density`;
