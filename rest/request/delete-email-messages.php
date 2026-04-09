<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE handler for email-messages endpoint
 * Handles: DELETE /api/email-messages/{id}
 *
 * Deletes email from database and optionally from IMAP server
 *
 * @version 1.0.0
 */
class requestDeleteEmailMessages extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteEmailMessages::execute');
            $this->log(['requestDeleteEmailMessages::request', $this->request]);

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

            // Verify message exists and belongs to user
            $sql = "SELECT * FROM " . PREFIX . "_email_messages WHERE emails_id = ? AND user_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$messageId, $userId]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$message) {
                http_response_code(404);
                echo json_encode(['error' => 'Message not found']);
                return;
            }

            // Delete from IMAP server
            $this->deleteFromImap((int)$message['uid'], (int)$message['folder_id']);

            // Delete message from database (CASCADE will delete tags and attachments automatically)
            $sql = "DELETE FROM " . PREFIX . "_email_messages WHERE emails_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$messageId]);

            http_response_code(204); // No Content

        } catch (\Throwable $e) {
            $this->handleError('Error deleting email message', $e);
        }
    }

    /**
     * Delete message from IMAP server
     *
     * @param int $uid IMAP UID
     * @param int $folderId Folder ID
     */
    private function deleteFromImap(int $uid, int $folderId): void {
        try {
            // Get IMAP configuration
            $imapHost = $_ENV['IMAP_HOST'] ?? null;
            $imapPort = $_ENV['IMAP_PORT'] ?? '993';
            $imapUser = $_ENV['IMAP_USER'] ?? null;
            $imapPassword = $_ENV['IMAP_PASSWORD'] ?? null;
            $imapEncryption = $_ENV['IMAP_ENCRYPTION'] ?? 'ssl';

            // Skip if IMAP is not configured
            if (!$imapHost || !$imapUser || !$imapPassword) {
                $this->log('IMAP not configured, skipping deletion from server');
                return;
            }

            // Get folder IMAP path from database
            $sql = "SELECT imap_path FROM " . PREFIX . "_email_folders WHERE folders_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$folderId]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$folder) {
                $this->log('Folder not found for message, skipping IMAP deletion');
                return;
            }

            // Load IMAP Helper
            require_once __DIR__ . '/../lib/ImapHelper.php';

            // Connect to IMAP server
            $imap = new ImapHelper(
                $imapHost,
                (int)$imapPort,
                $imapUser,
                $imapPassword,
                $imapEncryption
            );

            $imap->connect();

            // Mark message as deleted and expunge
            $success = $imap->deleteMessage($folder['imap_path'], $uid);

            if ($success) {
                // Expunge to permanently delete
                $imap->expunge();
                $this->log("Message UID $uid deleted from IMAP server");
            } else {
                $this->log("Failed to delete message UID $uid from IMAP server");
            }

            $imap->disconnect();

        } catch (\Throwable $e) {
            // Log error but don't fail the request - database deletion will still happen
            $this->log('IMAP deletion error: ' . $e->getMessage());
        }
    }
}
