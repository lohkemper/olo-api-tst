<?php
declare(strict_types=1);

include('base.php');
include(__DIR__ . '/../auth/csrf-helper.php');
include(__DIR__ . '/../auth/rate-limiter.php');
include('get.php');
include('get-auth.php');
include('get-users.php');
include('get-profiles.php');
include('get-roles.php');
include('get-permissions.php');
include('get-role-permissions.php');
include('get-navigations.php');
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
include('delete-navigation-roles.php');
include('delete-role-permissions.php');
include('delete-user-permissions.php');
include('delete-user-roles.php');
include('delete-articles.php');
include('get-warehouse-locations.php');
include('get-warehouse-items.php');
include('post-warehouse-locations.php');
include('post-warehouse-items.php');
include('put-warehouse-locations.php');
include('put-warehouse-items.php');
include('delete-warehouse-locations.php');
include('delete-warehouse-items.php');
include('get-email-folders.php');
include('get-email-messages.php');
include('post-email-messages.php');
include('put-email-messages.php');
include('delete-email-messages.php');
include('postfile.php');
include('put.php');
include('put-articles.php');
include('delete.php');

// IoT Request Handlers
include('post-iot-register.php');
include('post-iot-heartbeat.php');
include('post-iot-data-sync.php');
include('post-iot-pi-sync.php');
include('post-iot-rotate-key.php');
include('get-iot-devices.php');
include('get-iot-data.php');
include('get-iot-networks.php');
include('post-iot-networks.php');
include('put-iot-networks.php');
include('delete-iot-networks.php');
include('delete-iot-devices.php');

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

    // Handle auth routes: /auth/user, /auth/csrf-token, /auth/refresh
    if ($area === 'auth') {
      return $this->handleAuthRoutes();
    }

    // Handle users routes: /users, /users/{id}
    if ($area === 'users' && $this->methode === 'GET' && !isset($this->request['subroute'])) {
      $requestGetUsers = new requestGetUsers($this->pdo, '');
      $requestGetUsers->setRequest($this->request);
      $requestGetUsers->execute();
      return true;
    }

    // Handle profiles routes: /profiles/{username}
    if ($area === 'profiles' && $this->methode === 'GET') {
      $requestGetProfiles = new requestGetProfiles($this->pdo, '');
      $requestGetProfiles->setRequest($this->request);
      $requestGetProfiles->execute();
      return true;
    }

    // Handle roles routes: /roles, /roles/{id}
    if ($area === 'roles' && $this->methode === 'GET') {
      $requestGetRoles = new requestGetRoles($this->pdo, '');
      $requestGetRoles->setRequest($this->request);
      $requestGetRoles->execute();
      return true;
    }

    // Handle permissions routes: /permissions, /permissions/{id}
    if ($area === 'permissions' && $this->methode === 'GET') {
      $requestGetPermissions = new requestGetPermissions($this->pdo, '');
      $requestGetPermissions->setRequest($this->request);
      $requestGetPermissions->execute();
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

    // Handle articles routes: /articles, /articles/{id}
    if ($area === 'articles') {
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

    return false;
  }

  /**
   * Handle IoT-specific routes
   */
  private function handleIotRoutes(): bool {
    $subroute = $this->request['subroute'] ?? '';
    global $_PUT;

    $this->normalizeIotPathId($subroute);

    // POST /iot/devices/{id}/rotate-key — path parser leaves this as
    // ['area'=>iot, 'subroute'=>devices, '<id>'=>'rotate-key'], so detect
    // and dispatch before the generic GET /iot/devices handler below.
    if ($subroute === 'devices' && $this->methode === 'POST') {
        foreach ($this->request as $key => $value) {
            if (ctype_digit((string)$key) && $value === 'rotate-key') {
                $handler = new requestPostIotRotateKey($this->pdo, '');
                $handler->setDeviceId((int)$key);
                $handler->execute();
                return true;
            }
        }
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
