<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Handler für Favorite-Toggle eines Artikels.
 * Routen (siehe request.php):
 *   POST   /articles/{id}/favorite  → favorisieren   (setFavorite(true))
 *   DELETE /articles/{id}/favorite  → entfavorisieren (setFavorite(false))
 *
 * Ohne diesen Handler fiel der Request früher auf die generische Articles-Route:
 * POST → CREATE (leerer Artikel angelegt), DELETE → Artikel gelöscht. Beides
 * destruktiv und die Ursache des „Inhalt verschwindet"-Bugs.
 *
 * Antwortformat: EIN angereichertes Artikel-Objekt (nicht als Array), passend
 * zum Frontend-Service `favoriteArticle()/unfavoriteArticle()` (ArticleResponse).
 */
class requestArticleFavorite extends RequestBase {
    private int $articleId = 0;
    private bool $favorite = true;

    public function setArticleId(int $id): void {
        $this->articleId = $id;
    }

    /** true = favorisieren (POST), false = entfernen (DELETE). */
    public function setFavorite(bool $favorite): void {
        $this->favorite = $favorite;
    }

    public function execute(): void {
        try {
            // Favorisieren erfordert eine Anmeldung (Favorit ist nutzergebunden).
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];
            $articleId = $this->articleId;

            if ($articleId <= 0) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid article id']);
                return;
            }

            // Existenz prüfen → sauberer 404 statt FK-Fehler/Leerantwort.
            $check = $this->pdo->prepare(
                'SELECT articles_id FROM ' . PREFIX . '_articles WHERE articles_id = ?'
            );
            $check->execute([$articleId]);
            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Article not found']);
                return;
            }

            if ($this->favorite) {
                // Idempotent: doppeltes Favorisieren erzeugt keinen zweiten Eintrag.
                $stmt = $this->pdo->prepare(
                    'INSERT IGNORE INTO ' . PREFIX . '_article_favorites (user_id, articles_id) VALUES (?, ?)'
                );
                $stmt->execute([$userId, $articleId]);
            } else {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM ' . PREFIX . '_article_favorites WHERE user_id = ? AND articles_id = ?'
                );
                $stmt->execute([$userId, $articleId]);
            }

            // Denormalisiertes favorites_count synchron halten.
            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS c FROM ' . PREFIX . '_article_favorites WHERE articles_id = ?'
            );
            $countStmt->execute([$articleId]);
            $count = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

            $upd = $this->pdo->prepare(
                'UPDATE ' . PREFIX . '_articles SET favorites_count = ? WHERE articles_id = ?'
            );
            $upd->execute([$count, $articleId]);

            // Angereichertes Einzel-Objekt zurückgeben (Tags, favorited, author),
            // damit der Store `original` vollständig aktualisieren kann.
            $getHandler = new requestGetArticles($this->pdo, 'articles');
            $article = $getHandler->fetchOneEnriched($articleId, $user);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($article);
        } catch (\Throwable $e) {
            $this->handleError('Error toggling article favorite', $e);
        }
    }
}
