<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for email-folders endpoint
 * Handles:
 * - GET /api/email-folders (all folders for user with counts)
 * - GET /api/email-folders/{id} (specific folder)
 *
 * @version 1.0.0
 */
class requestGetEmailFolders extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetEmailFolders::execute');
            $this->log(['requestGetEmailFolders::request', $this->request]);

            // Requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // Route to appropriate handler
            if (isset($this->request['id'])) {
                $folderId = is_array($this->request['id'])
                    ? (int)$this->request['id'][0]
                    : (int)$this->request['id'];

                $this->handleGetFolder($userId, $folderId);
            } else {
                $this->handleGetFolders($userId);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET email-folders request', $e);
        }
    }

    /**
     * GET /api/email-folders - All folders for user with counts
     */
    private function handleGetFolders(int $userId): void {
        // Use view for optimized query with counts
        $sql = "
            SELECT
                folders_id,
                name,
                display_name,
                imap_path,
                type,
                parent_id,
                user_id,
                created_at,
                unread_count,
                total_count
            FROM vw_email_folders_with_counts
            WHERE user_id = ?
            ORDER BY
                CASE type
                    WHEN 'inbox' THEN 1
                    WHEN 'sent' THEN 2
                    WHEN 'drafts' THEN 3
                    WHEN 'archive' THEN 4
                    WHEN 'spam' THEN 5
                    WHEN 'trash' THEN 6
                    ELSE 7
                END,
                name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert numeric fields to proper types
        $folders = array_map(function($folder) {
            return [
                'folders_id' => (int)$folder['folders_id'],
                'name' => $folder['name'],
                'display_name' => $folder['display_name'],
                'imap_path' => $folder['imap_path'],
                'type' => $folder['type'],
                'parent_id' => $folder['parent_id'] ? (int)$folder['parent_id'] : null,
                'user_id' => (int)$folder['user_id'],
                'created_at' => $folder['created_at'],
                'unread_count' => (int)($folder['unread_count'] ?? 0),
                'total_count' => (int)($folder['total_count'] ?? 0),
            ];
        }, $folders);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($folders);
    }

    /**
     * GET /api/email-folders/{id} - Single folder
     */
    private function handleGetFolder(int $userId, int $folderId): void {
        $sql = "
            SELECT
                folders_id,
                name,
                display_name,
                imap_path,
                type,
                parent_id,
                user_id,
                created_at,
                unread_count,
                total_count
            FROM vw_email_folders_with_counts
            WHERE folders_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$folderId, $userId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            http_response_code(404);
            echo json_encode(['error' => 'Folder not found']);
            return;
        }

        // Convert numeric fields
        $folder = [
            'folders_id' => (int)$folder['folders_id'],
            'name' => $folder['name'],
            'display_name' => $folder['display_name'],
            'imap_path' => $folder['imap_path'],
            'type' => $folder['type'],
            'parent_id' => $folder['parent_id'] ? (int)$folder['parent_id'] : null,
            'user_id' => (int)$folder['user_id'],
            'created_at' => $folder['created_at'],
            'unread_count' => (int)($folder['unread_count'] ?? 0),
            'total_count' => (int)($folder['total_count'] ?? 0),
        ];

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$folder]);
    }
}
