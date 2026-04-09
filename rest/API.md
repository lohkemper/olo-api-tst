# MBC REST API - Quick Reference

## 📚 Vollständige Dokumentation

**Interaktive API-Dokumentation (Swagger UI):**
👉 http://localhost/rest/?r=docs

**OpenAPI 3.0 Spezifikation:**
👉 http://localhost/rest/?r=openapi.yaml

---

## 🔐 Authentication & Security

### Login
```http
POST /rest/?r=auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "your-password"
}
```

**Response:**
```json
{
  "user": {
    "users_id": 1,
    "email": "user@example.com",
    "username": "john_doe",
    "roles": [...],
    "permissions": [...]
  },
  "csrfToken": "abc123..."
}
```

**Wichtig:**
- ✅ JWT wird als **HttpOnly Cookie** gesetzt
- ✅ CSRF-Token muss bei **POST/PUT/DELETE** im Header mitgeschickt werden:
  `X-CSRF-Token: abc123...`
- ✅ **Rate Limit:** 5 Versuche / 5 Minuten pro IP

### Logout
```http
POST /rest/?r=auth/logout
Cookie: jwt_token=...
```

### Current User
```http
GET /rest/?r=auth/user
Cookie: jwt_token=...
```

---

## 👥 Users

### List Users
```http
GET /rest/?r=users
Cookie: jwt_token=...
```

**Query Parameters:**
- `is_active=true` - Filter nach aktiven Usern
- `page=1` - Pagination
- `limit=20` - Einträge pro Seite

### Get User by ID
```http
GET /rest/?r=users/1
Cookie: jwt_token=...
```

---

## 🔑 Roles & Permissions

### List Roles
```http
GET /rest/?r=roles
Cookie: jwt_token=...
```

### List Permissions
```http
GET /rest/?r=permissions
Cookie: jwt_token=...
```

### Create Permission
```http
POST /rest/?r=permissions
Cookie: jwt_token=...
X-CSRF-Token: abc123...
Content-Type: application/json

{
  "name": "articles.create.own",
  "resource": "articles",
  "action": "create",
  "scope": "own",
  "description": "Erlaubt das Erstellen eigener Artikel"
}
```

**Scopes:**
- `own` - Nur eigene Ressourcen
- `any` - Alle Ressourcen

**Actions:**
- `create`, `read`, `update`, `delete`

### Assign Role to User
```http
POST /rest/?r=users/1/roles
Cookie: jwt_token=...
X-CSRF-Token: abc123...
Content-Type: application/json

{
  "role_id": 2
}
```

### Remove Role from User
```http
DELETE /rest/?r=users/1/roles/2
Cookie: jwt_token=...
X-CSRF-Token: abc123...
```

---

## 🏥 System Endpoints

### Health Check
```http
GET /rest/?r=health
```

**Response (200 OK):**
```json
{
  "status": "healthy",
  "timestamp": "2025-12-24 12:00:00",
  "checks": {
    "database": {"status": "ok", "message": "Database connection successful"},
    "session": {"status": "ok", "message": "Session active"},
    "filesystem": {"status": "ok", "message": "Filesystem writable"},
    "environment": {"status": "ok", "message": "All environment variables present"}
  },
  "system": {
    "php_version": "8.2.0",
    "memory_usage": "10.5 MB",
    "memory_peak": "12.3 MB"
  }
}
```

**Response (503 Service Unavailable):**
```json
{
  "status": "unhealthy",
  "checks": {
    "database": {"status": "error", "message": "Database connection failed"}
  }
}
```

---

## 📝 Generic CRUD Operations

Für alle Datenbank-Tabellen (z.B. `articles`, `warehouse-items`, etc.):

### GET (Read)
```http
# Alle Einträge
GET /rest/?r=articles

# Einzelner Eintrag
GET /rest/?r=articles/1

# Mit Filter
GET /rest/?r=articles?is_published=true

# Mit Pagination
GET /rest/?r=articles?page=1&limit=10
```

### POST (Create)
```http
POST /rest/?r=articles
Cookie: jwt_token=...
X-CSRF-Token: abc123...
Content-Type: application/json

{
  "title": "Neuer Artikel",
  "content": "Artikel-Inhalt...",
  "is_published": true
}
```

