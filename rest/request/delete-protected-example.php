<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Example: Protected DELETE Handler with Permission-Based Authorization
 *
 * This is a reference implementation showing how to integrate auth into DELETE requests.
 * You can copy this pattern to your actual delete.php or other handlers.
 *
 * Use cases:
 * 1. User can delete own articles (articles.delete.own)
 * 2. Moderators/Admins can delete any article (articles.delete.any)
 */
class requestDeleteProtected extends RequestBase {
    private array $request = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
        $this->log('requestDeleteProtected');
    }

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            // STEP 1: Require authentication
            $user = $this->requireAuth();
            $this->log(['Authenticated user', $user['id'], $user['username']]);

            // STEP 2: Check if ID is provided
            if (!array_key_exists('id', $this->request) || empty($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing id parameter']);
                return;
            }

            // Handle single or multiple IDs
            $ids = is_array($this->request['id'])
                ? array_map('intval', $this->request['id'])
                : [(int)$this->request['id']];

            $deletedCount = 0;
            $errors = [];

            // STEP 3: Process each ID with permission check
            foreach ($ids as $id) {
                // Fetch entity to check ownership
                $stmt = $this->pdo->prepare('SELECT * FROM `' . $this->getArea() . '` WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $entity = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$entity) {
                    $errors[] = ['id' => $id, 'error' => 'Not found'];
                    continue;
                }

                // Extract resource name from table (e.g., mbc_articles -> articles)
                $tableName = $this->getArea();
                $resource = str_replace(PREFIX . '_', '', $tableName);

                // STEP 4: Check permission with ownership
                if (!$this->can($user, 'delete', $resource, $entity)) {
                    $errors[] = ['id' => $id, 'error' => 'Forbidden'];
                    continue;
                }

                // STEP 5: Delete the entity
                $deleteStmt = $this->pdo->prepare('DELETE FROM `' . $this->getArea() . '` WHERE id = :id');
                $deleteStmt->execute(['id' => $id]);
                $deletedCount++;

                $this->log(['Deleted', $resource, $id]);
            }

            // STEP 6: Return result
            $response = [
                'success' => true,
                'deleted' => $deletedCount
            ];

            if (count($errors) > 0) {
                $response['errors'] = $errors;
                if ($deletedCount === 0) {
                    http_response_code(403);
                    $response['success'] = false;
                }
            }

            echo json_encode($response);
            $this->debugOutput();

        } catch (\Throwable $e) {
            $this->handleError('Error executing DELETE request', $e);
        }
    }
}

/**
 * ALTERNATIVE: Simple admin-only delete
 *
 * Use this pattern if you want to restrict deletion to admins only
 */
class requestDeleteAdminOnly extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            // Require admin or moderator role
            $user = $this->requireAnyRole(['admin', 'moderator']);

            if (array_key_exists('id', $this->request)) {
                $ids = is_array($this->request['id'])
                    ? array_map('intval', $this->request['id'])
                    : [(int)$this->request['id']];

                $sql = 'DELETE FROM `' . $this->getArea() . '` WHERE id IN (' . implode(',', $ids) . ')';
                $qRequest = $this->pdo->prepare($sql);
                $qRequest->execute();

                echo json_encode(['success' => true, 'deleted' => count($ids)]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing id parameter']);
            }

            $this->debugOutput();
        } catch (\Throwable $e) {
            $this->handleError('Error executing DELETE request', $e);
        }
    }
}

/**
 * ALTERNATIVE: Simple permission-based delete
 *
 * Use this if you just want to check for a specific permission without ownership
 */
class requestDeleteWithPermission extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            // Require permission to delete any article
            $user = $this->requirePermission('articles.delete.any');

            if (array_key_exists('id', $this->request)) {
                $ids = is_array($this->request['id'])
                    ? array_map('intval', $this->request['id'])
                    : [(int)$this->request['id']];

                $sql = 'DELETE FROM `' . $this->getArea() . '` WHERE id IN (' . implode(',', $ids) . ')';
                $qRequest = $this->pdo->prepare($sql);
                $qRequest->execute();

                echo json_encode(['success' => true, 'deleted' => count($ids)]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing id parameter']);
            }

            $this->debugOutput();
        } catch (\Throwable $e) {
            $this->handleError('Error executing DELETE request', $e);
        }
    }
}

?>
