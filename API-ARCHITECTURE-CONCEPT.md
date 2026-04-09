# API Architecture Concept - Framework-Agnostic Blueprint

**Version:** 1.0
**Date:** 2025-11-15
**Purpose:** Vollständige Dokumentation der API-Architektur zur Reimplementierung in beliebigen Sprachen/Frameworks

---

## Inhaltsverzeichnis

1. [Architektur-Überblick](#1-architektur-überblick)
2. [Routing-Mechanismus](#2-routing-mechanismus)
3. [Authentifizierung (JWT)](#3-authentifizierung-jwt)
4. [Autorisierung (RBAC + PBAC)](#4-autorisierung-rbac--pbac)
5. [Datenbank-Layer](#5-datenbank-layer)
6. [Request/Response-Handling](#6-requestresponse-handling)
7. [Error-Handling](#7-error-handling)
8. [Logging-System](#8-logging-system)
9. [CORS & Security](#9-cors--security)
10. [File-Upload-System](#10-file-upload-system)
11. [Konfiguration & Environment](#11-konfiguration--environment)
12. [Implementierungs-Patterns](#12-implementierungs-patterns)

---

## 1. Architektur-Überblick

### 1.1 Architektur-Typ

**Custom Framework** mit folgenden Prinzipien:

- **Convention over Configuration**: Dynamische Table-Discovery, automatische CRUD-Generierung
- **Separation of Concerns**: Klare Trennung von Routing, Auth, Business Logic, Data Access
- **Object-Oriented Design**: Vererbungshierarchie mit spezialisiertem Base-Handler
- **Zero-Dependency**: Keine externen Bibliotheken (außer PDO für Datenbank)

### 1.2 Architektur-Schichten

```
┌─────────────────────────────────────────────┐
│  Entry Point (index.php)                    │
│  - CORS Handling                            │
│  - Request Parsing                          │
│  - Bootstrap                                │
└─────────────────┬───────────────────────────┘
                  │
┌─────────────────▼───────────────────────────┐
│  Router (request.php)                       │
│  - Path Parsing                             │
│  - Specialized Route Matching               │
│  - Generic Table Route Matching             │
└─────────────────┬───────────────────────────┘
                  │
        ┌─────────┴─────────┐
        │                   │
┌───────▼────────┐ ┌────────▼────────────────┐
│  Specialized   │ │  Generic Handlers       │
│  Handlers      │ │  - GET (read)           │
│  - Auth        │ │  - POST (create)        │
│  - Login       │ │  - PUT (update)         │
│  - Register    │ │  - DELETE (delete)      │
│  - Roles       │ │  - POSTFILE (upload)    │
│  - Permissions │ │  - OPTIONS (preflight)  │
└───────┬────────┘ └────────┬────────────────┘
        │                   │
        └─────────┬─────────┘
                  │
┌─────────────────▼───────────────────────────┐
│  Base Handler (base.php)                    │
│  - Authentication Methods                   │
│  - Authorization Methods                    │
│  - Logging Methods                          │
│  - Error Handling                           │
└─────────────────┬───────────────────────────┘
                  │
┌─────────────────▼───────────────────────────┐
│  Auth Helper (auth-helper.php)              │
│  - JWT Validation                           │
│  - Token Extraction                         │
│  - User Loading                             │
│  - Role & Permission Loading                │
└─────────────────┬───────────────────────────┘
                  │
┌─────────────────▼───────────────────────────┐
│  Configuration (cfg.php)                    │
│  - Database Connection (PDO)                │
│  - Environment Variables                    │
│  - Table Discovery                          │
│  - Global Constants                         │
└─────────────────────────────────────────────┘
```

### 1.3 Verzeichnisstruktur

```
public-server/rest/
│
├── index.php                  # Entry Point & CORS
├── .htaccess                  # Apache Rewrite Rules
├── cors-fix.php               # Alternative CORS Handler
│
├── cfg/
│   └── cfg.php                # Konfiguration & DB-Connection
│
├── auth/
│   └── auth-helper.php        # JWT & User-Loading
│
└── request/
    ├── request.php            # Router
    ├── base.php               # Abstract Base Handler
    │
    ├── get.php                # Generic GET Handler
    ├── post.php               # Generic POST Handler
    ├── put.php                # Generic PUT Handler
    ├── delete.php             # Generic DELETE Handler
    ├── postfile.php           # File Upload Handler
    ├── options.php            # OPTIONS Preflight Handler
    │
    ├── get-auth.php           # GET /auth/user, /auth/csrf-token
    ├── post-login.php         # POST /auth/login
    ├── post-register.php      # POST /auth/register
    ├── post-auth.php          # POST /auth/refresh
    ├── get-roles.php          # GET /roles
    ├── get-permissions.php    # GET /permissions
    └── post-user-roles.php    # POST /users/{id}/roles
```

---

## 2. Routing-Mechanismus

### 2.1 Request Flow

```
1. HTTP Request
   ↓
2. Apache .htaccess Rewrite
   /auth/login → index.php?r=auth/login
   ↓
3. index.php
   - CORS Headers setzen
   - OPTIONS preflight handling
   - Request Path extrahieren
   ↓
4. Router (request.php)
   - Path parsen: ["auth", "login"]
   - Request-Array erzeugen
   ↓
5. Route Matching (Priorität):
   a) Specialized Routes (Auth, Roles, Permissions)
   b) Generic Table Routes (Dynamic CRUD)
   ↓
6. Handler Instantiation
   - PDO übergeben
   - Method setzen (GET/POST/PUT/DELETE)
   - Request-Array setzen
   - Data/Files setzen (bei POST/PUT)
   ↓
7. Handler Execution
   - Auth/Authorization-Checks
   - Business Logic
   - Database Query
   - JSON Response
```

### 2.2 Path Parsing Logic

**Beispiele:**

```
Input: /auth/login
Parse: ["auth", "login"]
Result: {area: "auth", subroute: "login"}

Input: /users/123/roles
Parse: ["users", "123", "roles"]
Result: {area: "users", user_id: 123, subroute: "roles"}

Input: /articles/5
Parse: ["articles", "5"]
Result: {area: "articles", id: 5}

Input: /articles/1,2,3
Parse: ["articles", "1,2,3"]
Result: {area: "articles", id: [1, 2, 3]}

Input: /articles/0-10
Parse: ["articles", "0-10"]
Result: {area: "articles", limit: "0-10"}

Input: /articles?status=published
Parse: ["articles"] + Query Params
Result: {area: "articles", status: "published"}
```

**Parsing-Regeln:**

1. **Erste Segment** = `area` (Ressource/Tabelle)
2. **Zweite Segment numerisch** = `id` (oder `limit` bei Format `0-10`)
3. **Zweite Segment nicht-numerisch** = `subroute` (z.B. "login", "roles")
4. **Komma-separiert** = Array von IDs: `[1, 2, 3]`
5. **Query-Parameter** = werden in Request-Array übernommen
6. **Dritte Segment** = `subroute` (bei Pattern `/users/{id}/roles`)

### 2.3 Route Matching Strategie

**Priorität 1: Specialized Routes**

```
Route Pattern              → Handler Class          → HTTP Methods
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
/auth/login                → requestPostLogin       → POST
/auth/register             → requestPostRegister    → POST
/auth/user                 → requestGetAuth         → GET
/auth/csrf-token           → requestGetAuth         → GET
/auth/refresh              → requestPostAuth        → POST
/roles                     → requestGetRoles        → GET
/roles/{id}                → requestGetRoles        → GET
/permissions               → requestGetPermissions  → GET
/permissions/{id}          → requestGetPermissions  → GET
/users/{id}/roles          → requestPostUserRoles   → POST
```

**Priorität 2: Generic Table Routes**

```
Pattern                    → Handler Class          → Aktion
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
GET    /{table}            → requestGet             → List all
GET    /{table}/{id}       → requestGet             → Get single
GET    /{table}/0-10       → requestGet             → List with limit
POST   /{table}            → requestPost            → Create
PUT    /{table}/{id}       → requestPut             → Update single
PUT    /{table}/1,2,3      → requestPut             → Update multiple
DELETE /{table}/{id}       → requestDelete          → Delete single
DELETE /{table}/1,2,3      → requestDelete          → Delete multiple
POST   /{table}/upload     → requestPostfile        → File upload
OPTIONS /{table}           → requestOptions         → CORS preflight
```

**Bedingung für Generic Routes:**

- Tabelle muss in Datenbank existieren
- Tabellenname muss mit definiertem Prefix beginnen (z.B. `mbc_`)
- Tabelle wird bei Startup via `SHOW TABLES` erkannt

### 2.4 Handler-Vererbung

```
Abstract RequestBase
    │
    ├── requestGet
    ├── requestPost
    ├── requestPut
    ├── requestDelete
    ├── requestPostfile
    ├── requestOptions
    │
    ├── requestGetAuth
    ├── requestPostLogin
    ├── requestPostRegister
    ├── requestPostAuth
    ├── requestGetRoles
    ├── requestGetPermissions
    └── requestPostUserRoles
```

**RequestBase stellt bereit:**

- `protected log(mixed $msg, string $type = 'info'): void`
- `protected handleError(string $message, Throwable $e = null): void`
- `protected getCurrentUser(): ?array`
- `protected isAuthenticated(): bool`
- `protected requireAuth(): array`
- `protected hasRole(array $user, string $role): bool`
- `protected requireRole(string $role): array`
- `protected hasPermission(array $user, string $permission): bool`
- `protected requirePermission(string $permission): array`
- `protected can(array $user, string $action, string $resource, ?array $entity): bool`
- `protected requireCan(string $action, string $resource, ?array $entity): array`

---

## 3. Authentifizierung (JWT)

### 3.1 JWT-Struktur

**Format:** `header.payload.signature`

**Header:**
```json
{
  "typ": "JWT",
  "alg": "HS256"
}
```

**Payload:**
```json
{
  "userId": 1,
  "username": "john",
  "email": "john@example.com",
  "iat": 1698765432,
  "exp": 1698851832
}
```

**Signature:**
```
HMACSHA256(
  base64UrlEncode(header) + "." + base64UrlEncode(payload),
  JWT_SECRET
)
```

### 3.2 Token-Generierung

**Algorithmus:**

1. Erstelle Header (JSON)
2. Erstelle Payload (JSON) mit:
   - `userId`, `username`, `email`
   - `iat` (issued at): aktuelle Unix-Timestamp
   - `exp` (expires): `iat + 24 Stunden`
3. Base64Url-Encode Header und Payload
4. Signiere mit HMAC-SHA256 und `JWT_SECRET`
5. Verbinde: `{header}.{payload}.{signature}`

**Token-Lebensdauer:** 24 Stunden

### 3.3 Token-Validierung

**Prozess:**

1. **Token extrahieren** aus HTTP-Header:
   - `Authorization: Token <jwt>`
   - `Authorization: Bearer <jwt>`

2. **Token splitten** in 3 Teile (Header, Payload, Signature)

3. **Payload decodieren** (Base64Url-Decode + JSON-Parse)

4. **Expiration prüfen**:
   - Wenn `exp < current_timestamp` → 401 Unauthorized
   - Sonst → Token gültig

5. **User laden** aus Datenbank via `userId` aus Payload

6. **Roles & Permissions laden** für User

7. **Cachen** in `$currentUser` Property für Request-Lifetime

**Wichtig:** Signature-Validierung erfolgt durch Neuberechnung und Vergleich

### 3.4 Authentication Endpoints

#### POST /auth/login

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Prozess:**

1. Validiere Input (email + password vorhanden)
2. Finde User in Datenbank via `email`
3. Wenn User nicht gefunden → 401 "Invalid credentials"
4. Vergleiche Passwort-Hash: `password_verify($password, $user['password'])`
5. Wenn Passwort falsch → 401 "Invalid credentials"
6. Lade Roles & Permissions für User
7. Generiere JWT Token
8. Generiere CSRF Token (random + Session-Speicher)
9. Return User + accessToken + csrfToken

**Response:**
```json
{
  "user": {
    "id": 1,
    "email": "user@example.com",
    "username": "john",
    "bio": "",
    "image": "",
    "roles": [
      {"id": 2, "name": "user", "description": "..."}
    ],
    "permissions": [
      {"id": 5, "name": "articles.read", "description": "..."},
      {"id": 6, "name": "articles.create", "description": "..."}
    ]
  },
  "accessToken": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "csrfToken": "a1b2c3d4e5f6..."
}
```

#### POST /auth/register

**Request:**
```json
{
  "email": "new@example.com",
  "username": "newuser",
  "password": "password123",
  "bio": "Optional bio text",
  "image": "https://example.com/avatar.jpg"
}
```

**Prozess:**

1. Validiere Input:
   - Email format (regex)
   - Email unique (DB-Check)
   - Username unique (DB-Check)
   - Password min. length
2. Hash Passwort: `password_hash($password, PASSWORD_BCRYPT)`
3. Erstelle User in Datenbank
4. Weise Default-Role "user" zu (via `user_roles` Tabelle)
5. Lade vollständigen User mit Roles & Permissions
6. Generiere JWT Token
7. Generiere CSRF Token
8. Return User + accessToken + csrfToken

**Response:** Identisch zu `/auth/login`

#### GET /auth/user

**Request Headers:**
```
Authorization: Token <jwt>
```

**Prozess:**

1. Validiere JWT Token
2. Lade aktuellen User mit Roles & Permissions
3. Return User-Objekt

**Response:**
```json
{
  "user": {
    "id": 1,
    "email": "user@example.com",
    "username": "john",
    "roles": [...],
    "permissions": [...]
  }
}
```

#### GET /auth/csrf-token

**Prozess:**

1. Generiere CSRF-Token (random string)
2. Speichere in Session
3. Return Token

**Response:**
```json
{
  "csrfToken": "a1b2c3d4e5f6..."
}
```

#### POST /auth/refresh

**Request:**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

**Prozess:**

1. Validiere alten JWT Token
2. Wenn gültig: generiere neuen Token mit aktualisiertem `iat` und `exp`
3. Return neuer Token

**Response:**
```json
{
  "accessToken": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

---

## 4. Autorisierung (RBAC + PBAC)

### 4.1 Datenbank-Schema

**Konzeptuelle Struktur:**

```
users
  ├── user_roles ──→ roles
  │                     └── role_permissions ──→ permissions
  │
  └── user_permissions ──→ permissions
```

**Tabellen:**

1. **users**: User-Daten (id, email, username, password, bio, image)
2. **roles**: Rollen (id, name, description)
3. **permissions**: Permissions (id, name, description)
4. **user_roles**: User-zu-Rolle-Zuordnung (user_id, role_id)
5. **role_permissions**: Rolle-zu-Permission-Zuordnung (role_id, permission_id)
6. **user_permissions**: User-zu-Permission-Zuordnung (user_id, permission_id)

**Permissions werden geladen als:**
- Alle Permissions aus `role_permissions` (über `user_roles` verknüpft)
- PLUS alle Permissions aus `user_permissions` (direkte Zuweisung)

### 4.2 Permission-Naming-Convention

**Format:** `{resource}.{action}[.{scope}]`

**Beispiele:**

```
Ressource  Action    Scope    Permission
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
articles   read      -        articles.read
articles   create    -        articles.create
articles   update    own      articles.update.own
articles   update    any      articles.update.any
articles   delete    own      articles.delete.own
articles   delete    any      articles.delete.any
users      ban       -        users.ban
users      manage    -        users.manage
admin      access    -        admin.access
roles      read      -        roles.read
roles      manage    -        roles.manage
```

**Scope-Bedeutung:**

- **ohne Scope**: Generelle Permission für die Aktion
- **`.own`**: Nur für eigene Ressourcen (Ownership-Check)
- **`.any`**: Für alle Ressourcen (Admin-Level)

### 4.3 Standard-Rollen

```
Role          Beschreibung                  Typische Permissions
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
guest         Nicht eingeloggt              Nur öffentliche Inhalte
user          Standard-User                 *.read, *.create, *.update.own, *.delete.own
moderator     Community-Moderator           *.read, *.update.any, users.ban
admin         Administrator                 *.*, roles.manage, users.manage
super_admin   Super-Administrator           *.* (implizit alle Permissions)
```

### 4.4 Authorization-Methods

**In RequestBase verfügbar:**

#### requireAuth()

```
Funktion: Prüft ob User eingeloggt ist
Returns:  User-Array mit Roles & Permissions
Throws:   401 Unauthorized (wenn nicht eingeloggt)

Verwendung:
$user = $this->requireAuth();
```

#### requireRole(string $roleName)

```
Funktion: Prüft ob User spezifische Rolle hat
Returns:  User-Array
Throws:   401 (nicht eingeloggt) oder 403 Forbidden (keine Rolle)

Verwendung:
$user = $this->requireRole('admin');
```

#### requireAnyRole(array $roleNames)

```
Funktion: Prüft ob User EINE der angegebenen Rollen hat
Returns:  User-Array
Throws:   401 oder 403

Verwendung:
$user = $this->requireAnyRole(['admin', 'moderator']);
```

#### requirePermission(string $permissionName)

```
Funktion: Prüft ob User spezifische Permission hat
Returns:  User-Array
Throws:   401 oder 403

Verwendung:
$user = $this->requirePermission('articles.delete.any');
```

#### requireAnyPermission(array $permissionNames)

```
Funktion: Prüft ob User EINE der angegebenen Permissions hat
Returns:  User-Array
Throws:   401 oder 403

Verwendung:
$user = $this->requireAnyPermission(['roles.read', 'admin.access']);
```

#### requireCan(string $action, string $resource, ?array $entity)

```
Funktion: Ownership-basierte Permission-Prüfung
Logic:    1. Prüfe {resource}.{action}.any → erlaubt
          2. Prüfe {resource}.{action}.own + Ownership → erlaubt
          3. Sonst → 403
Returns:  User-Array
Throws:   401 oder 403

Verwendung:
$article = getArticleById($id);
$user = $this->requireCan('delete', 'articles', $article);

Ownership-Check:
- Entity muss Feld "user_id" oder "author_id" haben
- Vergleich mit $user['id']
```

### 4.5 Authorization-Beispiele

**Beispiel 1: Geschützter Endpoint (nur eingeloggt)**

```
class requestGetProfile extends RequestBase {
  public function execute(): void {
    $user = $this->requireAuth();

    // User ist eingeloggt, fahre fort
    $profile = $this->loadProfile($user['id']);
    echo json_encode($profile);
  }
}
```

**Beispiel 2: Admin-Only Endpoint**

```
class requestDeleteUser extends RequestBase {
  public function execute(): void {
    $user = $this->requireRole('admin');

    // User ist Admin, fahre fort
    $this->deleteUser($this->request['id']);
    echo json_encode(['success' => true]);
  }
}
```

**Beispiel 3: Permission-basiert**

```
class requestBanUser extends RequestBase {
  public function execute(): void {
    $user = $this->requirePermission('users.ban');

    // User hat Ban-Permission
    $this->banUser($this->request['id']);
    echo json_encode(['success' => true]);
  }
}
```

**Beispiel 4: Ownership-basiert**

```
class requestDeleteArticle extends RequestBase {
  public function execute(): void {
    $user = $this->requireAuth();
    $article = $this->getArticle($this->request['id']);

    // Prüfe:
    // - articles.delete.any → OK
    // - articles.delete.own + user_id match → OK
    // - sonst → 403
    $this->requireCan('delete', 'articles', $article);

    $this->deleteArticle($article['id']);
    echo json_encode(['success' => true]);
  }
}
```

---

## 5. Datenbank-Layer

### 5.1 Connection Management

**Konfiguration:**

```
Environment Variables (.env):
  DB_DSN=mysql:dbname=database;host=localhost;port=3306
  DB_USERNAME=user
  DB_PASSWORD=password
  DB_PREFIX=mbc

Connection:
  PDO mit UTF-8 Charset
  Error Mode: EXCEPTION
  Fetch Mode: ASSOC
```

**Initialisierung:**

```
1. Lade .env-File (wenn vorhanden)
2. Parse Key-Value-Paare in $_ENV
3. Erstelle PDO-Verbindung
4. Führe Table Discovery aus
5. Stelle PDO global bereit
```

### 5.2 Table Discovery

**Prozess:**

1. Query: `SHOW TABLES`
2. Filter Tabellen mit Prefix (z.B. `mbc_*`)
3. Für jede Tabelle: Extrahiere Tabellenname ohne Prefix
4. Für jede Tabelle: Query `SHOW KEYS WHERE Key_name = 'PRIMARY'`
5. Speichere Primary Key Column Name

**Result:**

```
[
  'users' => ['key' => 'id'],
  'articles' => ['key' => 'id'],
  'roles' => ['key' => 'id'],
  'permissions' => ['key' => 'id'],
  'user_roles' => ['key' => null],  // Keine PK
  ...
]
```

### 5.3 Prepared Statements

**Alle Queries verwenden Prepared Statements:**

```
Query-Beispiel:
  SELECT * FROM mbc_users WHERE id = ?

Execution:
  $stmt = $pdo->prepare($sql);
  $stmt->execute([5]);
  $result = $stmt->fetch();
```

**Parameter-Binding:**

- **Integers**: Cast zu `(int)` vor Binding
- **Strings**: Via `$pdo->quote()` escapen (für dynamische Queries)
- **Arrays**: Iteration mit einzelnen Bindings

**Column Validation:**

- Regex: `/^[a-zA-Z0-9_]+$/`
- Verhindert SQL-Injection über Column-Namen
- Nur alphanumerische Zeichen + Underscore erlaubt

### 5.4 CRUD-Operationen

#### GET (Read)

**Features:**

- Automatische Column-Discovery via `SHOW COLUMNS`
- LEFT JOIN für Foreign Keys (Columns mit Format `{table}_id`)
- LEFT JOIN für File Fields (Columns mit Format `*_file`)
- WHERE-Clause mit Prepared Statements
- LIMIT-Support (`/articles/0-10` → `LIMIT 0, 10`)
- GROUP BY-Support (via Query-Parameter)
- Response: JSON mit camelCase-Conversion

**Query-Aufbau:**

```sql
SELECT
  articles.id AS id,
  articles.title AS title,
  articles.content AS content,
  articles.user_id AS user_id,
  users.id AS users__id,
  users.username AS users__username,
  files.name AS image_file__name
FROM mbc_articles AS articles
LEFT JOIN mbc_users AS users ON articles.user_id = users.id
LEFT JOIN mbc_files AS files ON articles.image_file = files.id
WHERE articles.id = ?
```

**Response-Format:**

```json
{
  "id": 1,
  "title": "Article Title",
  "content": "...",
  "userId": 5,
  "users": {
    "id": 5,
    "username": "john"
  },
  "imageFile": {
    "name": "abc123.jpg"
  }
}
```

#### POST (Create)

**Features:**

- JSON-Body-Parsing
- Type-Inference (int, float, string, bool, null)
- Automatisches Quoting für Strings
- Return: Created Entity via GET nach Insert

**Insert-Query:**

```sql
INSERT INTO mbc_articles
  (title, content, user_id, created_at)
VALUES
  ('Test', 'Content', 5, '2025-11-15 10:00:00')
```

**Response:** Neu erstellte Entity (via GET mit `lastInsertId`)

#### PUT (Update)

**Features:**

- Global `$_PUT` Array (aus `php://input` geparst)
- Column-Validierung
- Support für einzelne ID oder mehrere IDs
- Return: Updated Entity via GET

**Update-Query:**

```sql
UPDATE mbc_articles
SET
  title = 'Updated Title',
  content = 'New content'
WHERE id = 5
```

**Multiple IDs:**

```sql
UPDATE mbc_articles
SET title = 'Same Title'
WHERE id IN (1, 2, 3)
```

#### DELETE (Delete)

**Features:**

- Support für einzelne oder mehrere IDs
- Return: JSON mit gelöschter Anzahl

**Delete-Query:**

```sql
DELETE FROM mbc_articles
WHERE id IN (1, 2, 3)
```

**Response:**

```json
{
  "success": true,
  "deleted": 3
}
```

### 5.5 Foreign Key Detection

**Pattern:**

- Column-Name endet mit `_id` → Foreign Key
- Beispiel: `user_id` → Referenz auf `users` Tabelle
- LEFT JOIN zu `mbc_users` wird automatisch generiert

**Aliasing:**

```sql
LEFT JOIN mbc_users AS users ON articles.user_id = users.id
```

**Response nesting:**

```json
{
  "userId": 5,
  "users": {
    "id": 5,
    "username": "john",
    "email": "john@example.com"
  }
}
```

### 5.6 File Fields Detection

**Pattern:**

- Column-Name endet mit `_file` → File Reference
- Beispiel: `image_file` → Referenz auf `files` Tabelle
- LEFT JOIN zu `mbc_files` wird automatisch generiert

**Files-Tabelle:**

```
mbc_files:
  - id
  - name (unique filename)
  - realname (original filename)
  - type (mime type)
  - size
  - date
  - time
```

---

## 6. Request/Response-Handling

### 6.1 RESTful Endpoints

**Standard CRUD:**

```
HTTP Method  Endpoint             Aktion
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
GET          /{resource}          List all
GET          /{resource}/{id}     Get single
POST         /{resource}          Create
PUT          /{resource}/{id}     Update single
DELETE       /{resource}/{id}     Delete single
OPTIONS      /{resource}          CORS preflight
```

**Filtering & Pagination:**

```
GET /articles?status=published     # Filter via Query-Param
GET /articles/0-10                 # Limit (offset-count)
GET /articles?user_id=5            # Foreign Key Filter
GET /articles?groupby=category     # GROUP BY
```

**Nested Resources:**

```
POST /users/{id}/roles             # Assign role to user
GET  /users/{id}/articles          # (würde Custom Handler brauchen)
```

### 6.2 Request-Format

**Headers:**

```
Content-Type: application/json
Authorization: Token <jwt>
Authorization: Bearer <jwt>
X-CSRF-Token: <csrf-token>
X-Requested-With: XMLHttpRequest
Accept: application/json
```

**Body (POST/PUT):**

```json
{
  "field1": "value",
  "field2": 123,
  "nested": {
    "key": "value"
  }
}
```

**File Upload (multipart/form-data):**

```
POST /api/files/upload
Content-Type: multipart/form-data

file: [binary data]
```

### 6.3 Response-Format

**Success (200 OK):**

```json
{
  "id": 1,
  "title": "Article",
  "content": "...",
  "createdAt": "2025-11-15 10:00:00"
}
```

**List (200 OK):**

```json
[
  {"id": 1, "title": "Article 1"},
  {"id": 2, "title": "Article 2"}
]
```

**Created (201 Created):**

```json
{
  "id": 123,
  "title": "New Article"
}
```

**No Content (204 No Content):**

```
(Leerer Body - z.B. bei OPTIONS)
```

**Error (4xx/5xx):**

```json
{
  "error": "Error Type",
  "message": "Detailed error message"
}
```

**Validation Error (400 Bad Request):**

```json
{
  "error": "Validation failed",
  "errors": {
    "email": "Invalid email format",
    "password": "Password is required"
  }
}
```

### 6.4 HTTP Status Codes

```
Code  Bedeutung              Verwendung
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
200   OK                     Success (GET, PUT, DELETE)
201   Created                Resource created (POST)
204   No Content             Success ohne Body (OPTIONS)
400   Bad Request            Validation Error
401   Unauthorized           Authentication fehlt/ungültig
403   Forbidden              Insufficient Permissions
404   Not Found              Resource nicht gefunden
409   Conflict               Duplicate (z.B. Email exists)
500   Internal Server Error  Unerwarteter Server-Fehler
```

### 6.5 Field Naming Convention

**Datenbank:** `snake_case` (z.B. `created_at`, `user_id`)

**JSON Response:** `camelCase` (z.B. `createdAt`, `userId`)

**Conversion:**

```
Database         JSON Response
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
created_at   →   createdAt
user_id      →   userId
image_file   →   imageFile
is_active    →   isActive
```

**Implementation:**

```
Funktion: snakeToCamel(string $snake): string
  1. Split by underscore
  2. Capitalize first letter of each word (außer erstes)
  3. Join
```

---

## 7. Error-Handling

### 7.1 Error-Response-Pattern

**Standard-Error:**

```json
{
  "error": "Error Type",
  "message": "Human-readable message"
}
```

**Debug-Mode-Error:**

```json
{
  "error": "Error Type",
  "message": "Human-readable message",
  "details": "Exception message",
  "trace": "Stack trace..."
}
```

### 7.2 Error-Types

```
Error Type             HTTP Code   Beschreibung
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Unauthorized           401         Token fehlt/ungültig
Forbidden              403         Insufficient permissions
Not Found              404         Resource nicht gefunden
Validation failed      400         Input-Validierung fehlgeschlagen
Conflict               409         Duplicate entry (Email, Username)
Internal server error  500         Unerwarteter Fehler
Invalid request        400         Malformed request
```

### 7.3 Error-Handling-Methode

**In RequestBase:**

```
handleError(message, exception):
  1. Log error + exception in $this->logs
  2. If DEBUG:
       Return JSON with error + details + trace
     Else:
       Return JSON with error only
  3. Set HTTP Response Code (500)
  4. Exit
```

### 7.4 Validation-Error-Pattern

**Bei Input-Validierung:**

```json
{
  "error": "Validation failed",
  "errors": {
    "email": "Invalid email format",
    "password": "Password must be at least 8 characters",
    "username": "Username is already taken"
  }
}
```

**Validation-Rules (Beispiel Register):**

- Email: Format (Regex), Unique (DB-Check)
- Username: Min. 3 Zeichen, Max. 50 Zeichen, Unique (DB-Check)
- Password: Min. 8 Zeichen

### 7.5 Exception-Handling

**Try-Catch-Blocks:**

```
Jeder Handler hat try-catch in execute():

try {
  // Business Logic
  // Database Queries
  // Response
} catch (Throwable $e) {
  $this->handleError('Error message', $e);
}
```

**Wichtig:**

- Alle Exceptions werden gefangen
- Keine ungefangenen Exceptions nach außen
- Im Debug-Modus: Detaillierte Exception-Info
- Im Production-Modus: Generic Error Message

---

## 8. Logging-System

### 8.1 Logging-Mechanismus

**Konzept:** In-Memory Logging während Request-Lifetime

**Implementation:**

```
protected array $logs = [];

protected function log(mixed $msg, string $type = 'info'): void {
  array_push($this->logs, [$type, $msg]);
}
```

**Log-Entry-Format:**

```
[$type, $msg]

Beispiel:
['info', 'requestGet::execute']
['info', ['requestGet::sql', 'SELECT * FROM...']]
['error', ['error' => 'Not found', 'exception' => '...']]
```

### 8.2 Log-Types

```
Type     Verwendung
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
info     Normale Ablauf-Informationen
debug    Debug-Informationen (SQL, Variablen)
error    Fehler und Exceptions
```

### 8.3 Logging-Points

**Standard Logging in Handlern:**

```
1. Constructor: Klassenname
2. Execute Start: "Class::execute"
3. Request Data: $this->request, $this->data
4. Auth: Authenticated user info
5. SQL: Generated SQL queries
6. Errors: Exception messages + traces
```

**Beispiel-Log-Flow:**

```
['info', 'requestGetAuth']
['info', 'requestGetAuth::execute']
['info', ['requestGetAuth::request', {...}]]
['info', ['Authenticated user', 5, 'john']]
['info', ['SQL Query', 'SELECT * FROM...']]
```

### 8.4 Log-Output (Debug-Mode)

**Im Debug-Modus:**

```
Nach JSON-Response:
  <br>REQUEST<br>
  <pre>
    Array (
      [info, 'requestGet'],
      [info, 'requestGet::execute'],
      ...
    )
  </pre>
```

**Im Production-Modus:**

- Keine Log-Ausgabe
- Logs nur intern gespeichert (für Error-Handling)

### 8.5 Persistentes Logging (Empfehlung)

**Aktuell nicht implementiert, aber empfohlen:**

```
Konzept: Logs in Datei/Datenbank schreiben

Struktur:
  - Timestamp
  - Request ID (für Zuordnung)
  - User ID (wenn eingeloggt)
  - Endpoint
  - Method
  - Log Level
  - Message
  - Context (SQL, Variablen, etc.)

Beispiel-Tabelle:
  CREATE TABLE logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timestamp DATETIME,
    request_id VARCHAR(50),
    user_id INT NULL,
    endpoint VARCHAR(255),
    method VARCHAR(10),
    level VARCHAR(20),
    message TEXT,
    context JSON
  );
```

---

## 9. CORS & Security

### 9.1 CORS-Konfiguration

**Whitelist-Ansatz:**

```
Allowed Origins:
  - http://localhost:4200
  - http://localhost:4201
  - https://oliverlohkemper.de
  - https://www.oliverlohkemper.de

Logic:
  1. Lese HTTP_ORIGIN aus Request
  2. Prüfe ob Origin in Whitelist
  3. Wenn ja: Setze Access-Control-Allow-Origin auf Origin
  4. Setze Access-Control-Allow-Credentials: true
  5. Setze Access-Control-Max-Age: 86400 (24h)
```

**Wichtig:** Kein Wildcard (`*`) mit Credentials!

**CORS-Headers:**

```
Access-Control-Allow-Origin: <origin>
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With, Accept
Access-Control-Max-Age: 86400
```

### 9.2 OPTIONS Preflight Handling

**Browser sendet OPTIONS-Request vor CORS-Request:**

```
Request:
  OPTIONS /api/articles HTTP/1.1
  Origin: http://localhost:4200
  Access-Control-Request-Method: POST
  Access-Control-Request-Headers: Content-Type, Authorization

Response:
  HTTP/1.1 200 OK
  Access-Control-Allow-Origin: http://localhost:4200
  Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
  Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token
  Access-Control-Max-Age: 86400
```

**Implementation:**

```
if (REQUEST_METHOD === 'OPTIONS') {
  // Set CORS headers (already set)
  http_response_code(200);
  exit(0);
}
```

### 9.3 Security-Headers

**Empfohlen (aktuell nicht alle implementiert):**

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'
```

### 9.4 SQL-Injection-Prevention

**Maßnahmen:**

1. **Prepared Statements** für alle Queries
2. **Column-Name-Validation** via Regex: `/^[a-zA-Z0-9_]+$/`
3. **Integer-Casting** für IDs: `(int)$id`
4. **PDO::quote()** für dynamische String-Values

**Beispiel:**

```sql
-- UNSICHER:
$sql = "SELECT * FROM users WHERE id = " . $_GET['id'];

-- SICHER:
$sql = "SELECT * FROM users WHERE id = ?";
$stmt->execute([(int)$_GET['id']]);
```

### 9.5 XSS-Prevention

**Maßnahmen:**

1. **JSON-Encoding** escaped HTML-Entities automatisch
2. **Keine HTML-Ausgabe** (nur JSON)
3. **Content-Type: application/json**

**Beispiel:**

```
Input: <script>alert('XSS')</script>

JSON-Encode:
  "<script>alert('XSS')<\/script>"

Browser interpretiert als String, nicht als HTML
```

### 9.6 CSRF-Protection

**Mechanismus:**

1. **Token-Generierung** via `/auth/csrf-token` oder bei Login
2. **Token-Speicherung** in Session (Server-Side)
3. **Token-Validierung** für POST/PUT/DELETE-Requests
4. **Header:** `X-CSRF-Token: <token>`

**Ablauf:**

```
1. Client: GET /auth/csrf-token
2. Server: Generiere Token, speichere in Session, return Token
3. Client: Speichere Token (z.B. localStorage)
4. Client: POST /articles mit Header X-CSRF-Token: <token>
5. Server: Vergleiche Token mit Session-Token
6. Wenn Match: Proceed, Sonst: 403 Forbidden
```

### 9.7 Password-Security

**Hashing:**

```
Algorithmus: bcrypt (PASSWORD_BCRYPT)
Cost-Factor: Default (10)

Hash:
  $hash = password_hash($password, PASSWORD_BCRYPT);

Verify:
  $valid = password_verify($password, $hash);
```

**Wichtig:**

- Keine Plain-Text-Passwörter in DB
- Kein MD5 oder SHA1 (unsicher)
- Bcrypt mit Salt (automatisch)

### 9.8 JWT-Security

**Best Practices:**

1. **Secret Key** via Environment Variable (nicht in Code)
2. **Token Expiration** (24h)
3. **HTTPS-Only** in Production (Token-Übertragung verschlüsseln)
4. **HttpOnly Cookies** (optional, aktuell nicht implementiert)
5. **Signature Verification** bei jedem Request

---

## 10. File-Upload-System

### 10.1 Upload-Endpoint

**Endpoint:** `POST /{resource}/upload`

**Handler:** `requestPostfile`

**Request:**

```
POST /api/files/upload
Content-Type: multipart/form-data

file: [binary data]
```

### 10.2 Upload-Prozess

**Schritte:**

1. **Validiere File-Upload** (`$_FILES['file']['error'] === 0`)
2. **Validiere File-Type**:
   - Allowed: `jpg`, `png`, `jpeg`, `gif`
   - Extension-Check via `pathinfo()`
3. **Validiere File-Size**:
   - Max: 5MB (5000000 Bytes)
4. **Generiere Unique Filename**:
   - `uniqid() + '.' + extension`
   - Beispiel: `64a7b3c9f123e.jpg`
5. **Move File** nach `../../dist/uploads/{filename}`
6. **Speichere Metadata** in `files` Tabelle:
   - `name`: Unique filename
   - `realname`: Original filename
   - `type`: MIME-Type
   - `size`: File size in bytes
   - `date`: Upload date
   - `time`: Upload time
7. **Return File-Info** als JSON

**Response:**

```json
{
  "id": 42,
  "name": "64a7b3c9f123e.jpg",
  "realname": "avatar.jpg",
  "type": "image/jpeg",
  "size": 123456,
  "date": "2025-11-15",
  "time": "10:30:45"
}
```

### 10.3 File-Validation

**File-Type-Validation:**

```
Allowed Extensions: jpg, png, jpeg, gif

Check:
  1. Get file extension via pathinfo()
  2. Lowercase extension
  3. Check if in allowed array
  4. If not: 400 Bad Request
```

**File-Size-Validation:**

```
Max Size: 5MB (5000000 Bytes)

Check:
  1. Get file size via $_FILES['file']['size']
  2. If > 5000000: 400 Bad Request
```

### 10.4 File-Storage

**Upload-Directory:** `../../dist/uploads/`

**Relative to:** `/public-server/rest/`

**Absolute:** `/public-server/dist/uploads/`

**Filename-Format:** `{uniqid}.{extension}`

**Beispiele:**

```
64a7b3c9f123e.jpg
64a7b3ca01f5a.png
64a7b3ca12abc.gif
```

### 10.5 File-Retrieval

**Files werden via Foreign Key referenziert:**

```
Tabelle: articles
Column: image_file (INT, FK zu files.id)

GET /articles/5:
  SELECT
    articles.*,
    files.name AS image_file__name,
    files.realname AS image_file__realname
  FROM mbc_articles AS articles
  LEFT JOIN mbc_files AS files ON articles.image_file = files.id
  WHERE articles.id = 5
```

**Response:**

```json
{
  "id": 5,
  "title": "Article",
  "imageFile": 42,
  "imageFileData": {
    "name": "64a7b3c9f123e.jpg",
    "realname": "avatar.jpg"
  }
}
```

**File-URL:** `https://example.com/dist/uploads/64a7b3c9f123e.jpg`

---

## 11. Konfiguration & Environment

### 11.1 Environment Variables

**File:** `.env` (im Root-Verzeichnis von `/rest/`)

**Format:**

```env
# Database
DB_DSN=mysql:dbname=mbc_database;host=localhost;port=3306
DB_USERNAME=root
DB_PASSWORD=secret
DB_PREFIX=mbc

# JWT
JWT_SECRET=your-secret-key-change-in-production

# Debug
DEBUG=false
```

**Loading:**

```
1. Prüfe ob .env existiert
2. Lese Zeile für Zeile
3. Parse Key=Value (split bei erstem '=')
4. Speichere in $_ENV Array
5. Nutze $_ENV['KEY'] im Code
```

### 11.2 Global Constants

**Definiert in `cfg.php`:**

```
STOKEN   = 42             # Security Token für Include-Guards
PREFIX   = 'mbc'          # Datenbank-Tabellen-Prefix
DEBUG    = false          # Debug-Modus (true = detaillierte Errors)
JWT_SECRET = '...'        # JWT Signing Key
```

**Verwendung von DEBUG:**

```
if (DEBUG) {
  // Zeige detaillierte Error-Infos
  // Zeige SQL-Queries
  // Zeige Logs
} else {
  // Zeige nur generische Errors
}
```

### 11.3 Upload-Konfiguration

**Definiert in `postfile.php`:**

```
FILE_SIZE_MAX = 5000000              # 5MB in Bytes
FILE_TYPES    = ['jpg', 'png', 'jpeg', 'gif']
```

### 11.4 CORS-Konfiguration

**Definiert in `index.php`:**

```
allowedOrigins = [
  'http://localhost:4200',
  'http://localhost:4201',
  'https://oliverlohkemper.de',
  'https://www.oliverlohkemper.de'
]
```

**Empfehlung:** Via Environment Variable konfigurierbar machen:

```env
CORS_ALLOWED_ORIGINS=http://localhost:4200,https://example.com
```

### 11.5 Database-Konfiguration

**Connection-String (DSN):**

```
Format: mysql:dbname={name};host={host};port={port};charset=utf8mb4

Beispiel:
  mysql:dbname=mbc_database;host=localhost;port=3306;charset=utf8mb4
```

**PDO-Options:**

```
PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION
PDO::ATTR_DEFAULT_FETCH_MODE = PDO::FETCH_ASSOC
```

---

## 12. Implementierungs-Patterns

### 12.1 Convention over Configuration

**Prinzip:** Automatische Generierung von Funktionalität basierend auf Konventionen

**Beispiele:**

1. **Table Discovery**:
   - Alle Tabellen mit Prefix `mbc_*` werden erkannt
   - CRUD-Endpoints automatisch generiert

2. **Foreign Key Detection**:
   - Columns mit `_id` Suffix → Foreign Key
   - Automatischer LEFT JOIN

3. **File Field Detection**:
   - Columns mit `_file` Suffix → File Reference
   - Automatischer LEFT JOIN zu `files` Tabelle

4. **Primary Key Detection**:
   - Via `SHOW KEYS` automatisch ermittelt

**Vorteil:** Minimaler Code für Standard-Operationen

**Nachteil:** Magic Behavior (schwerer zu debuggen)

### 12.2 Inheritance Hierarchy

**Pattern:** Template Method Pattern

**Struktur:**

```
Abstract RequestBase:
  - Stellt Auth/Authorization-Methoden bereit
  - Stellt Logging-Methoden bereit
  - Stellt Error-Handling bereit
  - Abstract execute() Methode

Concrete Handlers (requestGet, requestPost, etc.):
  - Implementieren execute()
  - Nutzen Base-Methoden für Auth/Logging
```

**Vorteil:**

- Code-Reuse (Auth-Logic in Base)
- Konsistenz (alle Handler nutzen gleiche Methoden)
- Erweiterbarkeit (neue Handler erben alles)

### 12.3 Separation of Concerns

**Layer-Aufteilung:**

```
Entry Point (index.php):
  - CORS
  - Request Parsing
  - Bootstrap

Router (request.php):
  - Route Matching
  - Handler Selection

Handlers (request/*.php):
  - Business Logic
  - Response Generation

Base (base.php):
  - Auth/Authorization
  - Logging
  - Error Handling

Auth Helper (auth-helper.php):
  - JWT Validation
  - User Loading

Config (cfg.php):
  - DB Connection
  - Environment Loading
```

**Vorteil:** Jede Schicht hat klare Verantwortlichkeit

### 12.4 Dynamic Resource Discovery

**Pattern:** Reflection/Introspection

**Prozess:**

```
1. Startup: Discover Tables (SHOW TABLES)
2. Startup: Discover Primary Keys (SHOW KEYS)
3. Request: Discover Columns (SHOW COLUMNS)
4. Request: Detect Foreign Keys (Column Name Pattern)
5. Request: Detect File Fields (Column Name Pattern)
6. Request: Build Query dynamisch
```

**Vorteil:** Keine Schema-Definition nötig

**Nachteil:** Performance (mehrere Metadata-Queries)

### 12.5 Specialized vs. Generic Routing

**Pattern:** Chain of Responsibility

**Logic:**

```
1. Prüfe Specialized Routes (hardcoded Mappings)
   - /auth/login → requestPostLogin
   - /roles → requestGetRoles
   - etc.

2. Wenn kein Match: Prüfe Generic Table Routes
   - /{table}/{id} → requestGet/Post/Put/Delete
   - Bedingung: Tabelle existiert in DB

3. Wenn kein Match: 404 Not Found
```

**Vorteil:**

- Specialized: Volle Kontrolle für komplexe Endpoints
- Generic: Automatische CRUD ohne Code

### 12.6 Error Handling Pattern

**Pattern:** Centralized Error Handler

**Struktur:**

```
Try-Catch in execute():
  try {
    // Business Logic
  } catch (Throwable $e) {
    $this->handleError('Message', $e);
  }

handleError():
  1. Log error
  2. Set HTTP status code
  3. Return JSON error
  4. Exit
```

**Vorteil:**

- Konsistente Error-Responses
- Keine ungefangenen Exceptions
- Debugging-Info im Debug-Modus

### 12.7 Logging Pattern

**Pattern:** In-Memory Logging + Debug Output

**Flow:**

```
1. Während Request: log() Aufrufe speichern in $this->logs
2. Bei Error: Log error + exception
3. Am Ende:
   - Production: Keine Output
   - Debug: Print $this->logs
```

**Vorteil:**

- Performance (kein I/O während Request)
- Debugging (kompletter Request-Flow sichtbar)

**Empfehlung für Production:**

- Persistentes Logging (File/DB)
- Structured Logging (JSON)
- Log-Rotation

---

## Zusammenfassung für Reimplementierung

### Must-Have Features

1. **Routing-System**:
   - Path-Parsing nach Schema: `/{area}[/{id|subroute}[/{subroute}]]`
   - Specialized Routes (hardcoded Mappings)
   - Generic Table Routes (auto-CRUD)

2. **JWT-Authentifizierung**:
   - Token-Generierung (HS256, 24h Expiration)
   - Token-Validierung (Header-Extraktion, Signatur-Check, Expiration-Check)
   - User-Loading mit Roles & Permissions

3. **RBAC + PBAC Authorization**:
   - Rollen-System
   - Permission-System mit Scopes (`own` / `any`)
   - Ownership-basierte Checks
   - Authorization-Helper-Methoden

4. **Dynamic CRUD**:
   - Table Discovery
   - Column Discovery
   - Foreign Key Detection & JOIN
   - File Field Detection & JOIN
   - Prepared Statements

5. **Error Handling**:
   - Konsistente JSON-Error-Responses
   - HTTP-Status-Codes
   - Debug-Modus vs. Production-Modus

6. **CORS**:
   - Whitelist-basierte Origin-Validierung
   - OPTIONS-Preflight-Handling
   - Credentials-Support

7. **File Upload**:
   - Validierung (Type, Size)
   - Unique Filename Generation
   - Metadata-Speicherung

8. **Logging**:
   - Request-Lifecycle-Logging
   - Error-Logging
   - Debug-Output

### Nice-to-Have Features

1. **Rate Limiting** (aktuell nicht implementiert)
2. **API Versioning** (`/v1/`, `/v2/`)
3. **GraphQL Support** (zusätzlich zu REST)
4. **WebSocket Support** (für Real-time)
5. **Caching** (Redis, Memcached)
6. **Queue System** (für async Tasks)
7. **API Documentation** (Swagger/OpenAPI)
8. **Automated Testing** (Unit, Integration, E2E)

### Empfohlene Modernisierungen

**Bei Neuimplementierung empfohlen:**

1. **ORM verwenden** (TypeORM, Prisma, Eloquent, SQLAlchemy)
2. **JWT-Library** (jsonwebtoken, jose, PyJWT)
3. **Validation-Library** (class-validator, joi, zod)
4. **Dependency Injection** (NestJS, Spring, ASP.NET Core)
5. **Migrations-System** (Prisma Migrate, TypeORM Migrations)
6. **API-Dokumentation** (Auto-generiert aus Code)
7. **Testing-Framework** (Jest, PHPUnit, pytest)
8. **Logging-Framework** (Winston, Monolog, structlog)

---

## Lizenz & Credits

**Dokumentiert:** 2025-11-15
**Autor:** Claude (Anthropic)
**Projekt:** MBC Legacy API Modernisierung

Diese Dokumentation ist framework-agnostisch und kann als Blueprint für Reimplementierungen in beliebigen Sprachen verwendet werden.
