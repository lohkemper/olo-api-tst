# MBC — SQL-Setup (Struktur + Seed pro Modul)

Diese SQL-Dateien bauen das komplette MBC-Datenbankschema auf. Die frühere
Migrations-Historie (47 durchnummerierte Einzeldateien) wurde **pro Modul zu je
einer Struktur- und einer Seed-Datei konsolidiert** (Statement-Reihenfolge und
-Inhalt bleiben erhalten).

## Dateiübersicht (24 Dateien)

Ausführungsreihenfolge = Dateinummer. **Struktur** (`CREATE`/`ALTER`/Trigger)
und **Seed** (`INSERT`/Daten) sind pro Modul getrennt. `01`–`17` sind die
konsolidierten Modul-Dateien; `18`+ sind spätere Einzel-Migrationen,
`90`+ Wartungs-Skripte (nur bei Bedarf).

| Datei                              | Inhalt                                                        |
|------------------------------------|---------------------------------------------------------------|
| `01_core_structure.sql`            | Users, Roles, Permissions, Zuordnungstabellen, ID-Rename, UI-Settings |
| `02_core_seed.sql`                 | Standard-Rollen + Basis-Permissions + Rollen-Zuordnungen      |
| `03_navigation_structure.sql`      | Navigations-Tabelle + Navigation-Roles + `description`-Spalte |
| `04_navigation_seed.sql`           | Nav-Roles/-Permissions-Links, Cleanup/Reaktivierung, Mega-Menü-Texte |
| `05_articles_structure.sql`        | Artikel-Tabelle(n)                                            |
| `06_articles_seed.sql`             | Article-Permissions                                          |
| `07_logs_structure.sql`            | Logs-Tabelle (kein Seed)                                     |
| `08_warehouse_structure.sql`       | Locations/Items/Tags, Trigger/Views, Maße/Grid, Packlisten-Tabellen |
| `09_warehouse_seed.sql`            | Warehouse-Permissions/Nav, Item-Seed, Packlisten-Seed, Nav-Konsolidierung |
| `10_email_structure.sql`           | Folders/Messages/Attachments/Tags + Views                   |
| `11_email_seed.sql`                | Email-Permissions                                           |
| `12_iot_structure.sql`             | IoT-Tabellen, Sensor-Unique, API-Key-Hash, Rate-Limit, Provisioning |
| `13_iot_seed.sql`                  | IoT-Networks-Seed                                           |
| `14_gym_structure.sql`             | Exercise-Catalog, Workouts, Plans/Records, Body, Cardio/Nutrition, Phase-4 |
| `15_gym_seed.sql`                  | Exercise-/Foods-Katalog + Permissions/Nav aller Gym-Phasen  |
| `16_grow_structure.sql`            | Grow-Schema + Preparation-Fields                            |
| `17_grow_seed.sql`                 | Grow-Permissions + Nav-Subpages                             |
| `18_social_auth_structure.sql`     | Social-Login (Google/Facebook): Provider-Identitäten, password_hash NULL-fähig |
| `19_mfa_structure.sql`             | MFA/2FA-Kern: Registry, TOTP, Backup-Codes, Trusted Devices |
| `20_mfa_methods_structure.sql`     | MFA-Methoden: E-Mail-Einmalcodes + WebAuthn/Passkeys        |
| `21_gym_health_structure.sql`      | Gym Health/Blutwerte: Key-Value-Zeitreihe + Nav-Eintrag     |
| `22_gym_body_height.sql`           | Gym Körpermaße: `height_cm`-Spalte                          |
| `23_gym_plan_day_split.sql`        | Gym Plan-Tage: `split`-Spalte (push/pull/legs/…)            |
| `90_testaccount_reset.sql`         | Wartung: Cypress-Testaccount zurücksetzen (optional)        |

