# Backend Security Testing Guide

## Phase 1 Security Features - Test Plan

### ✅ Implementierte Features

1. **Composer + Professional Libraries**
   - firebase/php-jwt
   - vlucas/phpdotenv
   - monolog/monolog
   - symfony/rate-limiter
   - respect/validation

2. **Environment-based Configuration**
   - Keine hardcoded Credentials
   - .env Validierung beim Start

3. **JWT Authentication**
   - Professional JWT-Implementierung
   - HttpOnly Cookies
   - 24h Expiration

4. **SQL Injection Protection**
   - Tabellennamen-Validierung
   - Prepared Statements

5. **CSRF Protection**
   - Token-basierte Validierung
   - Aktiv für POST/PUT/DELETE

6. **Rate Limiting**
   - Login: 5 Versuche / 5 Minuten pro IP
   - Automatisches Reset bei Erfolg

7. **Secure Session Management**
   - HttpOnly, SameSite Cookies
   - Session ID Regeneration
   - User-Agent Validation

---

## Test-Szenarien

### 1. Login testen

**Endpoint:** `POST /api/auth/login`

**Request:**
```json
{
  "email": "test@example.com",
  "password": "your-password"
}
```

**Erwartetes Ergebnis:**
```json
{
  "user": {
    "users_id": 1,
    "email": "test@example.com",
    "username": "testuser",
    "first_name": "Test",
    "last_name": "User",
    "roles": [...],
    "permissions": [...]
  },
  "csrfToken": "abc123..."
}
```

**Prüfe:**
- ✅ Status Code: 200
- ✅ Response enthält `csrfToken`
- ✅ Cookie `jwt_token` ist gesetzt (HttpOnly)
- ✅ Cookie `PHPSESSID` ist gesetzt

---

### 2. Rate Limiting testen

**Test:** 6x falsches Login hintereinander

**Endpoint:** `POST /api/auth/login`

**Request (6x wiederholen):**
```json
{
  "email": "test@example.com",
  "password": "wrong-password"
}
```

**Erwartetes Ergebnis (beim 6. Versuch):**
```json
{
  "error": "Too Many Requests",
  "message": "Rate limit exceeded. Please try again later.",
  "retryAfter": 300,
  "resetAt": "2025-12-24 12:35:00"
}
```

**Prüfe:**
- ✅ Status Code: 429 (Too Many Requests)
- ✅ Header: `Retry-After: 300`
- ✅ Nach 5 Minuten funktioniert Login wieder

---

### 3. CSRF Protection testen

**Schritt 1:** Login durchführen und CSRF-Token speichern

**Schritt 2:** POST-Request OHNE CSRF-Token

**Endpoint:** `POST /api/articles` (oder ein anderer POST-Endpoint)

**Request (OHNE X-CSRF-Token Header):**
```json
{
  "title": "Test Article",
  "content": "Test Content"
}
```

**Erwartetes Ergebnis:**
```json
{
  "error": "Forbidden",
  "message": "Invalid or missing CSRF token"
}
```

**Prüfe:**
- ✅ Status Code: 403 (Forbidden)

**Schritt 3:** POST-Request MIT CSRF-Token

**Request (MIT X-CSRF-Token Header):**
```http
POST /api/articles
X-CSRF-Token: abc123...

{
  "title": "Test Article",
  "content": "Test Content"
}
```

**Erwartetes Ergebnis:**
- ✅ Status Code: 200
- ✅ Article wird erstellt

---

### 4. JWT Authentication testen

**Schritt 1:** Login durchführen (JWT-Cookie wird gesetzt)

**Schritt 2:** Geschützten Endpoint aufrufen

**Endpoint:** `GET /api/users`

**Request (Cookie wird automatisch mitgesendet):**
```http
GET /api/users
Cookie: jwt_token=eyJ0eXAiOiJKV1Q...
```

