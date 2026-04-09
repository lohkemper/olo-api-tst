<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT handler for email-messages endpoint
 * Handles:
 * - PUT /api/email-messages/{id}/read (mark as read)
 * - PUT /api/email-messages/{id}/unread (mark as unread)
 * - PUT /api/email-messages/{id}/flag (toggle flag status)
 * - PUT /api/email-messages/{id}/tags (add/remove tags)
 *
 * @version 1.0.0
 */
class requestPutEmailMessages extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            global $_PUT;
            $this->log('requestPutEmailMessages::execute');
            $this->log(['requestPutEmailMessages::request', $this->request]);
            $this->log(['requestPutEmailMessages::_PUT', $_PUT]);

            // Requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // ID is required
            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Message ID is required']);
                return;
            }

            $messageId = is_array($this->request['id'])
                ? (int)$this->request['id'][0]
                : (int)$this->request['id'];

            // Check subroute
            if (isset($this->request['subroute'])) {
                $subroute = $this->request['subroute'];

                if ($subroute === 'read') {
                    $this->handleMarkAsRead($userId, $messageId);
                } elseif ($subroute === 'unread') {
                    $this->handleMarkAsUnread($userId, $messageId);
                } elseif ($subroute === 'flag') {
                    $this->handleToggleFlag($userId, $messageId);
                } elseif ($subroute === 'tags') {
                    $this->handleManageTags($userId, $messageId);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Unknown subroute']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Subroute is required']);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error updating email message', $e);
        }
    }

    /**
     * PUT /api/email-messages/{id}/read - Mark as read
     */
    private function handleMarkAsRead(int $userId, int $messageId): void {
        // Verify message exists and belongs to user
        $message = $this->getMessageOrFail($userId, $messageId);

        // Update read status in database
        $sql = "UPDATE " . PREFIX . "_email_messages SET is_read = TRUE WHERE emails_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId]);

        // Set \Seen flag on IMAP server
        $this->syncFlagToIMAP($message, '\\Seen', true);

        // Fetch updated message
        $updatedMessage = $this->getMessageOrFail($userId, $messageId);
        $enrichedMessage = $this->enrichMessageWithTags($updatedMessage);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['data' => $enrichedMessage]);
    }

    /**
     * PUT /api/email-messages/{id}/unread - Mark as unread
     */
    private function handleMarkAsUnread(int $userId, int $messageId): void {
        // Verify message exists and belongs to user
        $message = $this->getMessageOrFail($userId, $messageId);

        // Update read status in database
        $sql = "UPDATE " . PREFIX . "_email_messages SET is_read = FALSE WHERE emails_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId]);

        // Remove \Seen flag from IMAP server
        $this->syncFlagToIMAP($message, '\\Seen', false);

        // Fetch updated message
        $updatedMessage = $this->getMessageOrFail($userId, $messageId);
        $enrichedMessage = $this->enrichMessageWithTags($updatedMessage);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['data' => $enrichedMessage]);
    }

    /**
     * PUT /api/email-messages/{id}/flag - Toggle flag status
     */
    private function handleToggleFlag(int $userId, int $messageId): void {
        // Verify message exists and belongs to user
        $message = $this->getMessageOrFail($userId, $messageId);

        // Toggle flag status
        $newFlagStatus = !$message['is_flagged'];
        $sql = "UPDATE " . PREFIX . "_email_messages SET is_flagged = ? WHERE emails_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$newFlagStatus ? 1 : 0, $messageId]);

        // Set/remove \Flagged flag on IMAP server
        $this->syncFlagToIMAP($message, '\\Flagged', $newFlagStatus);

        // Fetch updated message
        $updatedMessage = $this->getMessageOrFail($userId, $messageId);
        $enrichedMessage = $this->enrichMessageWithTags($updatedMessage);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['data' => $enrichedMessage]);
    }

    /**
     * PUT /api/email-messages/{id}/tags - Add or remove tags
     *
     * Body:
     * {
     *   "action": "add",
     *   "tag": "Important"
     * }
     * or
     * {
     *   "action": "remove",
     *   "tag_id": 5
     * }
     */
    private function handleManageTags(int $userId, int $messageId): void {
        global $_PUT;

        // Verify message exists and belongs to user
        $message = $this->getMessageOrFail($userId, $messageId);

        if (!isset($_PUT['action'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Action is required (add or remove)']);
            return;
        }

        $action = $_PUT['action'];

        if ($action === 'add') {
            if (!isset($_PUT['tag'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Tag name is required for add action']);
                return;
            }

            $tagName = $_PUT['tag'];
            $tagId = $this->findOrCreateTag($tagName);

            // Add tag
            $sql = "INSERT IGNORE INTO " . PREFIX . "_email_tags (email_id, tag_id) VALUES (?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$messageId, $tagId]);

        } elseif ($action === 'remove') {
            if (!isset($_PUT['tag_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Tag ID is required for remove action']);
                return;
            }

            $tagId = (int)$_PUT['tag_id'];

            // Remove tag
            $sql = "DELETE FROM " . PREFIX . "_email_tags WHERE email_id = ? AND tag_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$messageId, $tagId]);

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "add" or "remove"']);
            return;
        }

        // Fetch updated message
        $updatedMessage = $this->getMessageOrFail($userId, $messageId);
        $enrichedMessage = $this->enrichMessageWithTags($updatedMessage);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['data' => $enrichedMessage]);
    }

    /**
     * Get message or fail with 404
     */
    private function getMessageOrFail(int $userId, int $messageId): array {
        $sql = "
            SELECT *
            FROM " . PREFIX . "_email_messages
            WHERE emails_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId, $userId]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$message) {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found']);
            exit;
        }

        return $message;
    }

    /**
     * Find tag by name or create if not exists
     */
    private function findOrCreateTag(string $tagName): int {
        $sql = "SELECT tags_id FROM " . PREFIX . "_tags WHERE name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName]);
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tag) {
            return (int)$tag['tags_id'];
        }

        // Create slug from name
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $tagName));

        $sql = "INSERT INTO " . PREFIX . "_tags (name, slug) VALUES (?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName, $slug]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Enriches message with tags
     */
    private function enrichMessageWithTags(array $message): array {
        $messageId = (int)$message['emails_id'];

        $sql = "
            SELECT t.tags_id AS id, t.name
            FROM " . PREFIX . "_tags t
            INNER JOIN " . PREFIX . "_email_tags et ON t.tags_id = et.tag_id
            WHERE et.email_id = ?
            ORDER BY t.name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert types
        $message['emails_id'] = (int)$message['emails_id'];
        $message['uid'] = (int)$message['uid'];
        $message['folder_id'] = (int)$message['folder_id'];
        $message['is_read'] = (bool)$message['is_read'];
        $message['is_flagged'] = (bool)$message['is_flagged'];
        $message['has_attachments'] = (bool)$message['has_attachments'];
        $message['size_bytes'] = (int)$message['size_bytes'];
        $message['user_id'] = (int)$message['user_id'];

        // Parse JSON fields
        $message['to'] = json_decode($message['to_json'] ?? '[]', true);
        $message['cc'] = $message['cc_json'] ? json_decode($message['cc_json'], true) : null;
        $message['bcc'] = $message['bcc_json'] ? json_decode($message['bcc_json'], true) : null;

        unset($message['to_json'], $message['cc_json'], $message['bcc_json']);

        // Build from object
        $message['from'] = [
            'email' => $message['from_email'],
            'name' => $message['from_name'],
        ];
        unset($message['from_email'], $message['from_name']);

        $message['tags'] = $tags;
        return $message;
    }

    /**
     * Sync flag changes to IMAP server
     *
     * @param array $message Message data with folder_id and uid
     * @param string $flag IMAP flag name (e.g., '\Seen', '\Flagged')
     * @param bool $set True to set flag, false to clear it
     */
    private function syncFlagToIMAP(array $message, string $flag, bool $set): void {
        try {
            // Get IMAP configuration
            $imapHost = $_ENV['IMAP_HOST'] ?? null;
            $imapPort = $_ENV['IMAP_PORT'] ?? '993';
            $imapUser = $_ENV['IMAP_USER'] ?? null;
            $imapPassword = $_ENV['IMAP_PASSWORD'] ?? null;
            $imapEncryption = $_ENV['IMAP_ENCRYPTION'] ?? 'ssl';

            // Skip if IMAP is not configured
            if (!$imapHost || !$imapUser || !$imapPassword) {
                $this->log('IMAP not configured, skipping flag sync');
                return;
            }

            // Get folder path for this message
            $sql = "SELECT imap_path FROM " . PREFIX . "_email_folders WHERE folders_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$message['folder_id']]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$folder) {
                $this->log('Folder not found for message, skipping flag sync');
                return;
            }

            // Load IMAP Helper
            require_once __DIR__ . '/../lib/ImapHelper.php';

            // Connect and set flag
            $imap = new ImapHelper(
                $imapHost,
                (int)$imapPort,
                $imapUser,
                $imapPassword,
                $imapEncryption
            );

            $imap->connect();
            $success = $imap->setFlag($folder['imap_path'], (int)$message['uid'], $flag, $set);
            $imap->disconnect();

            if ($success) {
                $action = $set ? 'set' : 'cleared';
                $this->log("IMAP flag $flag $action for UID {$message['uid']}");
            } else {
                $this->log("Failed to sync flag $flag to IMAP for UID {$message['uid']}");
            }

        } catch (\Throwable $e) {
            // Log error but don't fail the request
            $this->log('IMAP flag sync error: ' . $e->getMessage());
        }
    }
}