**Konvention:** Erst alle Struktur- **und** Seed-Dateien in Nummern-Reihenfolge
ausführen. Alles ist idempotent (`IF NOT EXISTS`, `INSERT IGNORE`,
`ON DUPLICATE KEY UPDATE`), also gefahrlos wiederholbar. Cross-Modul-Nav-Backfills
(z.B. Mega-Menü-Beschreibungen in `04`) setzen voraus, dass die referenzierten
Basis-Nav-Einträge im Live-Bestand existieren — auf einer frischen DB no-op'en sie
gefahrlos.

## Authorization-System (Referenz)

Das RBAC/PBAC-System (`01`/`02`) besteht aus 5 Haupttabellen und 1 Seed:

1. **mbc_roles** - Rollen (user, moderator, admin, etc.)
2. **mbc_permissions** - Berechtigungen (articles.create, users.update.any, etc.)
3. **mbc_user_roles** - Many-to-Many Zuordnung User ↔ Roles
4. **mbc_role_permissions** - Many-to-Many Zuordnung Roles ↔ Permissions
5. **mbc_user_permissions** - Many-to-Many Zuordnung User ↔ Permissions (optional, für individuelle Permissions)

## Installation

### Voraussetzungen

- MySQL 5.7+ oder MariaDB 10.2+
- Bestehende `mbc_users` Tabelle (wird durch Foreign Keys referenziert)
- Datenbank mit Präfix "mbc" (konfigurierbar)

### Alle Dateien in Reihenfolge ausführen

```bash
# Alle nummerierten Dateien in Reihenfolge (Struktur + Seed) einspielen
# (90_* nur bei Bedarf — Wartung, kein Setup):
for f in $(ls [0-8][0-9]_*.sql | sort); do
  echo ">> $f"
  mysql -u username -p database_name < "$f"
done
```

**Oder einzeln / via MySQL Workbench / phpMyAdmin:** Inhalt jeder Datei in
Nummern-Reihenfolge nacheinander ausführen (siehe Dateiübersicht oben).

Das Core-Seed (`02_core_seed.sql`) erstellt u.a.:
- 5 Standard-Rollen (guest, user, moderator, admin, super_admin)
- 35+ Standard-Permissions für Articles, Users, Comments, Admin
- Zuweisungen von Permissions zu Rollen

> **Hinweis:** Modul-Permissions/Nav-Einträge (Warehouse, Email, Gym, Grow …)
> liegen im jeweiligen `*_seed.sql` des Moduls, nicht im Core-Seed.

### Schritt 3: Test-Daten (Optional)

Wenn du einen Test-Admin-User erstellen möchtest:

```sql
-- Super Admin User erstellen
INSERT INTO mbc_users (email, username, password, bio, image)
VALUES (
  'admin@example.com',
  'admin',
  '$2y$10$KIXzBcjH9Y2kN3QZqL1bP.8E2eX5vHJXfL0YpN8yZ1zN5qL1bP.8E', -- Passwort: admin123
  'System Administrator',
  ''
);

-- Super Admin Rolle zuweisen
INSERT INTO mbc_user_roles (user_id, role_id)
SELECT u.id, r.id FROM mbc_users u, mbc_roles r
WHERE u.username = 'admin' AND r.name = 'super_admin';
```

**Hinweis:** Das obige Passwort ist nur ein Beispiel-Hash. Generiere einen eigenen mit:

```php
echo password_hash('dein_passwort', PASSWORD_BCRYPT);
```

## Tabellen-Details

### 1. mbc_roles

Speichert alle Rollen im System.

**Spalten:**
- `id` - Primary Key
- `name` - Technischer Name (z.B. "admin", "moderator")
- `display_name` - Anzeigename für UI
- `description` - Beschreibung der Rolle
- `created_at`, `updated_at` - Timestamps

**Beispiel-Daten:**
```
| id | name        | display_name | description                |
|----|-------------|--------------|----------------------------|
| 1  | guest       | Guest        | Nicht authentifiziert      |
| 2  | user        | User         | Standard-Benutzer          |
| 3  | moderator   | Moderator    | Community-Moderator        |
| 4  | admin       | Administrator| System-Administrator       |
| 5  | super_admin | Super Admin  | Super-Administrator        |
```

