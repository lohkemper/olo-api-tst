<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /gym/exercises/{id} — Eigene Übung löschen.
 *
 * System-Templates (user_id IS NULL) sind nicht löschbar (WHERE user_id = current).
 * Wenn die Übung in Workout-Sets oder Plan-Exercises referenziert ist, wirft
 * InnoDB einen RESTRICT-Fehler — wir mappen das auf 409.
 */
class requestDeleteGymExercises extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Exercise id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_gym_exercises WHERE exercises_id = ? AND user_id = ?'
            );
            $stmt->execute([$id, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Exercise not found or not deletable']);
                return;
            }

            http_response_code(204);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Exercise is referenced in workouts or plans']);
                return;
            }
            $this->handleError('Error deleting gym exercise', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting gym exercise', $e);
        }
    }
}
