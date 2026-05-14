<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/exercise-categories — Alle System-Kategorien + eigene
 */
class requestGetGymExerciseCategories extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $stmt = $this->pdo->prepare(
                'SELECT exercise_categories_id, name, muscle_group, parent_id, user_id,
                        created_at, updated_at
                 FROM mbc_gym_exercise_categories
                 WHERE user_id IS NULL OR user_id = ?
                 ORDER BY muscle_group ASC, name ASC'
            );
            $stmt->execute([$userId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym exercise categories', $e);
        }
    }
}
