<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for email-messages endpoint
 * Handles:
 * - GET /api/email-messages (all messages for user with filters)
 * - GET /api/email-messages/{id} (specific message with full details)
 *
 * Supports filtering:
 * - folder_id: Filter by folder
 * - is_read: Filter by read status (true/false)
 * - has_attachments: Filter by attachment presence (true/false)
 * - tag: Filter by tag name
 * - page: Pagination page (0-based, default: 0)
 * - limit: Items per page (default: 10, max: 50)
 * - sort: Sort field (date|from|subject, default: date)
 * - order: Sort order (asc|desc, default: desc)
 *
 * @version 1.0.0
 */
class requestGetEmailMessages extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetEmailMessages::execute');
            $this->log(['requestGetEmailMessages::request', $this->request]);

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
                $messageId = is_array($this->request['id'])
                    ? (int)$this->request['id'][0]
                    : (int)$this->request['id'];

                $this->handleGetMessage($userId, $messageId);
            } else {
                $this->handleGetMessages($userId);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET email-messages request', $e);
        }
    }

    /**
     * GET /api/email-messages - All messages for user with filters and pagination
     */
    private function handleGetMessages(int $userId): void {
        // Build WHERE clause
        $where = ["m.user_id = ?"];
        $params = [$userId];

        // Filter by folder_id
        if (isset($this->request['folder_id'])) {
            $where[] = "m.folder_id = ?";
            $params[] = (int)$this->request['folder_id'];
        }

        // Filter by is_read
        if (isset($this->request['is_read'])) {
            $isRead = filter_var($this->request['is_read'], FILTER_VALIDATE_BOOLEAN);
            $where[] = "m.is_read = ?";
            $params[] = $isRead ? 1 : 0;
        }

        // Filter by has_attachments
        if (isset($this->request['has_attachments'])) {
            $hasAttachments = filter_var($this->request['has_attachments'], FILTER_VALIDATE_BOOLEAN);
            $where[] = "m.has_attachments = ?";
            $params[] = $hasAttachments ? 1 : 0;
        }

        // Filter by tag
        if (isset($this->request['tag'])) {
            $where[] = "EXISTS (
                SELECT 1
                FROM " . PREFIX . "_email_tags et
                INNER JOIN " . PREFIX . "_tags t ON et.tag_id = t.tags_id
                WHERE et.email_id = m.emails_id AND t.name = ?
            )";
            $params[] = $this->request['tag'];
        }

        // Pagination
        $page = isset($this->request['page']) ? max(0, (int)$this->request['page']) : 0;
        $limit = isset($this->request['limit']) ? min(50, max(1, (int)$this->request['limit'])) : 10;
        $offset = $page * $limit;

        // Sorting
        $sortField = $this->request['sort'] ?? 'date';
        $sortOrder = strtoupper($this->request['order'] ?? 'desc');

        $allowedSortFields = ['date' => 'email_date', 'from' => 'from_email', 'subject' => 'subject'];
        $sortColumn = $allowedSortFields[$sortField] ?? 'email_date';

        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        // Count total
        $countSql = "
            SELECT COUNT(DISTINCT m.emails_id)
            FROM " . PREFIX . "_email_messages m
            WHERE " . implode(' AND ', $where) . "
        ";

        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Fetch messages (LIMIT/OFFSET must be integers, not bound parameters)
        $sql = "
            SELECT
                m.emails_id,
                m.uid,
                m.message_id,
                m.folder_id,
                m.from_email,
                m.from_name,
                m.to_json,
                m.cc_json,
                m.bcc_json,
                m.subject,
                LEFT(m.body_plain, 200) AS body_preview,
                m.email_date,
                m.is_read,
                m.is_flagged,
                m.has_attachments,
                m.size_bytes,
                m.user_id,
                m.created_at,
                m.updated_at
            FROM " . PREFIX . "_email_messages m
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.{$sortColumn} {$sortOrder}
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with tags
        $enrichedMessages = array_map([$this, 'enrichMessageWithTags'], $messages);

        // Format response
        $response = [
            'data' => $enrichedMessages,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / $limit),
            ],
        ];

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * GET /api/email-messages/{id} - Single message with full details
     */
    private function handleGetMessage(int $userId, int $messageId): void {
        $sql = "
            SELECT
                m.emails_id,
                m.uid,
                m.message_id,
                m.folder_id,
                m.from_email,
                m.from_name,
                m.to_json,
                m.cc_json,
                m.bcc_json,
                m.subject,
                m.body_plain,
                m.body_html,
                m.email_date,
                m.is_read,
                m.is_flagged,
                m.has_attachments,
                m.size_bytes,
                m.user_id,
                m.created_at,
                m.updated_at
            FROM " . PREFIX . "_email_messages m
            WHERE m.emails_id = ? AND m.user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId, $userId]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$message) {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found']);
            return;
        }

        // Enrich with tags and attachments
        $enrichedMessage = $this->enrichMessageWithTags($message);
        $enrichedMessage = $this->enrichMessageWithAttachments($enrichedMessage);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['data' => $enrichedMessage]);
    }

    /**
     * Enriches message with tags from junction table
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

        // Convert numeric types
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

        // Remove JSON fields
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
     * Enriches message with attachments
     */
    private function enrichMessageWithAttachments(array $message): array {
        $messageId = (int)$message['emails_id'];

        $sql = "
            SELECT
                attachments_id AS id,
                email_id,
                filename,
                mime_type,
                size_bytes,
                imap_part_id,
                created_at
            FROM " . PREFIX . "_email_attachments
            WHERE email_id = ?
            ORDER BY filename
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert numeric types
        $attachments = array_map(function($att) {
            return [
                'id' => (int)$att['id'],
                'email_id' => (int)$att['email_id'],
                'filename' => $att['filename'],
                'mime_type' => $att['mime_type'],
                'size_bytes' => (int)$att['size_bytes'],
                'imap_part_id' => $att['imap_part_id'],
                'created_at' => $att['created_at'],
            ];
        }, $attachments);

        $message['attachments'] = $attachments;
        return $message;
    }
}
