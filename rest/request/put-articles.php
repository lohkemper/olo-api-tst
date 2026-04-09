<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized PUT handler for articles endpoint
 * Handles: PUT /api/articles/{id}
 *
 * Updates an existing article with tags
 */
class requestPutArticles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            global $_PUT;
            $this->log('requestPutArticles::execute');
            $this->log(['requestPutArticles::request', $this->request]);
            $this->log(['requestPutArticles::_PUT', $_PUT]);

            // Require authentication
            $user = $this->requireAuth();

            // Check if ID is provided
            if (!isset($this->request['id']) || empty($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing article ID']);
                return;
            }

            // ID comes as array from request.php:121 (explode(',', $value))
            $articleId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];

            // Check if article exists and get owner
            $article = $this->getArticle($articleId);
            if (!$article) {
                http_response_code(404);
                echo json_encode(['error' => 'Article not found']);
                return;
            }

            // Check permissions: user must be owner OR have articles.update.any permission
            $isOwner = (int)$article['user_id'] === (int)$user['users_id'];
            $canUpdateAny = $this->hasPermission($user, 'articles.update.any');

            if (!$isOwner && !$canUpdateAny) {
                // User doesn't own article and doesn't have update.any permission
                // Check if they have at least update.own
                if (!$this->hasPermission($user, 'articles.update.own')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: You do not have permission to update articles']);
                    return;
                }
                // Has update.own but not owner
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: You can only update your own articles']);
                return;
            }

            // Extract tags if present (handle separately)
            $tagList = $_PUT['tagList'] ?? null;
            unset($_PUT['tagList']);

            // Remove fields that shouldn't be updated
            unset($_PUT['id']);
            unset($_PUT['articles_id']);
            unset($_PUT['user_id']);
            unset($_PUT['author']);
            unset($_PUT['favorited']);
            unset($_PUT['favoritesCount']);
            unset($_PUT['created_at']);
            unset($_PUT['createdAt']);
            unset($_PUT['updated_at']);
            unset($_PUT['updatedAt']);

            // Build UPDATE SQL
            $set = [];
            foreach ($_PUT as $key => $value) {
                $dbKey = $this->convertCamelToSnake($key);

                if (!$this->isValidColumnName($dbKey)) {
                    continue;
                }

                // Handle different value types
                if (is_bool($value)) {
                    $quotedValue = $value ? '1' : '0';
                } elseif (is_null($value) || $value === '') {
                    $quotedValue = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $quotedValue = (string)$value;
                } else {
                    $quotedValue = $this->pdo->quote((string)$value);
                }

                $set[] = '`' . $dbKey . '` = ' . $quotedValue;
            }

            // Only execute UPDATE if there are fields to update
            if (count($set) > 0) {
                $sql = 'UPDATE `' . PREFIX . '_articles` SET ' . implode(', ', $set);
                $sql .= ' WHERE `articles_id` = ' . $articleId;

                $this->log(['requestPutArticles::sql', $sql]);

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
            }

            // Update tags if provided
            if ($tagList !== null) {
                $this->updateArticleTags($articleId, $tagList);
            }

            // Return updated article
            $requestGet = new requestGetArticles($this->pdo, 'articles');
            $requestGet->setRequest(['id' => $articleId]);
            $requestGet->execute();

        } catch (\Throwable $e) {
            $this->handleError('Error executing PUT articles request', $e);
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

    /**
     * Update article tags (replace all existing tags)
     */
    private function updateArticleTags(int $articleId, array $tagList): void {
        // Remove all existing tags
        $sql = "DELETE FROM " . PREFIX . "_article_tags WHERE articles_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$articleId]);

        // Add new tags
        foreach ($tagList as $tagData) {
            $tagId = null;

            // If tag has ID, use it
            if (isset($tagData['id']) && $tagData['id'] > 0) {
                $tagId = (int)$tagData['id'];
            }
            // Otherwise, find or create tag by name
            else if (isset($tagData['name'])) {
                $tagId = $this->findOrCreateTag($tagData['name']);
            }

            // Link tag to article
            if ($tagId !== null) {
                $this->linkArticleTag($articleId, $tagId);
            }
        }
    }

    /**
     * Find existing tag or create new one
     */
    private function findOrCreateTag(string $tagName): int {
        // Check if tag exists
        $sql = "SELECT tags_id FROM " . PREFIX . "_tags WHERE name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return (int)$result['tags_id'];
        }

        // Create new tag
        $slug = $this->generateTagSlug($tagName);
        $sql = "INSERT INTO " . PREFIX . "_tags (name, slug) VALUES (?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName, $slug]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Generate slug for tag
     */
    private function generateTagSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Link article to tag
     */
    private function linkArticleTag(int $articleId, int $tagId): void {
        $sql = "INSERT IGNORE INTO " . PREFIX . "_article_tags (articles_id, tag_id) VALUES (?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$articleId, $tagId]);
    }

    /**
     * Convert camelCase to snake_case
     */
    private function convertCamelToSnake(string $input): string {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }
}
