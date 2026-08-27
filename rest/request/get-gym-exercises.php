<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/exercises          — Übungs-Katalog (System-Templates + eigene)
 * GET /gym/exercises/{id}     — Einzelne Übung (sichtbar wenn Template oder eigen)
 */
class requestGetGymExercises extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $exerciseId = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($exerciseId !== null) {
                $this->getById($userId, $exerciseId);
            } else {
                $this->getAll($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym exercises', $e);
        }
    }

    private function getAll(int $userId): void {
        // Sichtbar: System-Templates (user_id IS NULL) + eigene
        // LEFT JOIN auf mbc_warehouse_items, damit das Frontend den
        // Equipment-Namen ohne extra Round-Trip anzeigen kann (Phase 4).
        $stmt = $this->pdo->prepare(
            'SELECT e.exercises_id, e.user_id, e.name, e.slug, e.category_id,
                    e.equipment_article_id, e.primary_muscle, e.primary_muscles, e.secondary_muscles,
                    e.exercise_type, e.measurement_type, e.description,
                    e.video_url, e.photo_url, e.is_template, e.created_at, e.updated_at,
                    wi.name AS equipment_name
             FROM mbc_gym_exercises e
             LEFT JOIN mbc_warehouse_items wi ON wi.items_id = e.equipment_article_id
             WHERE e.user_id IS NULL OR e.user_id = ?
             ORDER BY e.name ASC'
        );
        $stmt->execute([$userId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getById(int $userId, int $exerciseId): void {
        $stmt = $this->pdo->prepare(
            'SELECT e.exercises_id, e.user_id, e.name, e.slug, e.category_id,
                    e.equipment_article_id, e.primary_muscle, e.primary_muscles, e.secondary_muscles,
                    e.exercise_type, e.measurement_type, e.description,
                    e.video_url, e.photo_url, e.is_template, e.created_at, e.updated_at,
                    wi.name AS equipment_name
             FROM mbc_gym_exercises e
             LEFT JOIN mbc_warehouse_items wi ON wi.items_id = e.equipment_article_id
             WHERE e.exercises_id = ?
               AND (e.user_id IS NULL OR e.user_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$exerciseId, $userId]);
        $exercise = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exercise) {
            http_response_code(404);
            echo json_encode(['error' => 'Exercise not found']);
            return;
        }

        echo json_encode($exercise);
    }
}