### 2. mbc_permissions

Speichert alle Berechtigungen.

**Spalten:**
- `id` - Primary Key
- `name` - Permission-Name (z.B. "articles.update.own")
- `resource` - Resource-Name (z.B. "articles")
- `action` - Aktion (z.B. "update")
- `scope` - Scope: "own" oder "any" (NULL für kein Scope)
- `description` - Beschreibung
- `created_at` - Timestamp

**Permission-Naming-Convention:**
```
{resource}.{action}.{scope}
```

**Beispiele:**
- `articles.read` - Artikel lesen
- `articles.create` - Artikel erstellen
- `articles.update.own` - Eigene Artikel bearbeiten
- `articles.update.any` - Beliebige Artikel bearbeiten
- `articles.delete.own` - Eigene Artikel löschen
- `articles.delete.any` - Beliebige Artikel löschen

### 3. mbc_user_roles

Many-to-Many Zuordnung zwischen Users und Roles.

**Spalten:**
- `user_id` - Foreign Key zu mbc_users
- `role_id` - Foreign Key zu mbc_roles
- `assigned_at` - Wann wurde die Rolle zugewiesen
- `assigned_by` - User-ID des Zuweisers (optional)

**Composite Primary Key:** (user_id, role_id)

### 4. mbc_role_permissions

Many-to-Many Zuordnung zwischen Roles und Permissions.

**Spalten:**
- `role_id` - Foreign Key zu mbc_roles
- `permission_id` - Foreign Key zu mbc_permissions
- `created_at` - Timestamp

**Composite Primary Key:** (role_id, permission_id)

### 5. mbc_user_permissions

Many-to-Many Zuordnung für individuelle User-Permissions (optional).

**Spalten:**
- `user_id` - Foreign Key zu mbc_users
- `permission_id` - Foreign Key zu mbc_permissions
- `granted_at` - Wann wurde die Permission gewährt
- `granted_by` - User-ID des Gewährers (optional)
- `expires_at` - Ablaufdatum (optional)

**Use Case:** Für temporäre oder spezielle Berechtigungen einzelner User.

## Standard-Rollen und Permissions

### Guest (nicht authentifiziert)
- `articles.read`
- `comments.read`

### User (Standard-Benutzer)
- Alle Guest-Permissions +
- `articles.create`
- `articles.update.own`
- `articles.delete.own`
- `comments.create`
- `comments.update.own`
- `comments.delete.own`
- `users.read`
- `users.update.own`
- `files.upload`
- `files.delete.own`

### Moderator (Community-Moderator)
- Alle User-Permissions +
- `articles.update.any`
- `articles.delete.any`
- `articles.publish`
- `comments.update.any`
- `comments.delete.any`
- `comments.moderate`
- `users.ban`
- `users.unban`
- `files.delete.any`

### Admin (System-Administrator)
- Alle Moderator-Permissions +
- `users.create`
- `users.update.any`
- `users.delete.any`
- `admin.access`
- `admin.dashboard`
- `settings.manage`
- `roles.read`
- `permissions.read`

### Super Admin (Super-Administrator)
- **ALLE** Permissions im System
- Inklusive:
  - `roles.manage` - Rollen erstellen, bearbeiten, löschen
  - `permissions.manage` - Permissions verwalten

## Foreign Key Constraints

Alle Tabellen verwenden `ON DELETE CASCADE` und `ON UPDATE CASCADE`:

- Wenn ein User gelöscht wird → alle User-Roles und User-Permissions werden gelöscht
- Wenn eine Role gelöscht wird → alle User-Roles und Role-Permissions werden gelöscht
- Wenn eine Permission gelöscht wird → alle Role-Permissions und User-Permissions werden gelöscht

**Vorsicht:** Das Löschen von Rollen oder Permissions kann weitreichende Folgen haben!

## Indizes

Alle Tabellen haben Performance-Indizes auf wichtigen Spalten:

