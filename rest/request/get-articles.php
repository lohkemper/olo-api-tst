<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized GET handler for articles endpoint
 * Handles: GET /api/articles, GET /api/articles/{id}
 *
 * Returns articles with author information and tag list
 */
class requestGetArticles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetArticles::execute');
            $this->log(['requestGetArticles::request', $this->request]);

            // Articles can be read without authentication
            $user = $this->getCurrentUser();

            // Check if specific article ID is requested
            if (isset($this->request['id'])) {
                $id = is_array($this->request['id']) ? $this->request['id'] : [$this->request['id']];
                $this->handleGetArticles($user, $id);
            } else {
                $this->handleGetArticles($user);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET articles request', $e);
        }
    }

    /**
     * GET /api/articles or GET /api/articles/{id}
     * Returns articles with author and tags
     */
    private function handleGetArticles(?array $user, ?array $ids = null): void {
        // Build base query
        $sql = "
            SELECT
                a.articles_id,
                a.user_id,
                a.title,
                a.description,
                a.body,
                a.slug,
                a.favorites_count,
                a.is_published,
                a.published_at,
                a.created_at,
                a.updated_at,
                u.users_id AS author_id,
                u.username AS author_username,
                u.email AS author_email,
                u.first_name AS author_first_name,
                u.last_name AS author_last_name
            FROM " . PREFIX . "_articles a
            LEFT JOIN " . PREFIX . "_users u ON a.user_id = u.users_id
        ";

        $params = [];

        // Filter by specific IDs if provided
        if ($ids !== null) {
            $placeholders = array_fill(0, count($ids), '?');
            $sql .= " WHERE a.articles_id IN (" . implode(',', $placeholders) . ")";
            $params = array_map('intval', $ids);
        }

        // Only show published articles to non-authors
        if ($user === null) {
            $sql .= ($ids !== null ? " AND" : " WHERE") . " a.is_published = 1";
        } else if ($ids === null) {
            // Show all published + user's own articles
            $sql .= " WHERE (a.is_published = 1 OR a.user_id = ?)";
            $params[] = (int)$user['users_id'];
        }

        $sql .= " ORDER BY a.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich articles with tags and favorite status
        $enrichedArticles = array_map(function($article) use ($user) {
            return $this->enrichArticle($article, $user);
        }, $articles);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($enrichedArticles);
    }

    /**
     * Enrich article with tags, favorite status, and formatted author
     */
    private function enrichArticle(array $article, ?array $user): array {
        $articleId = (int)$article['articles_id'];

        // Get tags for this article
        $tags = $this->getArticleTags($articleId);

        // Check if user has favorited this article
        $favorited = false;
        if ($user !== null) {
            $favorited = $this->isArticleFavoritedByUser($articleId, (int)$user['users_id']);
        }

        return [
            'id' => $articleId,
            'title' => $article['title'],
            'description' => $article['description'],
            'body' => $article['body'],
            'slug' => $article['slug'],
            'tagList' => $tags,
            'favorited' => $favorited,
            'favoritesCount' => (int)$article['favorites_count'],
            'isPublished' => (bool)$article['is_published'],
            'publishedAt' => $article['published_at'],
            'createdAt' => $article['created_at'],
            'updatedAt' => $article['updated_at'],
            'author' => [
                'id' => $article['author_id'] !== null ? (int)$article['author_id'] : null,
                'username' => $article['author_username'] ?? '',
                'email' => $article['author_email'] ?? '',
                'firstName' => $article['author_first_name'] ?? '',
                'lastName' => $article['author_last_name'] ?? '',
                'bio' => '',
                'image' => '',
                'following' => false,
                'loading' => false
            ]
        ];
    }

    /**
     * Get all tags for an article
     */
    private function getArticleTags(int $articleId): array {
        $sql = "
            SELECT t.tags_id AS id, t.name
            FROM " . PREFIX . "_tags t
            INNER JOIN " . PREFIX . "_article_tags at ON t.tags_id = at.tag_id
            WHERE at.articles_id = ?
            ORDER BY t.name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$articleId]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($tag) {
            return [
                'id' => (int)$tag['id'],
                'name' => $tag['name']
            ];
        }, $tags);
    }

    /**
     * Check if user has favorited an article
     */
    private function isArticleFavoritedByUser(int $articleId, int $userId): bool {
        $sql = "
            SELECT COUNT(*) as count
            FROM " . PREFIX . "_article_favorites
            WHERE articles_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$articleId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)$result['count'] > 0;
    }
}
