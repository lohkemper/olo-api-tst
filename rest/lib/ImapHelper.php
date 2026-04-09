<?php
declare(strict_types=1);

/**
 * IMAP Helper Class
 *
 * Provides unified IMAP operations for email synchronization.
 * Uses PHP's native imap extension.
 *
 * @version 1.0.0
 */
class ImapHelper {
    private $connection = null;
    private string $host;
    private int $port;
    private string $user;
    private string $password;
    private string $encryption;
    private array $logs = [];

    /**
     * Constructor
     */
    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $encryption = 'ssl'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->encryption = strtolower($encryption);
    }

    /**
     * Connect to IMAP server
     *
     * @throws Exception if connection fails
     */
    public function connect(): void {
        if ($this->connection) {
            return; // Already connected
        }

        // Build mailbox string for IONOS
        // Based on testing, use standard SSL without 'secure' flag
        // IONOS authentication fails with 'secure' flag
        $flags = [
            $this->encryption, // ssl or tls
            'novalidate-cert', // Allow self-signed certificates
        ];

        $mailbox = sprintf(
            '{%s:%d/%s}',
            $this->host,
            $this->port,
            implode('/', $flags)
        );

        $this->log("Connecting to IMAP: $mailbox");
        $this->log("User: $this->user");

        // Clear any previous IMAP errors
        @imap_errors();
        @imap_alerts();

        // Attempt connection with retries for IONOS
        $maxRetries = 3;
        $retryDelay = 1; // seconds
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Suppress PHP warnings during connection attempt
                set_error_handler(function($errno, $errstr) use (&$lastError) {
                    $lastError = $errstr;
                    // Don't throw exception yet, let imap_open return false
                }, E_WARNING | E_NOTICE);

                $this->connection = @imap_open(
                    $mailbox,
                    $this->user,
                    $this->password,
                    0,  // Options: 0 = default
                    1,  // Retries within imap_open
                    []  // Parameters
                );

                restore_error_handler();

                if ($this->connection) {
                    $this->log("Connected successfully on attempt $attempt");
                    return;
                }

                // Get detailed error information
                $imapError = imap_last_error();
                $this->log("Connection attempt $attempt failed: " . ($imapError ?: $lastError));

                if ($attempt < $maxRetries) {
                    $this->log("Retrying in $retryDelay seconds...");
                    sleep($retryDelay);
                }

            } catch (\Throwable $e) {
                restore_error_handler();
                $this->log("Exception on attempt $attempt: " . $e->getMessage());

                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                sleep($retryDelay);
            }
        }

        // All attempts failed
        restore_error_handler();

        // Collect all error information
        $errors = imap_errors();
        $alerts = imap_alerts();

        $errorDetails = [
            'last_error' => $lastError,
            'imap_last_error' => imap_last_error(),
            'imap_errors' => $errors ?: [],
            'imap_alerts' => $alerts ?: [],
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'encryption' => $this->encryption,
        ];

        $this->log("All connection attempts failed. Details: " . json_encode($errorDetails));

        throw new Exception(
            "Failed to connect to IMAP server after $maxRetries attempts. " .
            "Last error: " . ($errorDetails['imap_last_error'] ?: $lastError) .
            " | Check credentials and server settings."
        );
    }

    /**
     * Disconnect from IMAP server
     */
    public function disconnect(): void {
        if ($this->connection) {
            // Clear any pending IMAP errors before closing
            @imap_errors();
            @imap_alerts();

            // Close connection without throwing warnings
            @imap_close($this->connection);
            $this->connection = null;
            $this->log("Disconnected from IMAP server");
        }
    }

    /**
     * Get list of all folders
     *
     * @return array Array of folder objects with name, path, and type
     */
    public function getFolders(): array {
        $this->ensureConnected();

        // Build mailbox pattern - note: must include /novalidate-cert for the reference
        $mailboxReference = sprintf('{%s:%d/%s/novalidate-cert}', $this->host, $this->port, $this->encryption);

        $this->log("Fetching folders from: $mailboxReference");

        // Try to get folders with different patterns
        $folders = imap_list($this->connection, $mailboxReference, '*');

        // If that fails, try with empty reference
        if (!$folders || count($folders) === 0) {
            $this->log("No folders found with pattern '*', trying ''");
            $folders = imap_list($this->connection, $mailboxReference, '');
        }

        // If still no folders, try to get at least INBOX
        if (!$folders || count($folders) === 0) {
            $this->log("No folders found, trying to access INBOX directly");
            $folders = imap_list($this->connection, $mailboxReference, 'INBOX');
        }

        if (!$folders || count($folders) === 0) {
            $this->log("WARNING: No folders found!");

            // Log IMAP errors
            $errors = imap_errors();
            if ($errors) {
                foreach ($errors as $error) {
                    $this->log("IMAP Error: $error");
                }
            }

            return [];
        }

        $result = [];
        foreach ($folders as $folder) {
            $folderInfo = $this->parseFolderPath($folder);
            if ($folderInfo) {
                $result[] = $folderInfo;
            }
        }

        $this->log("Found " . count($result) . " folders");
        return $result;
    }

    /**
     * Get messages from a folder
     *
     * @param string $folderPath IMAP folder path (e.g., "INBOX")
     * @param int $sinceUid Only fetch messages with UID greater than this (0 = all)
     * @param int $limit Maximum number of messages to fetch (0 = all)
     * @return array Array of message objects
     */
    public function getMessages(string $folderPath, int $sinceUid = 0, int $limit = 0): array {
        $this->ensureConnected();

        // Reopen connection for specific folder
        $mailbox = sprintf('{%s:%d/%s/novalidate-cert}%s', $this->host, $this->port, $this->encryption, $folderPath);

        $this->log("Opening folder: $mailbox");

        // Suppress errors and capture them
        set_error_handler(function($errno, $errstr) {
            // Convert warnings to exceptions
        });

        try {
            if (!imap_reopen($this->connection, $mailbox)) {
                $error = imap_last_error();
                $this->log("ERROR: Failed to open folder $folderPath: $error");
                return [];
            }
        } catch (\Throwable $e) {
            $this->log("ERROR: Exception opening folder $folderPath: " . $e->getMessage());
            return [];
        } finally {
            restore_error_handler();
        }

        // Get UIDs
        $uids = imap_search($this->connection, 'ALL', SE_UID);

        if (!$uids) {
            $this->log("No messages found in folder: $folderPath");
            return [];
        }

        // Filter by UID if needed
        if ($sinceUid > 0) {
            $uids = array_filter($uids, fn($uid) => $uid > $sinceUid);
        }

        // Apply limit
        if ($limit > 0 && count($uids) > $limit) {
            $uids = array_slice($uids, -$limit); // Get latest N messages
        }

        $messages = [];
        foreach ($uids as $uid) {
            $message = $this->fetchMessage($uid);
            if ($message) {
                $messages[] = $message;
            }
        }

        $this->log("Fetched " . count($messages) . " messages from $folderPath");
        return $messages;
    }

    /**
     * Fetch a single message by UID
     *
     * @param int $uid Message UID
     * @return array|null Message data or null if not found
     */
    private function fetchMessage(int $uid): ?array {
        $msgNumber = imap_msgno($this->connection, $uid);

        if (!$msgNumber) {
            return null;
        }

        // Get headers
        $header = imap_headerinfo($this->connection, $msgNumber);
        if (!$header) {
            return null;
        }

        // Get structure
        $structure = imap_fetchstructure($this->connection, $msgNumber);

        // Get body
        $body = $this->getBody($msgNumber, $structure);

        // Parse addresses
        $from = $this->parseAddress($header->from ?? []);
        $to = $this->parseAddresses($header->to ?? []);
        $cc = $this->parseAddresses($header->cc ?? []);
        $bcc = $this->parseAddresses($header->bcc ?? []);

        // Get message ID
        $messageId = $header->message_id ?? '<no-id-' . $uid . '@unknown>';

        // Get flags
        $flags = imap_fetch_overview($this->connection, (string)$uid, FT_UID)[0] ?? null;
        $isRead = $flags ? !$flags->seen : false;
        $isFlagged = $flags ? $flags->flagged : false;

        // Parse date
        $date = $header->date ?? date('r');
        try {
            $emailDate = new DateTime($date);
        } catch (Exception $e) {
            $emailDate = new DateTime();
        }

        return [
            'uid' => $uid,
            'message_id' => trim($messageId, '<>'),
            'from' => $from,
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $this->decodeMimeStr($header->subject ?? '(No Subject)'),
            'body_plain' => $body['plain'] ?? '',
            'body_html' => $body['html'] ?? null,
            'email_date' => $emailDate->format('Y-m-d H:i:s'),
            'is_read' => !$isRead,
            'is_flagged' => $isFlagged,
            'has_attachments' => $this->hasAttachments($structure),
            'size_bytes' => $header->Size ?? 0,
            'attachments' => $this->getAttachments($msgNumber, $structure),
        ];
    }

    /**
     * Get email body (plain and HTML)
     */
    private function getBody(int $msgNumber, $structure): array {
        $body = [
            'plain' => '',
            'html' => null,
        ];

        if (!$structure) {
            return $body;
        }

        // Handle multipart messages
        if (isset($structure->parts)) {
            $this->extractBodyParts($msgNumber, $structure->parts, $body);
        } else {
            // Single part message
            $data = imap_body($this->connection, $msgNumber);
            $data = $this->decodeBody($data, $structure->encoding ?? 0);

            $mimeType = $this->getMimeType($structure);
            if ($mimeType === 'text/html') {
                $body['html'] = $data;
                // Extract plain text from HTML if no plain text exists
                $body['plain'] = $this->htmlToPlainText($data);
            } else {
                $body['plain'] = $data;
            }
        }

        // Fallback: If we have HTML but no plain text, convert HTML to plain
        if (empty($body['plain']) && !empty($body['html'])) {
            $body['plain'] = $this->htmlToPlainText($body['html']);
        }

        // Ensure we always have at least plain text
        if (empty($body['plain'])) {
            $body['plain'] = '(Kein Textinhalt)';
        }

        return $body;
    }

    /**
     * Recursively extract body parts from multipart message
     */
    private function extractBodyParts(int $msgNumber, array $parts, array &$body, string $prefix = ''): void {
        foreach ($parts as $partNum => $part) {
            $section = $prefix ? "$prefix." . ($partNum + 1) : (string)($partNum + 1);

            // Handle nested multipart
            if (isset($part->parts)) {
                $this->extractBodyParts($msgNumber, $part->parts, $body, $section);
                continue;
            }

            // Skip attachments
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                continue;
            }

            $data = imap_fetchbody($this->connection, $msgNumber, $section);
            $data = $this->decodeBody($data, $part->encoding ?? 0);

            $mimeType = $this->getMimeType($part);

            if ($mimeType === 'text/plain' && empty($body['plain'])) {
                $body['plain'] = $data;
            } elseif ($mimeType === 'text/html' && empty($body['html'])) {
                $body['html'] = $data;
            }
        }
    }

    /**
     * Convert HTML to plain text (simple implementation)
     */
    private function htmlToPlainText(string $html): string {
        // Remove scripts and styles
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text);

        // Replace common HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Replace <br> and <p> with newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);

        // Strip all remaining HTML tags
        $text = strip_tags($text);

        // Clean up whitespace
        $text = preg_replace('/\n\s*\n\s*\n/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Decode message body based on encoding
     */
    private function decodeBody(string $data, int $encoding): string {
        switch ($encoding) {
            case 0: // 7BIT
            case 1: // 8BIT
                return $data;
            case 2: // BINARY
                return $data;
            case 3: // BASE64
                return base64_decode($data);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($data);
            default:
                return $data;
        }
    }

    /**
     * Get MIME type of a part
     */
    private function getMimeType($part): string {
        $primaryType = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];

        $type = $primaryType[$part->type ?? 0] ?? 'text';
        $subtype = strtolower($part->subtype ?? 'plain');

        return "$type/$subtype";
    }

    /**
     * Check if message has attachments
     */
    private function hasAttachments($structure): bool {
        if (!isset($structure->parts)) {
            return false;
        }

        foreach ($structure->parts as $part) {
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get attachments from message
     */
    private function getAttachments(int $msgNumber, $structure): array {
        $attachments = [];

        if (!isset($structure->parts)) {
            return $attachments;
        }

        foreach ($structure->parts as $partNum => $part) {
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                $filename = $this->getFilename($part);

                if ($filename) {
                    $attachments[] = [
                        'filename' => $filename,
                        'mime_type' => $this->getMimeType($part),
                        'size_bytes' => $part->bytes ?? 0,
                        'imap_part_id' => (string)($partNum + 1),
                    ];
                }
            }
        }

        return $attachments;
    }

    /**
     * Get filename from part parameters
     */
    private function getFilename($part): ?string {
        $filename = null;

        if (isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    $filename = $param->value;
                    break;
                }
            }
        }

        if (!$filename && isset($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    $filename = $param->value;
                    break;
                }
            }
        }

        return $filename ? $this->decodeMimeStr($filename) : null;
    }

    /**
     * Parse single email address
     */
    private function parseAddress(array $addresses): array {
        if (empty($addresses)) {
            return ['email' => 'unknown@unknown', 'name' => null];
        }

        $addr = $addresses[0];
        $email = ($addr->mailbox ?? 'unknown') . '@' . ($addr->host ?? 'unknown');
        $name = isset($addr->personal) ? $this->decodeMimeStr($addr->personal) : null;

        return ['email' => $email, 'name' => $name];
    }

    /**
     * Parse multiple email addresses
     */
    private function parseAddresses(array $addresses): array {
        $result = [];

        foreach ($addresses as $addr) {
            $email = ($addr->mailbox ?? 'unknown') . '@' . ($addr->host ?? 'unknown');
            $name = isset($addr->personal) ? $this->decodeMimeStr($addr->personal) : null;
            $result[] = ['email' => $email, 'name' => $name];
        }

        return $result;
    }

    /**
     * Decode MIME-encoded strings
     */
    private function decodeMimeStr(string $str): string {
        $decoded = imap_mime_header_decode($str);
        $result = '';

        foreach ($decoded as $part) {
            $charset = ($part->charset === 'default') ? 'UTF-8' : $part->charset;
            $result .= mb_convert_encoding($part->text, 'UTF-8', $charset);
        }

        return $result;
    }

    /**
     * Parse folder path to extract name and type
     *
     * @return array|null Folder info or null if invalid
     */
    private function parseFolderPath(string $fullPath): ?array {
        // Extract folder name from full path
        // Format: {host:port/ssl/novalidate-cert}FolderName
        preg_match('/\}(.+)$/', $fullPath, $matches);
        $folderPath = $matches[1] ?? null;

        if (!$folderPath) {
            $this->log("WARNING: Could not parse folder path: $fullPath");
            return null;
        }

        // Remove leading/trailing slashes
        $folderPath = trim($folderPath, '/');

        // Skip empty folder paths
        if (empty($folderPath)) {
            return null;
        }

        // Determine folder type based on name
        $name = basename($folderPath);
        $type = $this->determineFolderType($name);

        return [
            'name' => $name,
            'imap_path' => $folderPath,
            'type' => $type,
            'display_name' => $this->getDisplayName($name, $type),
        ];
    }

    /**
     * Determine folder type based on name
     */
    private function determineFolderType(string $name): string {
        $nameLower = strtolower($name);

        if ($nameLower === 'inbox') return 'inbox';
        if (in_array($nameLower, ['sent', 'sent items', 'sent mail'])) return 'sent';
        if (in_array($nameLower, ['drafts', 'entwürfe'])) return 'drafts';
        if (in_array($nameLower, ['trash', 'deleted', 'deleted items', 'papierkorb'])) return 'trash';
        if (in_array($nameLower, ['spam', 'junk', 'junk mail'])) return 'spam';
        if (in_array($nameLower, ['archive', 'archiv'])) return 'archive';

        return 'custom';
    }

    /**
     * Get localized display name for folder
     */
    private function getDisplayName(string $name, string $type): string {
        $translations = [
            'inbox' => 'Posteingang',
            'sent' => 'Gesendet',
            'drafts' => 'Entwürfe',
            'trash' => 'Papierkorb',
            'spam' => 'Spam',
            'archive' => 'Archiv',
        ];

        return $translations[$type] ?? $name;
    }

    /**
     * Set flag on message
     */
    public function setFlag(string $folderPath, int $uid, string $flag, bool $set = true): bool {
        $this->ensureConnected();

        $mailbox = sprintf('{%s:%d/%s/novalidate-cert}%s', $this->host, $this->port, $this->encryption, $folderPath);
        imap_reopen($this->connection, $mailbox);

        $msgNumber = imap_msgno($this->connection, $uid);

        if (!$msgNumber) {
            return false;
        }

        if ($set) {
            return imap_setflag_full($this->connection, (string)$uid, $flag, ST_UID);
        } else {
            return imap_clearflag_full($this->connection, (string)$uid, $flag, ST_UID);
        }
    }

    /**
     * Delete message (mark as deleted)
     */
    public function deleteMessage(string $folderPath, int $uid): bool {
        return $this->setFlag($folderPath, $uid, '\\Deleted', true);
    }

    /**
     * Expunge deleted messages
     */
    public function expunge(): bool {
        $this->ensureConnected();
        return imap_expunge($this->connection);
    }

    /**
     * Ensure connection is established
     */
    private function ensureConnected(): void {
        if (!$this->connection) {
            throw new Exception('Not connected to IMAP server. Call connect() first.');
        }
    }

    /**
     * Log message
     */
    private function log(string $message): void {
        $this->logs[] = ['time' => date('Y-m-d H:i:s'), 'message' => $message];
    }

    /**
     * Get logs
     */
    public function getLogs(): array {
        return $this->logs;
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct() {
        $this->disconnect();
    }
}