- `mbc_roles`: Index auf `name`
- `mbc_permissions`: Index auf `name` und `(resource, action)`
- `mbc_user_roles`: Indizes auf `user_id` und `role_id`
- `mbc_role_permissions`: Indizes auf `role_id` und `permission_id`
- `mbc_user_permissions`: Indizes auf `user_id`, `permission_id` und `expires_at`

## Migration von existierenden Usern

Wenn bereits User in der Datenbank existieren, müssen diese Rollen zugewiesen bekommen:

### Option 1: Allen Usern die "user"-Rolle geben

```sql
INSERT IGNORE INTO mbc_user_roles (user_id, role_id)
SELECT u.id, r.id FROM mbc_users u
CROSS JOIN mbc_roles r
WHERE r.name = 'user'
AND NOT EXISTS (
  SELECT 1 FROM mbc_user_roles ur
  WHERE ur.user_id = u.id
);
```

### Option 2: Spezifischen Usern Admin-Rollen geben

```sql
-- Admins (per Email)
INSERT INTO mbc_user_roles (user_id, role_id)
SELECT u.id, r.id FROM mbc_users u, mbc_roles r
WHERE u.email IN ('admin1@example.com', 'admin2@example.com')
AND r.name = 'admin';

-- Super Admins (per Username)
INSERT INTO mbc_user_roles (user_id, role_id)
SELECT u.id, r.id FROM mbc_users u, mbc_roles r
WHERE u.username IN ('superadmin', 'root')
AND r.name = 'super_admin';
```

### Option 3: Via API-Endpunkt

```http
POST /api/users/{id}/roles
Authorization: Token <admin-token>
Content-Type: application/json

{
  "roleName": "user"
}
```

## Troubleshooting

### Fehler: "Cannot add foreign key constraint"

**Ursache:** Die `mbc_users` Tabelle existiert nicht oder hat einen anderen Namen.

**Lösung:**
1. Prüfe ob `mbc_users` existiert: `SHOW TABLES LIKE 'mbc_users';`
2. Passe das Präfix in der `.env` Datei an: `DB_PREFIX=mbc`
3. Passe ggf. die SQL-Dateien an, wenn deine Users-Tabelle anders heißt

### Fehler: "Duplicate entry"

**Ursache:** Seed-Daten wurden bereits eingefügt.

**Lösung:** Die Seed-Datei verwendet `INSERT IGNORE` und `ON DUPLICATE KEY UPDATE`, daher sollte dies kein Problem sein. Wenn doch, lösche die Daten und füge sie erneut ein.

### Foreign Key Fehler bei User-Löschung

**Ursache:** `ON DELETE CASCADE` funktioniert nur, wenn die Tabellen-Engine InnoDB ist.

**Lösung:**
```sql
-- Prüfe Engine
SHOW CREATE TABLE mbc_user_roles;

-- Konvertiere zu InnoDB (falls nötig)
ALTER TABLE mbc_user_roles ENGINE=InnoDB;
```

## Backup & Rollback

### Backup erstellen

```bash
mysqldump -u username -p database_name \
  mbc_roles \
  mbc_permissions \
  mbc_user_roles \
  mbc_role_permissions \
  mbc_user_permissions \
  > authorization_backup.sql
```

### Rollback (Tabellen löschen)

```sql
-- VORSICHT: Löscht alle Daten!
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS mbc_user_permissions;
DROP TABLE IF EXISTS mbc_role_permissions;
DROP TABLE IF EXISTS mbc_user_roles;
DROP TABLE IF EXISTS mbc_permissions;
DROP TABLE IF EXISTS mbc_roles;
SET FOREIGN_KEY_CHECKS = 1;
```

## Weitere Ressourcen

- [docs/guides/authorization.md](../../docs/guides/authorization.md) - Authorization-Guide (API & Konzept)
- [CLAUDE.md](../../CLAUDE.md) - Projekt-Dokumentation

---

**Version:** 1.0
**Datum:** 26. Oktober 2025
**Status:** Ready for Production
