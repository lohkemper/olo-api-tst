<?php
declare(strict_types=1);

include('base.php');
include(__DIR__ . '/../auth/csrf-helper.php');
include(__DIR__ . '/../auth/rate-limiter.php');
// Google-OAuth-Helper (Grow-Kalender). Defensiv: fehlt die Datei nach Teil-Deploy,
// werden nur die gcal-Routen inaktiv, der Rest läuft weiter.
if (is_file(__DIR__ . '/../auth/google-oauth.php')) {
  include __DIR__ . '/../auth/google-oauth.php';
}
// Social-Login (Google/Facebook) — Helper + Handler defensiv geladen: fehlt eine
// Datei nach Teil-Deploy, sind nur die Social-Routen inaktiv.
foreach ([
  __DIR__ . '/../auth/social-login.php',
  __DIR__ . '/../auth/jwt-session.php',
  __DIR__ . '/get-social-url.php',
  __DIR__ . '/get-social-callback.php',
  __DIR__ . '/get-social-pending.php',
  __DIR__ . '/post-social-complete.php',
  __DIR__ . '/post-social-link.php',
] as $socialAuthFile) {
  if (is_file($socialAuthFile)) {
    include $socialAuthFile;
  }
}
// MFA/2FA (Plan: docs/planning/mfa-2fa.md) — Helper + Handler defensiv geladen:
// fehlt eine Datei nach Teil-Deploy, sind nur die MFA-Routen inaktiv.
foreach ([
  __DIR__ . '/../auth/crypto-helper.php',
  __DIR__ . '/../auth/totp.php',
  __DIR__ . '/../auth/mfa-helper.php',
  __DIR__ . '/../lib/Mailer.php',
  __DIR__ . '/../lib/MfaEmailCode.php',
  __DIR__ . '/../lib/WebAuthnHelper.php',
  __DIR__ . '/get-mfa-pending.php',
  __DIR__ . '/get-mfa-status.php',
  __DIR__ . '/post-mfa-verify.php',
  __DIR__ . '/post-mfa-totp-setup.php',
  __DIR__ . '/post-mfa-totp-confirm.php',
  __DIR__ . '/post-mfa-totp-disable.php',
  __DIR__ . '/post-mfa-backup-regenerate.php',
  __DIR__ . '/post-mfa-trusted-revoke.php',
  __DIR__ . '/post-mfa-email-send.php',
  __DIR__ . '/post-mfa-email-confirm.php',
  __DIR__ . '/post-webauthn-register-options.php',
  __DIR__ . '/post-webauthn-register.php',
  __DIR__ . '/post-webauthn-verify-options.php',
  __DIR__ . '/get-webauthn-credentials.php',
  __DIR__ . '/delete-webauthn-credentials.php',
] as $mfaFile) {
  if (is_file($mfaFile)) {
    include $mfaFile;
  }
}
include('get.php');
include('get-auth.php');
include('get-users.php');
include('get-profiles.php');
include('get-roles.php');
include('get-permissions.php');
include('post-permissions.php');
include('put-permissions.php');
include('delete-permissions.php');
include('get-role-permissions.php');
include('get-navigation-roles.php');
include('get-navigations.php');
// Neue Admin-CRUD-Handler (users/roles) werden LAZY in ihren Dispatch-Zweigen
// per include_once geladen — so kann ein etwaiger Fehler nicht die ganze API
// blockieren, sondern nur die jeweilige Route.
include('get-articles.php');
include('get-schema.php');
include('get-tables.php');
include('get-health.php');
include('get-docs.php');
include('get-openapi.php');
include('post.php');
include('post-login.php');
include('post-register.php');
include('post-logout.php');
include('post-auth.php');
include('post-user-roles.php');
include('post-user-permissions.php');
include('post-navigation-roles.php');
include('post-role-permissions.php');
include('post-articles.php');
include('post-article-favorite.php');
include('delete-navigation-roles.php');
include('delete-role-permissions.php');
include('delete-user-permissions.php');
include('delete-user-roles.php');
include('delete-articles.php');
include('get-warehouse-locations.php');
include('get-warehouse-items.php');
include('post-warehouse-locations.php');
include('post-warehouse-items.php');
include('post-warehouse-items-upload-image.php');
include('put-warehouse-locations.php');
include('put-warehouse-items.php');
include('delete-warehouse-locations.php');
include('delete-warehouse-items.php');
// Packliste + Verleihservice (gemeinsame Basis zuerst)
include('warehouse-packlist-base.php');
include('get-warehouse-packlist-templates.php');
include('post-warehouse-packlist-templates.php');
include('put-warehouse-packlist-templates.php');
include('delete-warehouse-packlist-templates.php');
include('get-warehouse-packlists.php');
include('post-warehouse-packlists.php');
include('put-warehouse-packlists.php');
include('delete-warehouse-packlists.php');
include('get-email-folders.php');
include('get-email-messages.php');
include('post-email-messages.php');
include('put-email-messages.php');
include('delete-email-messages.php');
include('postfile.php');
include('put.php');
include('put-articles.php');
include('delete.php');
include('post-log.php');

// IoT Request Handlers
include('post-iot-register.php');
include('post-iot-heartbeat.php');
include('post-iot-data-sync.php');
include('post-iot-pi-sync.php');
include('post-iot-rotate-key.php');
include('post-iot-device-approve.php');
include('post-iot-fleet-token.php');
include('get-iot-devices.php');
include('get-iot-device-keys.php');
include('delete-iot-devices.php');
include('get-iot-data.php');
include('get-iot-networks.php');
include('post-iot-networks.php');
include('put-iot-networks.php');
include('delete-iot-networks.php');

