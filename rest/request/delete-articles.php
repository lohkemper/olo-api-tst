<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized DELETE handler for articles endpoint
 * Handles: DELETE /api/articles/{id}
 *
 * Deletes an article (with CASCADE delete for tags and favorites)
 */
class requestDeleteArticles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteArticles::execute');
            $this->log(['requestDeleteArticles::request', $this->request]);

            // Require authentication
            $user = $this->requireAuth();

            // Check if ID is provided
            if (!isset($this->request['id']) || empty($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing article ID']);
                return;
            }

            // Handle both array and scalar ID (request.php converts single IDs to arrays)
            $id = $this->request['id'];
            $articleId = is_array($id) ? (int)$id[0] : (int)$id;

            // Check if article exists and get owner
            $article = $this->getArticle($articleId);
            if (!$article) {
                http_response_code(404);
                echo json_encode(['error' => 'Article not found']);
                return;
            }

            // Check permissions: user must be owner OR have articles.delete.any permission
            $isOwner = (int)$article['user_id'] === (int)$user['users_id'];
            $canDeleteAny = $this->hasPermission($user, 'articles.delete.any');

            if (!$isOwner && !$canDeleteAny) {
                // User doesn't own article and doesn't have delete.any permission
                // Check if they have at least delete.own
                if (!$this->hasPermission($user, 'articles.delete.own')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: You do not have permission to delete articles']);
                    return;
                }
                // Has delete.own but not owner
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: You can only delete your own articles']);
                return;
            }

            // Delete article (CASCADE will handle tags and favorites)
            $sql = "DELETE FROM " . PREFIX . "_articles WHERE articles_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$articleId]);

            // Check if deletion was successful
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Article deleted successfully',
                    'deleted_id' => $articleId
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete article']);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing DELETE articles request', $e);
        }
    }

    /**
     * Get article by ID
     */
    private function getArticle(int $articleId): ?array {
        $sql = "SELECT * FROM " . PREFIX . "_articles WHERE articles_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$articleId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