**Erwartetes Ergebnis:**
- ✅ Status Code: 200
- ✅ User-Daten werden zurückgegeben

**Schritt 3:** Geschützten Endpoint OHNE JWT aufrufen

**Request (ohne Cookie):**
```http
GET /api/users
```

**Erwartetes Ergebnis:**
- ✅ Status Code: 401 (Unauthorized)

---

### 5. Session Management testen

**Test:** Session-Hijacking-Schutz (User-Agent-Validierung)

**Schritt 1:** Login mit User-Agent "Browser A"

**Schritt 2:** Request mit anderem User-Agent "Browser B" (gleiche Session-ID)

**Erwartetes Ergebnis:**
```json
{
  "error": "Unauthorized",
  "message": "Session validation failed. Please log in again."
}
```

**Prüfe:**
- ✅ Status Code: 401
- ✅ Session wird zerstört

---

### 6. Logout testen

**Endpoint:** `POST /api/auth/logout`

**Erwartetes Ergebnis:**
```json
{
  "message": "Logged out successfully"
}
```

**Prüfe:**
- ✅ Status Code: 200
- ✅ Cookie `jwt_token` wird gelöscht (expires in der Vergangenheit)
- ✅ Session wird zerstört
- ✅ CSRF-Token wird gelöscht

---

### 7. SQL Injection Protection testen

**Test:** Versuch mit manipuliertem Tabellennamen

**Endpoint:** `GET /api/users'; DROP TABLE users; --`

**Erwartetes Ergebnis:**
- ✅ Status Code: 400 oder 404
- ✅ **KEINE** SQL-Injection möglich
- ✅ Error-Message: "Invalid table name"

---

## Testing Tools

### cURL Beispiele

**Login:**
```bash
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"yourpassword"}' \
  -c cookies.txt -v
```

**POST mit CSRF-Token:**
```bash
curl -X POST http://localhost/api/articles \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b cookies.txt \
  -d '{"title":"Test","content":"Content"}'
```

**Logout:**
```bash
curl -X POST http://localhost/api/auth/logout \
  -b cookies.txt -v
```

### Postman Collection

Importiere diese Collection in Postman:

1. **Login** → Speichert CSRF-Token automatisch
2. **Create Article** → Nutzt gespeicherten CSRF-Token
3. **Logout** → Löscht Cookies

---

## Bekannte Einschränkungen

1. **CORS:** Aktuell sind nur spezifische Origins erlaubt (siehe `index.php`)
2. **Rate Limiting:** Basiert auf IP-Adresse (hinter Proxy: X-Forwarded-For)
3. **Session Storage:** File-based (für Production: Redis/Memcached empfohlen)

---

## Nächste Schritte

Nach erfolgreichem Testing:

1. ✅ **Request-Logging implementieren** (Monolog)
2. ✅ **API-Dokumentation erstellen** (OpenAPI/Swagger)
3. ✅ **Unit-Tests schreiben** (PHPUnit)
4. ✅ **Performance-Optimierung** (Caching, Query-Optimierung)

---

## Troubleshooting

### Problem: "Configuration Error"

**Lösung:** Prüfe `.env` Datei - alle Variablen müssen gesetzt sein:
- `DB_DSN`
- `DB_USERNAME`
- `DB_PASSWORD`
- `DB_PREFIX`
- `JWT_SECRET` (mindestens 32 Zeichen!)
- `DEBUG` (true/false)

### Problem: "Database Connection Failed"

**Lösung:** Prüfe Datenbank-Credentials in `.env`

### Problem: "Too Many Requests" beim ersten Login

**Lösung:** Rate-Limit-Tabelle leeren:
```sql
TRUNCATE TABLE mbc_rate_limit;
```

### Problem: CSRF-Token fehlt bei Login

**Lösung:** Sessions müssen aktiviert sein - prüfe Session-Cookie im Browser

---

**Viel Erfolg beim Testen! 🚀**
