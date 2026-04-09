<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized POST handler for articles endpoint
 * Handles: POST /api/articles
 *
 * Creates a new article with tags
 */
class requestPostArticles extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $this->log('requestPostArticles::execute');
            $this->log(['requestPostArticles::data', $this->data]);

            // Require authentication and permission to create articles
            $user = $this->requirePermission('articles.create');

            // Extract tags if present
            $tagList = $this->data['tagList'] ?? [];
            unset($this->data['tagList']);

            // Extract author data (not needed for insert)
            unset($this->data['author']);
            unset($this->data['favorited']);
            unset($this->data['favoritesCount']);

            // Set user_id from authenticated user
            $this->data['user_id'] = (int)$user['users_id'];

            // Generate slug from title if not provided
            if (empty($this->data['slug']) && !empty($this->data['title'])) {
                $this->data['slug'] = $this->generateSlug($this->data['title']);
            }

            // Prepare SQL INSERT with proper prepared statements
            $columns = [];
            $placeholders = [];
            $params = [];

            foreach ($this->data as $key => $value) {
                // Skip timestamps - let database handle these
                if (in_array($key, ['id', 'created_at', 'updated_at', 'createdAt', 'updatedAt', 'published_at', 'publishedAt'])) {
                    continue;
                }

                $dbKey = $this->convertCamelToSnake($key);

                // Handle NULL values
                if ($value === null || $value === '') {
                    $columns[] = '`' . $dbKey . '`';
                    $placeholders[] = 'NULL';
                    continue;
                }

                // Handle boolean values
                if ($value === true || $value === 'true') {
                    $value = 1;
                } else if ($value === false || $value === 'false') {
                    $value = 0;
                }

                $columns[] = '`' . $dbKey . '`';
                $placeholders[] = '?';
                $params[] = $value;
            }

            $sqlquery = 'INSERT INTO `' . PREFIX . '_articles` ';
            $sqlquery .= '(' . implode(', ', $columns) . ') VALUES ';
            $sqlquery .= '(' . implode(', ', $placeholders) . ')';

            $this->log(['requestPostArticles::sqlquery', $sqlquery]);
            $this->log(['requestPostArticles::params', $params]);

            $qRequest = $this->pdo->prepare($sqlquery);
            $qRequest->execute($params);

            $articleId = (int)$this->pdo->lastInsertId();

            // Add tags if provided
            if (!empty($tagList) && $articleId > 0) {
                $this->addArticleTags($articleId, $tagList);
            }

            // Return created article
            $requestGet = new requestGetArticles($this->pdo, 'articles');
            $requestGet->setRequest(['id' => $articleId]);
            $requestGet->execute();

        } catch (\Throwable $e) {
            $this->handleError('Error executing POST articles request', $e);
        }
    }

    /**
     * Generate URL-friendly slug from title
     */
    private function generateSlug(string $title): string {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $baseSlug = $slug;
        $counter = 1;
        while ($this->slugExists($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if slug already exists
     */
    private function slugExists(string $slug): bool {
        $sql = "SELECT COUNT(*) as count FROM " . PREFIX . "_articles WHERE slug = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$slug]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['count'] > 0;
    }

    /**
     * Add tags to article
     */
    private function addArticleTags(int $articleId, array $tagList): void {
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