### PUT (Update)
```http
PUT /rest/?r=articles/1
Cookie: jwt_token=...
X-CSRF-Token: abc123...
Content-Type: application/json

{
  "title": "Aktualisierter Titel",
  "is_published": false
}
```

### DELETE
```http
DELETE /rest/?r=articles/1
Cookie: jwt_token=...
X-CSRF-Token: abc123...
```

---

## 🛡️ Security Features

### CSRF Protection
**Alle POST/PUT/DELETE Requests** benötigen den CSRF-Token:

```http
POST /rest/?r=articles
X-CSRF-Token: abc123...
Cookie: jwt_token=...
```

**Ausnahmen (kein CSRF erforderlich):**
- `POST /auth/login`
- `POST /auth/register`

### Rate Limiting
- **Login:** 5 Versuche / 5 Minuten pro IP
- Bei Überschreitung: **429 Too Many Requests**

### Session Security
- HttpOnly Cookies (kein JavaScript-Zugriff)
- SameSite Protection (CSRF-Schutz)
- Automatische Session-ID Regeneration
- User-Agent Validation (Session-Hijacking-Schutz)

---

## ⚠️ Error Responses

### 400 Bad Request
```json
{
  "error": "Bad Request",
  "message": "Missing required field: email"
}
```

### 401 Unauthorized
```json
{
  "error": "Unauthorized",
  "message": "Authentication required"
}
```

### 403 Forbidden
```json
{
  "error": "Forbidden",
  "message": "Invalid or missing CSRF token"
}
```

### 404 Not Found
```json
{
  "error": "Not Found",
  "message": "Resource not found"
}
```

### 429 Too Many Requests
```json
{
  "error": "Too Many Requests",
  "message": "Rate limit exceeded. Please try again later.",
  "retryAfter": 300,
  "resetAt": "2025-12-24 12:35:00"
}
```

### 500 Internal Server Error
```json
{
  "error": "Internal Server Error",
  "message": "An unexpected error occurred"
}
```

**Debug-Modus (`DEBUG=true`):**
```json
{
  "error": "Internal Server Error",
  "message": "Detaillierte Fehlermeldung...",
  "trace": "Stack Trace...",
  "file": "/path/to/file.php",
  "line": 42
}
```

---

## 📊 Logging & Monitoring

Alle Requests werden geloggt:

```bash
# Application Logs (30 Tage Retention)
tail -f public-server/rest/logs/app.log

# Error Logs (90 Tage Retention)
tail -f public-server/rest/logs/error.log
```

**Log-Format:**
```
[2025-12-24 12:00:00] mbc-api.INFO: HTTP Request {"method":"GET","path":"users","status_code":200,"duration_ms":45.23}
[2025-12-24 12:01:00] mbc-api.WARNING: Security Event: Failed login attempt {"email":"user@example.com","ip":"192.168.1.1"}
[2025-12-24 12:02:00] mbc-api.ERROR: Exception occurred {"exception":"PDOException","message":"Connection failed"}
```

---

## 🧪 Testing mit cURL

### Login + Get Users
```bash
# 1. Login (speichert Cookies)
curl -X POST http://localhost/rest/?r=auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}' \
  -c cookies.txt \
  -v

# 2. Extract CSRF Token from response
CSRF_TOKEN=$(curl -s -X POST http://localhost/rest/?r=auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}' \
  -c cookies.txt | jq -r '.csrfToken')

# 3. Get Users (mit Cookie)
curl http://localhost/rest/?r=users \
  -b cookies.txt

# 4. Create Article (mit CSRF-Token)
curl -X POST http://localhost/rest/?r=articles \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF_TOKEN" \
  -b cookies.txt \
  -d '{"title":"Test Article","content":"Content..."}'
```

---

## 📖 Weitere Ressourcen

- **TESTING.md** - Detaillierte Test-Szenarien
- **Swagger UI** - `/rest/?r=docs` (Interaktive Dokumentation)
- **OpenAPI Spec** - `/rest/?r=openapi.yaml` (Maschinenlesbare Spezifikation)

---

**Version:** 2.0.0
**Letzte Aktualisierung:** 2025-12-24