// Gym Request Handlers
include('get-gym-exercises.php');
include('post-gym-exercises.php');
include('get-gym-exercise-categories.php');
include('get-gym-workouts.php');
include('post-gym-workouts.php');
include('put-gym-workouts.php');
include('delete-gym-workouts.php');
include('post-gym-workout-sets.php');
include('put-gym-workout-sets.php');
include('delete-gym-workout-sets.php');
include('get-gym-plans.php');
include('post-gym-plans.php');
include('put-gym-plans.php');
include('delete-gym-plans.php');
include('post-gym-plan-start.php');
include('post-gym-plan-days.php');
include('put-gym-plan-days.php');
include('delete-gym-plan-days.php');
include('post-gym-plan-exercises.php');
include('put-gym-plan-exercises.php');
include('delete-gym-plan-exercises.php');
include('get-gym-personal-records.php');
include('get-gym-body-measurements.php');
include('post-gym-body-measurements.php');
include('put-gym-body-measurements.php');
include('delete-gym-body-measurements.php');
include('get-gym-analytics.php');
include('get-gym-cardio-sessions.php');
include('post-gym-cardio-sessions.php');
include('put-gym-cardio-sessions.php');
include('delete-gym-cardio-sessions.php');
include('get-gym-foods.php');
include('post-gym-foods.php');
include('put-gym-foods.php');
include('delete-gym-foods.php');
include('get-gym-nutrition-entries.php');
include('post-gym-nutrition-entries.php');
include('put-gym-nutrition-entries.php');
include('delete-gym-nutrition-entries.php');
include('put-gym-exercises.php');
include('delete-gym-exercises.php');
include('get-gym-warehouse-items.php');
include('get-gym-plan-assignments.php');
include('post-gym-plan-assignments.php');
include('put-gym-plan-assignments.php');
include('delete-gym-plan-assignments.php');
// Grow-Modul (Phasen 1/5/6/7). Defensiv geladen: fehlt eine Datei (z.B. nach
// unvollständigem Deploy), wird sie übersprungen statt den ganzen Request mit
// einer include-Warnung (→ "headers already sent") lahmzulegen. Nur /grow-Routen
// sind dann betroffen.
foreach ([
  'get-grow-cycles', 'post-grow-cycles', 'put-grow-cycles', 'delete-grow-cycles',
  'get-grow-plants', 'post-grow-plants', 'put-grow-plants', 'delete-grow-plants',
  'get-grow-preparations', 'post-grow-preparations', 'put-grow-preparations', 'delete-grow-preparations',
  'get-grow-feeding-schedule', 'post-grow-feeding-schedule', 'delete-grow-feeding-schedule',
  'get-grow-feeding-upcoming',
  'get-grow-plant-devices', 'post-grow-plant-devices', 'delete-grow-plant-devices',
  'get-grow-harvests', 'post-grow-harvests', 'delete-grow-harvests',
  'get-grow-settings', 'post-grow-settings',
  'get-grow-gcal-auth-url', 'get-grow-gcal-callback', 'post-grow-gcal-disconnect', 'post-grow-gcal-sync',
] as $growHandlerFile) {
  $growHandlerPath = __DIR__ . '/' . $growHandlerFile . '.php';
  if (is_file($growHandlerPath)) {
    include $growHandlerPath;
  }
}

class request {
  private array $logs = [];
  private array $allowedRequestMethod = ['POST','POSTFILE','GET','PUT','DELETE'];
  private string $methode = '';
  private array $request = [];
  private array $sqlTables = [];
  private PDO $pdo;

  protected function log(mixed $msg, string $type = 'info'): void {
    array_push($this->logs, [$type, $msg]);
  }

  public function setMethode(string $requestMethod): void {
    $this->log('request::setMethode: ' . $requestMethod);

    if (!in_array($requestMethod, $this->allowedRequestMethod)) {
      throw new InvalidArgumentException("Invalid request method: {$requestMethod}");
    }
    $this->methode = $requestMethod;
  }

  public function setPdo(PDO $pdo): void {
    $this->log('request::setPdo');
    $this->pdo = $pdo;
  }

