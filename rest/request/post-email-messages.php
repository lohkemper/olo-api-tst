<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST handler for email-messages endpoint
 * Handles:
 * - POST /api/email-messages/sync (trigger IMAP sync)
 *
 * Phase 2 (Future):
 * - POST /api/email-messages (send new email via SMTP)
 *
 * @version 1.0.0
 */
class requestPostEmailMessages extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            global $_POST;
            $this->log('requestPostEmailMessages::execute');
            $this->log(['requestPostEmailMessages::request', $this->request]);
            $this->log(['requestPostEmailMessages::_POST', $_POST]);

            // Requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // Check subroute
            if (isset($this->request['subroute'])) {
                $subroute = $this->request['subroute'];

                if ($subroute === 'sync') {
                    $this->handleSyncEmails($userId);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Unknown subroute']);
                }
            } else {
                // Phase 1: Send email not implemented yet
                http_response_code(501);
                echo json_encode([
                    'error' => 'Not Implemented',
                    'message' => 'Email sending via SMTP will be available in Phase 2'
                ]);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing POST email-messages request', $e);
        }
    }

    /**
     * POST /api/email-messages/sync - Trigger IMAP sync
     *
     * This endpoint triggers synchronization of emails from the IMAP server.
     * Rate limiting: Maximum 1 sync per minute per user.
     *
     * Response:
     * {
     *   "synced": true,
     *   "new_emails": 15,
     *   "updated_emails": 3,
     *   "timestamp": "2025-12-07T10:30:00Z"
     * }
     */
    private function handleSyncEmails(int $userId): void {
        // Check rate limiting (max 1 sync per minute)
        $sql = "
            SELECT MAX(created_at) AS last_sync
            FROM " . PREFIX . "_email_messages
            WHERE user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['last_sync']) {
            $lastSync = new DateTime($result['last_sync']);
            $now = new DateTime();
            $diff = $now->getTimestamp() - $lastSync->getTimestamp();

            if ($diff < 60) {
                http_response_code(429); // Too Many Requests
                echo json_encode([
                    'error' => 'Rate limit exceeded',
                    'message' => 'Please wait ' . (60 - $diff) . ' seconds before syncing again',
                    'retry_after' => 60 - $diff
                ]);
                return;
            }
        }

        // Get IMAP configuration from environment
        $imapHost = $_ENV['IMAP_HOST'] ?? null;
        $imapPort = $_ENV['IMAP_PORT'] ?? '993';
        $imapUser = $_ENV['IMAP_USER'] ?? null;
        $imapPassword = $_ENV['IMAP_PASSWORD'] ?? null;
        $imapEncryption = $_ENV['IMAP_ENCRYPTION'] ?? 'ssl';

        if (!$imapHost || !$imapUser || !$imapPassword) {
            http_response_code(500);
            echo json_encode([
                'error' => 'IMAP configuration missing',
                'message' => 'IMAP credentials are not configured in environment variables'
            ]);
            return;
        }

        // Load IMAP Helper
        require_once __DIR__ . '/../lib/ImapHelper.php';

        try {
            // Initialize IMAP connection
            $imap = new ImapHelper(
                $imapHost,
                (int)$imapPort,
                $imapUser,
                $imapPassword,
                $imapEncryption
            );

            $imap->connect();

            // Sync folders
            $this->log('Fetching IMAP folders...');
            $folders = $imap->getFolders();
            $this->syncFolders($userId, $folders);

            // Sync emails for each folder
            $newEmailsCount = 0;
            $updatedEmailsCount = 0;

            foreach ($folders as $folderInfo) {
                $syncResult = $this->syncFolder($userId, $folderInfo, $imap);
                $newEmailsCount += $syncResult['new'];
                $updatedEmailsCount += $syncResult['updated'];
            }

            $imap->disconnect();

            // Success response
            $response = [
                'synced' => true,
                'new_emails' => $newEmailsCount,
                'updated_emails' => $updatedEmailsCount,
                'folders_synced' => count($folders),
                'timestamp' => (new DateTime())->format('c'),
            ];

            // Include logs in debug mode
            if (DEBUG) {
                $response['debug'] = [
                    'imap_logs' => $imap->getLogs(),
                ];
            }

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($response);

        } catch (Exception $e) {
            $this->handleError('IMAP sync failed', $e);
        }
    }

    /**
     * Sync all folders to database
     *
     * @param int $userId User ID
     * @param array $folders Array of folder info from IMAP
     */
    private function syncFolders(int $userId, array $folders): void {
        foreach ($folders as $folderInfo) {
            // Check if folder exists
            $sql = "
                SELECT folders_id FROM " . PREFIX . "_email_folders
                WHERE imap_path = ? AND user_id = ?
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$folderInfo['imap_path'], $userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                // Create new folder
                $sql = "
                    INSERT INTO " . PREFIX . "_email_folders
                    (name, display_name, imap_path, type, user_id, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $folderInfo['name'],
                    $folderInfo['display_name'],
                    $folderInfo['imap_path'],
                    $folderInfo['type'],
                    $userId
                ]);

                $this->log("Created folder: {$folderInfo['display_name']}");
            }
        }
    }

    /**
     * Sync emails from a single IMAP folder
     *
     * @param int $userId User ID
     * @param array $folderInfo Folder information
     * @param ImapHelper $imap IMAP connection
     * @return array Stats: ['new' => int, 'updated' => int]
     */
    private function syncFolder(int $userId, array $folderInfo, ImapHelper $imap): array {
        $stats = ['new' => 0, 'updated' => 0];

        // Get folder ID from database
        $sql = "
            SELECT folders_id FROM " . PREFIX . "_email_folders
            WHERE imap_path = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$folderInfo['imap_path'], $userId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            $this->log("Folder not found in database: {$folderInfo['imap_path']}");
            return $stats;
        }

        $folderId = (int)$folder['folders_id'];

        // Get highest UID we have for this folder
        $sql = "
            SELECT MAX(uid) AS max_uid FROM " . PREFIX . "_email_messages
            WHERE folder_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$folderId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastUid = (int)($result['max_uid'] ?? 0);

        // Fetch new messages from IMAP (limit to 50 per sync to avoid timeouts)
        $this->log("Fetching messages from {$folderInfo['display_name']} (since UID $lastUid)");
        $messages = $imap->getMessages($folderInfo['imap_path'], $lastUid, 50);

        foreach ($messages as $message) {
            // Check if message already exists
            $sql = "
                SELECT emails_id FROM " . PREFIX . "_email_messages
                WHERE message_id = ? AND folder_id = ? AND user_id = ?
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$message['message_id'], $folderId, $userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing message
                $sql = "
                    UPDATE " . PREFIX . "_email_messages
                    SET is_read = ?, is_flagged = ?, updated_at = NOW()
                    WHERE emails_id = ?
                ";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $message['is_read'] ? 1 : 0,
                    $message['is_flagged'] ? 1 : 0,
                    $existing['emails_id']
                ]);

                $stats['updated']++;
            } else {
                // Insert new message
                $sql = "
                    INSERT INTO " . PREFIX . "_email_messages
                    (uid, message_id, folder_id, from_email, from_name, to_json, cc_json, bcc_json,
                     subject, body_plain, body_html, email_date, is_read, is_flagged, has_attachments,
                     size_bytes, user_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $message['uid'],
                    $message['message_id'],
                    $folderId,
                    $message['from']['email'],
                    $message['from']['name'],
                    json_encode($message['to']),
                    !empty($message['cc']) ? json_encode($message['cc']) : null,
                    !empty($message['bcc']) ? json_encode($message['bcc']) : null,
                    $message['subject'],
                    $message['body_plain'],
                    $message['body_html'],
                    $message['email_date'],
                    $message['is_read'] ? 1 : 0,
                    $message['is_flagged'] ? 1 : 0,
                    $message['has_attachments'] ? 1 : 0,
                    $message['size_bytes'],
                    $userId
                ]);

                $emailId = (int)$this->pdo->lastInsertId();

                // Insert attachments
                if (!empty($message['attachments'])) {
                    $this->insertAttachments($emailId, $message['attachments']);
                }

                $stats['new']++;
            }
        }

        $this->log("Synced {$folderInfo['display_name']}: {$stats['new']} new, {$stats['updated']} updated");
        return $stats;
    }

    /**
     * Insert attachments for an email
     *
     * @param int $emailId Email ID
     * @param array $attachments Array of attachment info
     */
    private function insertAttachments(int $emailId, array $attachments): void {
        foreach ($attachments as $attachment) {
            $sql = "
                INSERT INTO " . PREFIX . "_email_attachments
                (email_id, filename, mime_type, size_bytes, imap_part_id, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $emailId,
                $attachment['filename'],
                $attachment['mime_type'],
                $attachment['size_bytes'],
                $attachment['imap_part_id']
            ]);
        }
    }
}