  public function setQueryParams(array $queryParams): void {
    $this->log(['request::setQueryParams', $queryParams]);

    foreach ($queryParams as $key => $value) {
      // Validate parameter names to prevent injection
      if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
        $this->request[$key] = $value;
      }
    }
  }

  public function setPath(array $requestPath): void {
    $this->log(['request::setPath', $requestPath]);

    $key = '';
    foreach( $requestPath AS $i => $value ) {

      if( $key == '' && $value == 'file' && $this->methode === 'POST' ) {
        $this->setMethode('POSTFILE');
      }
      else if( $i == 0 ) {
        $key = 'area';
      }
      // Special handling for subroutes (e.g., /auth/user, /users/1/roles)
      else if( $i == 1 && !preg_match('/^[0-9]+(?:,[0-9]+)*$/Uis', $value) && !preg_match('/^[0-9]+-[0-9]+$/Uis', $value) ) {
        $key = 'subroute';
      }
      // Special handling for user_id/navigation_id in /users/{id}/roles or /navigation/{id}/roles pattern
      else if( $i == 1 && isset($requestPath[2]) && $requestPath[2] === 'roles' && preg_match('/^[0-9]+$/Uis', $value) ) {
        if ($requestPath[0] === 'users') {
          $this->request['user_id'] = (int)$value;
          // Check if there's a role_id in the path for DELETE: /users/{userId}/roles/{roleId}
          if (isset($requestPath[3]) && preg_match('/^[0-9]+$/Uis', $requestPath[3])) {
            $this->request['role_id'] = (int)$requestPath[3];
          }
        } elseif ($requestPath[0] === 'navigation') {
          $this->request['navigation_id'] = (int)$value;
        }
        // Set subroute to 'roles' for the specialized handler
        $this->request['subroute'] = 'roles';
        $key = '';
        // Skip index 2 since we already know it's 'roles'
        continue;
      }
      // Special handling for role-permissions DELETE: /role-permissions/{roleId}/{permissionId}
      else if( $requestPath[0] === 'role-permissions' && $i == 1 && isset($requestPath[2]) && preg_match('/^[0-9]+$/Uis', $value) && preg_match('/^[0-9]+$/Uis', $requestPath[2]) ) {
        $this->request['role_id'] = (int)$value;
        $this->request['permission_id'] = (int)$requestPath[2];
        break; // We've consumed all relevant path segments
      }
      // Special handling for user-permissions DELETE: /user-permissions/{userId}/{permissionId}
      else if( $requestPath[0] === 'user-permissions' && $i == 1 && isset($requestPath[2]) && preg_match('/^[0-9]+$/Uis', $value) && preg_match('/^[0-9]+$/Uis', $requestPath[2]) ) {
        $this->request['user_id'] = (int)$value;
        $this->request['permission_id'] = (int)$requestPath[2];
        break; // We've consumed all relevant path segments
      }
      // Special handling for email-messages actions: /email-messages/{id}/{action}
      else if( $requestPath[0] === 'email-messages' && $i == 1 && isset($requestPath[2]) && preg_match('/^[0-9]+$/Uis', $value) && !preg_match('/^[0-9]+$/Uis', $requestPath[2]) ) {
        $this->request['id'] = (int)$value;
        $this->request['subroute'] = $requestPath[2];
        break; // We've consumed all relevant path segments
      }
      // Special handling for warehouse-packlists actions:
      // /warehouse-packlists/{id}/{action}  (check-out|return|status|items)
      else if( $requestPath[0] === 'warehouse-packlists' && $i == 1 && isset($requestPath[2]) && preg_match('/^[0-9]+$/Uis', $value) && !preg_match('/^[0-9]+$/Uis', $requestPath[2]) ) {
        $this->request['id'] = (int)$value;
        $this->request['subroute'] = $requestPath[2];
        break; // We've consumed all relevant path segments
      }
      // Special handling for articles actions: /articles/{id}/{action} (favorite|comments)
      // Ohne dies landet die Sub-Action als 'groupby' und POST/DELETE fallen
      // destruktiv auf CREATE/DELETE des Artikels zurück.
      else if( $requestPath[0] === 'articles' && $i == 1 && isset($requestPath[2]) && preg_match('/^[0-9]+$/Uis', $value) && !preg_match('/^[0-9]+$/Uis', $requestPath[2]) ) {
        $this->request['id'] = (int)$value;
        $this->request['subroute'] = $requestPath[2];
        break; // We've consumed all relevant path segments
      }
      else if(($key !== '' or $i === 1)
        && preg_match('/^[0-9]+(?:,[0-9]+)*$/Uis', $value)
      ) {
        if( $i === 1 ) $key = 'id';
        $value = explode(',', $value);
      }
      else if(preg_match('/^[0-9]+-[0-9]+$/Uis', $value)) {
        $key = 'limit';
      }

      if($key === '') {
        $key = $value;
      }
      else {
        if( !is_array( $value ) && !preg_match( '/[0-9\w_-]+/Uis', $value ) ) {
          $value = '';
        }
        $this->request[ $key ] = $value;
        $key = '';
      }
    }

    if($key !== '') {
      $this->request['groupby'] = $key;
      $key = '';
    }
  }

  private function getArea(): string {
    global $prefix;
    return $prefix . '_' . $this->request['area'];
  }

  private function setColums(): void {
    $qRequestMeta = $this->pdo->prepare( "SHOW COLUMNS FROM `" . $this->getArea() ."`" );
    $qRequestMeta->execute();

    while ( $result = $qRequestMeta->fetch()) {
      $endresultFields2 = array();
      foreach( $result AS $key => $value ) {
        if( (int)$key !== $key ) {
          $endresultFields2[ $key ] = $value;

          if( $key == 'Field' ) {
            $fieldname = "`" . $this->area ."`." . $value ;

            if( substr($value, -5, 5) == '_file') {
              $this->files[] = $value;
              $fieldname = $value . ' AS ' . $value;
            }

            $this->select[] = $fieldname;
          }
        }
      }

      $this->endresultFields[ $endresultFields2['Field'] ] = $endresultFields2;
    }
  }

  public function execute(): void {
    $this->log('request::execute');

    try {
      $this->setSqlTables();

      // Zentrale CSRF-Durchsetzung für ALLE mutierenden Requests (vor jedem
      // Dispatch). Schließt den breiten CSRF-Gap der spezialisierten Handler.
      $this->enforceCsrf();

      // Check for specialized routes first (auth, roles, permissions)
      if ($this->handleSpecializedRoutes()) {
        return;
      }

      // Standard table-based routing (only if no subroute is set)
      if (array_key_exists('area', $this->request)
        && $this->request['area'] !== ''
        && array_key_exists($this->request['area'], $this->sqlTables)
        && !isset($this->request['subroute'])) {

        switch ($this->methode) {
          case 'GET':
            $requestGet = new requestGet($this->pdo, $this->getArea());
            $requestGet->setRequest($this->request);
            $requestGet->execute();
            break;

          case 'POST':
            global $_PUT;
            $requestPost = new requestPost($this->pdo, $this->getArea());
            $requestPost->setData($_PUT ?? []);
            $requestPost->execute();
            break;

          case 'POSTFILE':
            $requestPostfile = new requestPostfile($this->pdo, $this->getArea());
            $requestPostfile->setData($_FILES);
            $requestPostfile->execute();
            break;

          case 'PUT':
            $requestPut = new requestPut($this->pdo, $this->getArea());
            $requestPut->setRequest($this->request);
            $requestPut->execute();
            break;

          case 'DELETE':
            $requestDelete = new requestDelete($this->pdo, $this->getArea());
            $requestDelete->setRequest($this->request);
            $requestDelete->execute();
            break;

          default:
            throw new InvalidArgumentException("Unsupported method: {$this->methode}");
        }
      } else {
        http_response_code(404);
        echo json_encode(['error' => 'Invalid or missing area']);
      }
    } catch (\Throwable $e) {
      $this->log(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'error');
      http_response_code(500);
      echo json_encode(['error' => 'Internal server error', 'message' => DEBUG ? $e->getMessage() : null]);
    }

    $this->log(['request::execute', $this->request]);

    if (DEBUG) {
      echo '<br>REQUEST<br><pre>' . print_r($this->logs, true) . '</pre>';
    }
  }

  /**
   * Zentrale CSRF-Durchsetzung. Verlangt für jeden mutierenden Request
   * (POST/POSTFILE/PUT/PATCH/DELETE) ein gültiges `X-CSRF-Token` gegen die
   * Session — das Angular-Frontend sendet es per `csrfInterceptor` bei allen
   * Mutationen mit. Bei Fehlen/Ungültigkeit antwortet `CsrfHelper` mit 403
   * und beendet den Request.
   *
   * Exempt (kein Session-CSRF möglich/sinnvoll):
   *  - Auth-Lifecycle: `login`/`register` (es existiert noch kein Token),
   *    `logout`/`refresh`.
   *  - IoT-Geräte-Ingestion: Geräte authentifizieren per `X-Api-Key` bzw.
   *    `X-Provisioning-Token` (kein Session-Cookie). Admin-IoT-Routen
   *    (`devices`-Approve/Rotate, `fleet-tokens`, `networks`-CRUD) sind
   *    NICHT exempt → CSRF-pflichtig.
   *  - Offenes Client-Logging (`log`/`logs`, auch pre-auth).
   */
  private function enforceCsrf(): void {
    if (!in_array($this->methode, ['POST', 'POSTFILE', 'PUT', 'PATCH', 'DELETE'], true)) {
      return;
    }

    $area = $this->request['area'] ?? '';
    $subroute = $this->request['subroute'] ?? '';

    $exempt = [
      'auth' => ['login', 'register', 'logout', 'refresh'],
      'iot'  => ['register', 'heartbeat', 'data-sync', 'pi-sync'],
      'log'  => true,
      'logs' => true,
    ];

    if (isset($exempt[$area])
      && ($exempt[$area] === true || in_array($subroute, $exempt[$area], true))) {
      return;
    }

    CsrfHelper::requireValidToken();
  }

  /**
   * Handle specialized routes that don't follow the standard table pattern
   * Returns true if route was handled, false otherwise
   */
  private function handleSpecializedRoutes(): bool {
    $area = $this->request['area'] ?? '';

    // Handle API documentation route: /docs (no authentication required)
    if ($area === 'docs' && $this->methode === 'GET') {
      $requestGetDocs = new requestGetDocs($this->pdo, '');
      $requestGetDocs->execute();
      return true;
    }

    // Handle OpenAPI spec route: /openapi.yaml (no authentication required)
    if ($area === 'openapi.yaml' && $this->methode === 'GET') {
      $requestGetOpenapi = new requestGetOpenapi($this->pdo, '');
      $requestGetOpenapi->execute();
      return true;
    }

    // Handle health check route: /health (no authentication required)
    if ($area === 'health' && $this->methode === 'GET') {
      $requestGetHealth = new requestGetHealth($this->pdo, '');
      $requestGetHealth->execute();
      return true;
    }

    // Handle tables list route: /tables
    if ($area === 'tables' && $this->methode === 'GET') {
      $requestGetTables = new requestGetTables($this->pdo, '');
      $requestGetTables->setRequest($this->request);
      $requestGetTables->execute();
      return true;
    }

    // Handle schema routes FIRST (before specialized routes): /users/schema, /roles/schema, /permissions/schema, etc.
    // This must come before permissions/roles routes to avoid authentication requirements
    if (isset($this->request['subroute']) && $this->request['subroute'] === 'schema' && $this->methode === 'GET') {
      $requestGetSchema = new requestGetSchema($this->pdo, '');
      $requestGetSchema->setRequest($this->request);
      $requestGetSchema->execute();
      return true;
    }

    // Handle log ingestion route: POST /log (WebApi-Publisher aus @olo/core/logs).
    // Fängt zusätzlich /logs ab, damit die mbc_logs-Tabelle NICHT über das
    // generische Tabellen-Routing öffentlich auslesbar ist (GET /logs).
    if ($area === 'log' || $area === 'logs') {
      if ($this->methode === 'POST') {
        $requestPostLog = new requestPostLog($this->pdo, '');
        global $_PUT;
        $requestPostLog->setData($_PUT ?? []);
        $requestPostLog->execute();
        return true;
      }
      http_response_code(405);
      header('Allow: POST');
      echo json_encode(['error' => 'Method Not Allowed', 'message' => 'The log endpoint only accepts POST']);
      return true;
    }

    // Handle auth routes: /auth/user, /auth/csrf-token, /auth/refresh
    if ($area === 'auth') {
      return $this->handleAuthRoutes();
    }

    // Handle users routes: /users, /users/{id} (ohne subroute; /users/{id}/roles weiter unten)
    if ($area === 'users' && !isset($this->request['subroute'])) {
      if ($this->methode === 'GET') {
        $requestGetUsers = new requestGetUsers($this->pdo, '');
        $requestGetUsers->setRequest($this->request);
        $requestGetUsers->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        include_once(__DIR__ . '/post-users.php');
        $requestPostUsers = new requestPostUsers($this->pdo, '');
        global $_PUT; $requestPostUsers->setData($_PUT ?? []);
        $requestPostUsers->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        include_once(__DIR__ . '/put-users.php');
        $requestPutUsers = new requestPutUsers($this->pdo, '');
        $requestPutUsers->setRequest($this->request);
        global $_PUT; $requestPutUsers->setData($_PUT ?? []);
        $requestPutUsers->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        include_once(__DIR__ . '/delete-users.php');
        $requestDeleteUsers = new requestDeleteUsers($this->pdo, '');
        $requestDeleteUsers->setRequest($this->request);
        $requestDeleteUsers->execute();
        return true;
      }
    }

    // Handle profiles routes: /profiles/{username}
    if ($area === 'profiles' && $this->methode === 'GET') {
      $requestGetProfiles = new requestGetProfiles($this->pdo, '');
      $requestGetProfiles->setRequest($this->request);
      $requestGetProfiles->execute();
      return true;
    }

    // Handle roles routes: /roles, /roles/{id}
    if ($area === 'roles') {
      if ($this->methode === 'GET') {
        $requestGetRoles = new requestGetRoles($this->pdo, '');
        $requestGetRoles->setRequest($this->request);
        $requestGetRoles->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        include_once(__DIR__ . '/put-roles.php');
        $requestPutRoles = new requestPutRoles($this->pdo, '');
        $requestPutRoles->setRequest($this->request);
        global $_PUT; $requestPutRoles->setData($_PUT ?? []);
        $requestPutRoles->execute();
        return true;
      }
    }

    // Handle permissions routes: /permissions, /permissions/{id}
    if ($area === 'permissions') {
      if ($this->methode === 'GET') {
        $requestGetPermissions = new requestGetPermissions($this->pdo, '');
        $requestGetPermissions->setRequest($this->request);
        $requestGetPermissions->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $requestPostPermissions = new requestPostPermissions($this->pdo, '');
        $requestPostPermissions->setRequest($this->request);
        global $_PUT; $data = $_PUT;
        $requestPostPermissions->setData($data ?? []);
        $requestPostPermissions->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $requestPutPermissions = new requestPutPermissions($this->pdo, '');
        $requestPutPermissions->setRequest($this->request);
        global $_PUT; $data = $_PUT;
        $requestPutPermissions->setData($data ?? []);
        $requestPutPermissions->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $requestDeletePermissions = new requestDeletePermissions($this->pdo, '');
        $requestDeletePermissions->setRequest($this->request);
        $requestDeletePermissions->execute();
        return true;
      }
    }

    // Handle navigation-roles read: /navigation_roles?navigations_id= / ?role_id=
    if (($area === 'navigation_roles' || $area === 'navigation-roles') && $this->methode === 'GET') {
      $requestGetNavigationRoles = new requestGetNavigationRoles($this->pdo, '');
      $requestGetNavigationRoles->setRequest($this->request);
      $requestGetNavigationRoles->execute();
      return true;
    }

    // Handle navigations routes: /navigations, /navigations/{id}
    if ($area === 'navigations') {
      if ($this->methode === 'GET') {
        $requestGetNavigations = new requestGetNavigations($this->pdo, '');
        $requestGetNavigations->setRequest($this->request);
        $requestGetNavigations->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $requestPost = new requestPost($this->pdo, $this->getArea());
        global $_PUT; $data = $_PUT;
        $requestPost->setData($data ?? []);
        $requestPost->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $requestPut = new requestPut($this->pdo, $this->getArea());
        $requestPut->setRequest($this->request);
        $requestPut->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $requestDelete = new requestDelete($this->pdo, $this->getArea());
        $requestDelete->setRequest($this->request);
        $requestDelete->execute();
        return true;
      }
    }

    // Handle articles favorite toggle: /articles/{id}/favorite
    // MUSS vor dem generischen Articles-Block stehen, sonst fallen POST/DELETE
    // destruktiv auf CREATE/DELETE des Artikels zurück.
    if ($area === 'articles' && ($this->request['subroute'] ?? '') === 'favorite') {
      if ($this->methode === 'POST' || $this->methode === 'DELETE') {
        $requestArticleFavorite = new requestArticleFavorite($this->pdo, '');
        $requestArticleFavorite->setArticleId((int)$this->request['id']);
        $requestArticleFavorite->setFavorite($this->methode === 'POST');
        $requestArticleFavorite->execute();
        return true;
      }
      http_response_code(405);
      header('Allow: POST, DELETE');
      echo json_encode(['error' => 'Method Not Allowed', 'message' => 'favorite accepts POST or DELETE']);
      return true;
    }

    // Handle articles routes: /articles, /articles/{id}
    // Nur ohne Subroute: unbekannte Sub-Actions (z.B. /comments) dürfen NICHT
    // auf CREATE/DELETE des Artikels durchfallen.
    if ($area === 'articles' && !isset($this->request['subroute'])) {
      if ($this->methode === 'GET') {
        $requestGetArticles = new requestGetArticles($this->pdo, '');
        $requestGetArticles->setRequest($this->request);
        $requestGetArticles->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $requestPostArticles = new requestPostArticles($this->pdo, '');
        global $_PUT; $data = $_PUT;
        $requestPostArticles->setData($data ?? []);
        $requestPostArticles->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $requestPutArticles = new requestPutArticles($this->pdo, '');
        $requestPutArticles->setRequest($this->request);
        $requestPutArticles->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $requestDeleteArticles = new requestDeleteArticles($this->pdo, '');
        $requestDeleteArticles->setRequest($this->request);
        $requestDeleteArticles->execute();
        return true;
      }
    }

    // Handle user roles assignment: /users/{id}/roles
    if ($area === 'users' && isset($this->request['subroute']) && $this->request['subroute'] === 'roles') {
      if ($this->methode === 'POST') {
        $requestPostUserRoles = new requestPostUserRoles($this->pdo, '');
        $requestPostUserRoles->setRequest($this->request);
        global $_PUT; $data = $_PUT;
        $requestPostUserRoles->setData($data ?? []);
        $requestPostUserRoles->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        // Handle DELETE /users/{userId}/roles/{roleId}
        $requestDeleteUserRoles = new requestDeleteUserRoles($this->pdo, '');
        $requestDeleteUserRoles->setRequest($this->request);
        $requestDeleteUserRoles->execute();
        return true;
      }
    }

    // Handle navigation roles assignment: /navigation/{id}/roles
    if ($area === 'navigation' && isset($this->request['subroute']) && $this->request['subroute'] === 'roles') {
      if ($this->methode === 'POST') {
        $requestPostNavigationRoles = new requestPostNavigationRoles($this->pdo, '');
        $requestPostNavigationRoles->setRequest($this->request);
        global $_PUT; $data = $_PUT;
        $requestPostNavigationRoles->setData($data ?? []);
        $requestPostNavigationRoles->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $requestDeleteNavigationRoles = new requestDeleteNavigationRoles($this->pdo, '');
        $requestDeleteNavigationRoles->setRequest($this->request);
        $requestDeleteNavigationRoles->execute();
        return true;
      }
    }

    // Handle role-permissions routes: /role-permissions
    if ($area === 'role-permissions') {
      if ($this->methode === 'GET') {
        $requestGetRolePermissions = new requestGetRolePermissions($this->pdo, '');
        $requestGetRolePermissions->setRequest($this->request);
        $requestGetRolePermissions->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $requestPostRolePermissions = new requestPostRolePermissions($this->pdo, '');
        $requestPostRolePermissions->setRequest($this->request);
        global $_PUT; $data = $_PUT;
        $requestPostRolePermissions->setData($data ?? []);
        $requestPostRolePermissions->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        // Handle DELETE /role-permissions/{roleId}/{permissionId}
        $requestDeleteRolePermissions = new requestDeleteRolePermissions($this->pdo, '');
        $requestDeleteRolePermissions->setRequest($this->request);
        $requestDeleteRolePermissions->execute();
        return true;
      }
    }

    // Handle user-permissions routes: /user-permissions
    if ($area === 'user-permissions') {
      if ($this->methode === 'POST') {
        $requestPostUserPermissions = new requestPostUserPermissions($this->pdo, '');
        $requestPostUserPermissions->setRequest($this->request);
        global $_PUT; $data = $_PUT;
        $requestPostUserPermissions->setData($data ?? []);
        $requestPostUserPermissions->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        // Handle DELETE /user-permissions/{userId}/{permissionId}
        $requestDeleteUserPermissions = new requestDeleteUserPermissions($this->pdo, '');
        $requestDeleteUserPermissions->setRequest($this->request);
        $requestDeleteUserPermissions->execute();
        return true;
      }
    }

    // Handle warehouse-locations routes: /warehouse-locations
    if ($area === 'warehouse-locations') {
      if ($this->methode === 'GET') {
        $requestGetWarehouseLocations = new requestGetWarehouseLocations($this->pdo, '');
        $requestGetWarehouseLocations->setRequest($this->request);
        $requestGetWarehouseLocations->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $requestPostWarehouseLocations = new requestPostWarehouseLocations($this->pdo, '');
        global $_PUT; $data = $_PUT;
        $requestPostWarehouseLocations->setData($data ?? []);
        $requestPostWarehouseLocations->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $requestPutWarehouseLocations = new requestPutWarehouseLocations($this->pdo, '');
        $requestPutWarehouseLocations->setRequest($this->request);
        $requestPutWarehouseLocations->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $requestDeleteWarehouseLocations = new requestDeleteWarehouseLocations($this->pdo, '');
        $requestDeleteWarehouseLocations->setRequest($this->request);
        $requestDeleteWarehouseLocations->execute();
        return true;
      }
    }

    // Handle warehouse-items routes: /warehouse-items
    if ($area === 'warehouse-items') {
      // Subroute: POST /warehouse-items/upload-image (multipart file upload)
      if ($this->methode === 'POST'
        && isset($this->request['subroute'])
        && $this->request['subroute'] === 'upload-image'
      ) {
        $handler = new requestPostWarehouseItemsUploadImage($this->pdo, '');
        $handler->execute();
        return true;
      }

      if ($this->methode === 'GET') {
        $requestGetWarehouseItems = new requestGetWarehouseItems($this->pdo, '');
        $requestGetWarehouseItems->setRequest($this->request);
        $requestGetWarehouseItems->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $requestPostWarehouseItems = new requestPostWarehouseItems($this->pdo, '');
        global $_PUT; $data = $_PUT;
        $requestPostWarehouseItems->setData($data ?? []);
        $requestPostWarehouseItems->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $requestPutWarehouseItems = new requestPutWarehouseItems($this->pdo, '');
        $requestPutWarehouseItems->setRequest($this->request);
        $requestPutWarehouseItems->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $requestDeleteWarehouseItems = new requestDeleteWarehouseItems($this->pdo, '');
        $requestDeleteWarehouseItems->setRequest($this->request);
        $requestDeleteWarehouseItems->execute();
        return true;
      }
    }

    // Handle warehouse-packlist-templates routes: /warehouse-packlist-templates
    if ($area === 'warehouse-packlist-templates') {
      global $_PUT;
      if ($this->methode === 'GET') {
        $handler = new requestGetWarehousePacklistTemplates($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $handler = new requestPostWarehousePacklistTemplates($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $handler = new requestPutWarehousePacklistTemplates($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $handler = new requestDeleteWarehousePacklistTemplates($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
      }
    }

    // Handle warehouse-packlists routes: /warehouse-packlists (+ /{id}/{action})
    if ($area === 'warehouse-packlists') {
      global $_PUT;
      if ($this->methode === 'GET') {
        $handler = new requestGetWarehousePacklists($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $handler = new requestPostWarehousePacklists($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $handler = new requestPutWarehousePacklists($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $handler = new requestDeleteWarehousePacklists($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
      }
    }

    // Handle email-folders routes: /email-folders
    if ($area === 'email-folders') {
      if ($this->methode === 'GET') {
        $requestGetEmailFolders = new requestGetEmailFolders($this->pdo, '');
        $requestGetEmailFolders->setRequest($this->request);
        $requestGetEmailFolders->execute();
        return true;
      }
    }

    // Handle email-messages routes: /email-messages
    if ($area === 'email-messages') {
      if ($this->methode === 'GET') {
        $requestGetEmailMessages = new requestGetEmailMessages($this->pdo, '');
        $requestGetEmailMessages->setRequest($this->request);
        $requestGetEmailMessages->execute();
        return true;
      } elseif ($this->methode === 'POST') {
        $requestPostEmailMessages = new requestPostEmailMessages($this->pdo, '');
        $requestPostEmailMessages->setRequest($this->request);
        $requestPostEmailMessages->execute();
        return true;
      } elseif ($this->methode === 'PUT') {
        $requestPutEmailMessages = new requestPutEmailMessages($this->pdo, '');
        $requestPutEmailMessages->setRequest($this->request);
        $requestPutEmailMessages->execute();
        return true;
      } elseif ($this->methode === 'DELETE') {
        $requestDeleteEmailMessages = new requestDeleteEmailMessages($this->pdo, '');
        $requestDeleteEmailMessages->setRequest($this->request);
        $requestDeleteEmailMessages->execute();
        return true;
      }
    }

    // Handle IoT routes: /iot/{subroute}
    if ($area === 'iot') {
        return $this->handleIotRoutes();
    }

    // Handle Gym routes: /gym/{subroute}
    if ($area === 'gym') {
        return $this->handleGymRoutes();
    }

    // Handle Grow routes: /grow/{subroute}
    if ($area === 'grow') {
        return $this->handleGrowRoutes();
    }

    return false;
  }

  /**
   * Handle Grow-specific routes (Phase 1).
   *
   * Subroutes:
   *  - /grow/cycles               GET (list), POST (create)
   *  - /grow/cycles/{id}          GET (detail + plants), PUT (update), DELETE
   *  - /grow/plants               GET (list, ?cycle_id=), POST (create)
   *  - /grow/plants/{id}          GET (detail), PUT (update), DELETE
   */
  private function handleGrowRoutes(): bool {
    $subroute = $this->request['subroute'] ?? '';
    global $_PUT;

    $this->normalizeGrowPathId($subroute);

    // /grow/cycles ...
    if ($subroute === 'cycles') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGrowCycles($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGrowCycles($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGrowCycles($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGrowCycles($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /grow/plants ...
    if ($subroute === 'plants') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGrowPlants($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGrowPlants($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGrowPlants($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGrowPlants($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /grow/preparations ...
    if ($subroute === 'preparations') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGrowPreparations($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGrowPreparations($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGrowPreparations($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGrowPreparations($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /grow/feeding-schedule ...
    if ($subroute === 'feeding-schedule') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGrowFeedingSchedule($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGrowFeedingSchedule($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGrowFeedingSchedule($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /grow/feeding-upcoming  — kommende Dünge-Termine (Kalender-Vorschau)
    if ($subroute === 'feeding-upcoming' && $this->methode === 'GET' && class_exists('requestGetGrowFeedingUpcoming')) {
        $handler = new requestGetGrowFeedingUpcoming($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // /grow/settings ...
    if ($subroute === 'settings') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGrowSettings($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGrowSettings($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        }
    }

    // /grow/gcal-auth-url  — Google-OAuth Consent-URL
    if ($subroute === 'gcal-auth-url' && $this->methode === 'GET' && class_exists('requestGetGrowGcalAuthUrl')) {
        $handler = new requestGetGrowGcalAuthUrl($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // /grow/gcal-callback  — OAuth-Redirect-Ziel von Google
    if ($subroute === 'gcal-callback' && $this->methode === 'GET' && class_exists('requestGetGrowGcalCallback')) {
        $handler = new requestGetGrowGcalCallback($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // /grow/gcal-disconnect  — Verbindung trennen
    if ($subroute === 'gcal-disconnect' && $this->methode === 'POST' && class_exists('requestPostGrowGcalDisconnect')) {
        $handler = new requestPostGrowGcalDisconnect($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // /grow/gcal-sync  — Dünge-Termine in den Google-Kalender pushen
    if ($subroute === 'gcal-sync' && $this->methode === 'POST' && class_exists('requestPostGrowGcalSync')) {
        $handler = new requestPostGrowGcalSync($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // /grow/harvests ...
    if ($subroute === 'harvests') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGrowHarvests($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGrowHarvests($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGrowHarvests($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /grow/plant-devices ...
    if ($subroute === 'plant-devices') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGrowPlantDevices($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGrowPlantDevices($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGrowPlantDevices($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    return false;
  }

  /**
   * Wandelt eine trailing-ID (vom Path-Parser als 'groupby' abgelegt)
   * in 'id' um — analog normalizeGymPathId. Greift nur für Grow-Subroutes
   * mit Detail-Endpoint.
   */
  private function normalizeGrowPathId(string $subroute): void {
    if (!in_array($subroute, ['cycles', 'plants', 'preparations', 'feeding-schedule', 'plant-devices', 'harvests'], true)) {
        return;
    }
    if (isset($this->request['id'])) {
        return;
    }
    $groupby = $this->request['groupby'] ?? null;
    if ($groupby !== null && ctype_digit((string)$groupby)) {
        $this->request['id'] = (int)$groupby;
        unset($this->request['groupby']);
    }
  }

  /**
   * Handle Gym-specific routes (Phase 1).
   *
   * Subroutes:
   *  - /gym/exercises               GET (list), POST (create)
   *  - /gym/exercises/{id}          GET (detail)
   *  - /gym/exercise-categories     GET (list)
   *  - /gym/workouts                GET (list), POST (start)
   *  - /gym/workouts/{id}           GET (detail+sets), PUT (update/end), DELETE
   *  - /gym/workout-sets            POST (create)
   *  - /gym/workout-sets/{id}       PUT, DELETE
   */
  private function handleGymRoutes(): bool {
    $subroute = $this->request['subroute'] ?? '';
    global $_PUT;

    $this->normalizeGymPathId($subroute);

    // POST /gym/plans/{id}/start-day — sub-action ähnlich iot rotate-key:
    // path-parser liefert ['area'=>gym, 'subroute'=>plans, '<id>'=>'start-day']
    if ($subroute === 'plans' && $this->methode === 'POST') {
        foreach ($this->request as $key => $value) {
            if (ctype_digit((string)$key) && $value === 'start-day') {
                $handler = new requestPostGymPlanStart($this->pdo, '');
                $handler->setPlanId((int)$key);
                $handler->setData($_PUT ?? []);
                $handler->execute();
                return true;
            }
        }
    }

    // /gym/exercises ...
    if ($subroute === 'exercises') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymExercises($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymExercises($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymExercises($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymExercises($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/warehouse-items (read-only Proxy)
    if ($subroute === 'warehouse-items' && $this->methode === 'GET') {
        $handler = new requestGetGymWarehouseItems($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // /gym/plan-assignments ...
    if ($subroute === 'plan-assignments') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymPlanAssignments($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymPlanAssignments($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymPlanAssignments($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymPlanAssignments($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/exercise-categories
    if ($subroute === 'exercise-categories' && $this->methode === 'GET') {
        $handler = new requestGetGymExerciseCategories($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // /gym/workouts ...
    if ($subroute === 'workouts') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymWorkouts($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymWorkouts($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymWorkouts($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymWorkouts($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/workout-sets ...
    if ($subroute === 'workout-sets') {
        if ($this->methode === 'POST') {
            $handler = new requestPostGymWorkoutSets($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymWorkoutSets($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymWorkoutSets($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/plans ...
    if ($subroute === 'plans') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymPlans($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymPlans($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymPlans($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymPlans($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/plan-days ...
    if ($subroute === 'plan-days') {
        if ($this->methode === 'POST') {
            $handler = new requestPostGymPlanDays($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymPlanDays($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymPlanDays($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/plan-exercises ...
    if ($subroute === 'plan-exercises') {
        if ($this->methode === 'POST') {
            $handler = new requestPostGymPlanExercises($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymPlanExercises($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymPlanExercises($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/personal-records (nur GET)
    if ($subroute === 'personal-records' && $this->methode === 'GET') {
        $handler = new requestGetGymPersonalRecords($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // /gym/body-measurements ...
    if ($subroute === 'body-measurements') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymBodyMeasurements($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymBodyMeasurements($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymBodyMeasurements($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymBodyMeasurements($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/cardio-sessions ...
    if ($subroute === 'cardio-sessions') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymCardioSessions($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymCardioSessions($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymCardioSessions($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymCardioSessions($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/foods ...
    if ($subroute === 'foods') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymFoods($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymFoods($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymFoods($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymFoods($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/nutrition-entries ...
    if ($subroute === 'nutrition-entries') {
        if ($this->methode === 'GET') {
            $handler = new requestGetGymNutritionEntries($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'POST') {
            $handler = new requestPostGymNutritionEntries($this->pdo, '');
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'PUT') {
            $handler = new requestPutGymNutritionEntries($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->setData($_PUT ?? []);
            $handler->execute();
            return true;
        } elseif ($this->methode === 'DELETE') {
            $handler = new requestDeleteGymNutritionEntries($this->pdo, '');
            $handler->setRequest($this->request);
            $handler->execute();
            return true;
        }
    }

    // /gym/analytics/{report} — nur GET. Path-Parser legt {report} als
    // 'groupby' ab (analog wie zuvor bei numerischen IDs); wir lesen
    // direkt aus dem $request-Array.
    if ($subroute === 'analytics' && $this->methode === 'GET') {
        $report = (string)($this->request['groupby'] ?? '');
        if ($report === '') {
            // Fallback: erste nicht-numerische Path-Component nach 'analytics'
            foreach ($this->request as $key => $value) {
                if (!is_int($key) && !ctype_digit((string)$key)
                    && !in_array($key, ['area','subroute','range','exercise_id','from','to','limit','offset'], true)
                    && $value === '') {
                    $report = $key;
                    break;
                }
            }
        }

        $handler = new requestGetGymAnalytics($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->setReport($report);
        $handler->execute();
        return true;
    }

    return false;
  }

  /**
   * Analog zu normalizeIotPathId — der allgemeine Path-Parser kippt die
   * trailing numerische ID nach 'groupby'. Für Gym-Detail-Endpoints holen
   * wir sie nach 'id' zurück, damit die Handler einheitlich
   * $request['id'] lesen können.
   */
  private function normalizeGymPathId(string $subroute): void {
    if (!in_array($subroute, [
        'exercises', 'exercise-categories', 'workouts', 'workout-sets',
        'plans', 'plan-days', 'plan-exercises', 'personal-records',
        'body-measurements',
        'cardio-sessions', 'foods', 'nutrition-entries',
        'plan-assignments',
        // 'warehouse-items' nicht: kein Detail-Endpoint nötig
        // analytics nicht in dieser Liste — dort ist 'groupby' der Report-Name, nicht ID
    ], true)) {
        return;
    }
    if (isset($this->request['id'])) {
        return;
    }
    $groupby = $this->request['groupby'] ?? null;
    if ($groupby !== null && ctype_digit((string)$groupby)) {
        $this->request['id'] = (int)$groupby;
        unset($this->request['groupby']);
    }
  }

  /**
   * Handle IoT-specific routes
   */
  private function handleIotRoutes(): bool {
    $subroute = $this->request['subroute'] ?? '';
    global $_PUT;

    $this->normalizeIotPathId($subroute);

    // POST /iot/devices/{id}/{action} — path parser leaves this as
    // ['area'=>iot, 'subroute'=>devices, '<id>'=>'<action>'], so detect
    // and dispatch before the generic GET /iot/devices handler below.
    if ($subroute === 'devices' && $this->methode === 'POST') {
        foreach ($this->request as $key => $value) {
            if (!ctype_digit((string)$key)) {
                continue;
            }
            if ($value === 'rotate-key') {
                $handler = new requestPostIotRotateKey($this->pdo, '');
                $handler->setDeviceId((int)$key);
                $handler->execute();
                return true;
            }
            if ($value === 'approve' || $value === 'revoke') {
                $handler = new requestPostIotDeviceApprove($this->pdo, '');
                $handler->setDeviceId((int)$key);
                $handler->setAction((string)$value);
                $handler->execute();
                return true;
            }
        }
    }

    // POST /iot/fleet-tokens
    if ($subroute === 'fleet-tokens' && $this->methode === 'POST') {
        $handler = new requestPostIotFleetToken($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // POST /iot/register
    if ($subroute === 'register' && $this->methode === 'POST') {
        $handler = new requestPostIotRegister($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // POST /iot/heartbeat
    if ($subroute === 'heartbeat' && $this->methode === 'POST') {
        $handler = new requestPostIotHeartbeat($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // POST /iot/data-sync
    if ($subroute === 'data-sync' && $this->methode === 'POST') {
        $handler = new requestPostIotDataSync($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // POST /iot/pi-sync
    if ($subroute === 'pi-sync' && $this->methode === 'POST') {
        $handler = new requestPostIotPiSync($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // GET /iot/device-keys — deviceKey→chipId für die Pi-Zentrale (Geräte-Key,
    // typ=pi). Muss vor dem generischen devices-Handler stehen.
    if ($subroute === 'device-keys' && $this->methode === 'GET') {
        $handler = new requestGetIotDeviceKeys($this->pdo, '');
        $handler->execute();
        return true;
    }

    // GET /iot/devices, GET /iot/devices/{id}
    if ($subroute === 'devices' && $this->methode === 'GET') {
        $handler = new requestGetIotDevices($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // DELETE /iot/devices/{id}
    if ($subroute === 'devices' && $this->methode === 'DELETE') {
        $handler = new requestDeleteIotDevices($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // GET /iot/data/{device_id}
    if ($subroute === 'data' && $this->methode === 'GET') {
        $handler = new requestGetIotData($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // GET /iot/networks, GET /iot/networks/{id}
    if ($subroute === 'networks' && $this->methode === 'GET') {
        $handler = new requestGetIotNetworks($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    // POST /iot/networks
    if ($subroute === 'networks' && $this->methode === 'POST') {
        $handler = new requestPostIotNetworks($this->pdo, '');
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // PUT /iot/networks/{id}
    if ($subroute === 'networks' && $this->methode === 'PUT') {
        $handler = new requestPutIotNetworks($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->setData($_PUT ?? []);
        $handler->execute();
        return true;
    }

    // DELETE /iot/networks/{id}
    if ($subroute === 'networks' && $this->methode === 'DELETE') {
        $handler = new requestDeleteIotNetworks($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
    }

    return false;
  }

  /**
   * Der allgemeine Parser kippt die trailing numerische ID im Pfad
   * /iot/{subroute}/{id} nach 'groupby'. Für die IoT-Detail-Endpoints
   * holen wir sie hier zurück nach 'id', ohne andere Routen anzufassen.
   */
  private function normalizeIotPathId(string $subroute): void {
    if (!in_array($subroute, ['devices', 'data', 'networks'], true)) {
        return;
    }
    if (isset($this->request['id'])) {
        return;
    }
    $groupby = $this->request['groupby'] ?? null;
    if ($groupby !== null && ctype_digit((string)$groupby)) {
        $this->request['id'] = (int)$groupby;
        unset($this->request['groupby']);
    }
  }

  /**
   * Handle auth-specific routes
   */
  private function handleAuthRoutes(): bool {
    $subroute = $this->request['subroute'] ?? '';

    // GET routes
    if ($this->methode === 'GET' && in_array($subroute, ['user', 'csrf-token'])) {
      $requestGetAuth = new requestGetAuth($this->pdo, '');
      $requestGetAuth->setRequest($this->request);
      $requestGetAuth->execute();
      return true;
    }

    // Social-Login (Google/Facebook) — Klassen defensiv geprüft (Teil-Deploy).
    if ($this->methode === 'GET' && $subroute === 'social-url' && class_exists('requestGetSocialUrl')) {
      $handler = new requestGetSocialUrl($this->pdo, '');
      $handler->setRequest($this->request);
      $handler->execute();
      return true;
    }

    if ($this->methode === 'GET' && in_array($subroute, ['google-callback', 'facebook-callback'])
        && class_exists('requestGetSocialCallback')) {
      $handler = new requestGetSocialCallback($this->pdo, '');
      $handler->setRequest($this->request);
      $handler->setProvider($subroute === 'google-callback' ? 'google' : 'facebook');
      $handler->execute();
      return true;
    }

    if ($this->methode === 'GET' && $subroute === 'social-pending' && class_exists('requestGetSocialPending')) {
      $handler = new requestGetSocialPending($this->pdo, '');
      $handler->setRequest($this->request);
      $handler->execute();
      return true;
    }

    // MFA/2FA-Routen (Plan: docs/planning/mfa-2fa.md), Klassen defensiv geprüft.
    if ($this->methode === 'GET') {
      $mfaGetHandlers = [
        'mfa-pending' => 'requestGetMfaPending',
        'mfa-status' => 'requestGetMfaStatus',
        'webauthn-credentials' => 'requestGetWebauthnCredentials',
      ];
      if (isset($mfaGetHandlers[$subroute]) && class_exists($mfaGetHandlers[$subroute])) {
        $handler = new $mfaGetHandlers[$subroute]($this->pdo, '');
        $handler->setRequest($this->request);
        $handler->execute();
        return true;
      }
    }

    if ($this->methode === 'DELETE' && $subroute === 'webauthn-credentials'
        && class_exists('requestDeleteWebauthnCredentials')) {
      $handler = new requestDeleteWebauthnCredentials($this->pdo, '');
      $handler->setRequest($this->request);
      $handler->execute();
      return true;
    }

    // POST routes
    if ($this->methode === 'POST') {
      switch ($subroute) {
        case 'login':
          $requestPostLogin = new requestPostLogin($this->pdo, '');
          global $_PUT; $data = $_PUT;
          $requestPostLogin->setData($data ?? []);
          $requestPostLogin->execute();
          return true;

        case 'register':
          $requestPostRegister = new requestPostRegister($this->pdo, '');
          global $_PUT; $data = $_PUT;
          $requestPostRegister->setData($data ?? []);
          $requestPostRegister->execute();
          return true;

        case 'logout':
          $requestPostLogout = new requestPostLogout($this->pdo, '');
          $requestPostLogout->execute();
          return true;

        case 'refresh':
          $requestPostAuth = new requestPostAuth($this->pdo, '');
          $requestPostAuth->setRequest($this->request);
          global $_PUT; $data = $_PUT;
          $requestPostAuth->setData($data ?? []);
          $requestPostAuth->execute();
          return true;

        case 'settings':
          $requestPostAuth = new requestPostAuth($this->pdo, '');
          $requestPostAuth->setRequest($this->request);
          global $_PUT; $data = $_PUT;
          $requestPostAuth->setData($data ?? []);
          $requestPostAuth->execute();
          return true;

        // Social-Login: CSRF-pflichtig (bewusst NICHT exempt — die PHP-Session
        // existiert beim Rücksprung bereits, Token kommt aus GET /auth/social-pending).
        case 'social-complete':
          if (!class_exists('requestPostSocialComplete')) break;
          $handler = new requestPostSocialComplete($this->pdo, '');
          global $_PUT; $data = $_PUT;
          $handler->setData($data ?? []);
          $handler->execute();
          return true;

        case 'social-link':
          if (!class_exists('requestPostSocialLink')) break;
          $handler = new requestPostSocialLink($this->pdo, '');
          global $_PUT; $data = $_PUT;
          $handler->setData($data ?? []);
          $handler->execute();
          return true;
      }

      // MFA/2FA-POSTs (alle CSRF-pflichtig — bewusst NICHT in der Exempt-Liste;
      // Token kommt aus der Login-Antwort bzw. GET /auth/mfa-pending).
      $mfaPostHandlers = [
        'mfa-verify' => 'requestPostMfaVerify',
        'mfa-totp-setup' => 'requestPostMfaTotpSetup',
        'mfa-totp-confirm' => 'requestPostMfaTotpConfirm',
        'mfa-totp-disable' => 'requestPostMfaTotpDisable',
        'mfa-backup-regenerate' => 'requestPostMfaBackupRegenerate',
        'mfa-trusted-revoke' => 'requestPostMfaTrustedRevoke',
        'mfa-email-send' => 'requestPostMfaEmailSend',
        'mfa-email-confirm' => 'requestPostMfaEmailConfirm',
        'webauthn-register-options' => 'requestPostWebauthnRegisterOptions',
        'webauthn-register' => 'requestPostWebauthnRegister',
        'webauthn-verify-options' => 'requestPostWebauthnVerifyOptions',
      ];
      if (isset($mfaPostHandlers[$subroute]) && class_exists($mfaPostHandlers[$subroute])) {
        $handler = new $mfaPostHandlers[$subroute]($this->pdo, '');
        global $_PUT; $data = $_PUT;
        $handler->setData($data ?? []);
        $handler->execute();
        return true;
      }
    }

    return false;
  }

  private function setSqlTables(): void {
    // Use helper function from cfg.php to avoid code duplication
    global $prefix;
    $this->sqlTables = getSqlTables($this->pdo, $prefix);
  }
}
