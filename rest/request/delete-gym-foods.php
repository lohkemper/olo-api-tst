<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /gym/foods/{id} — Eigene Lebensmittel löschen.
 *
 * Falls noch nutrition_entries auf dem Food hängen, wirft InnoDB einen
 * RESTRICT-Fehler — wir mappen das auf 409.
 */
class requestDeleteGymFoods extends RequestBase {
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
                echo json_encode(['error' => 'Food id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_gym_foods WHERE foods_id = ? AND user_id = ?'
            );
            $stmt->execute([$id, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Food not found or not deletable']);
                return;
            }

            http_response_code(204);
        } catch (\PDOException $e) {
            // FK-RESTRICT: Lebensmittel ist noch in Mahlzeiten referenziert
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Food is referenced by nutrition entries — entferne diese zuerst']);
                return;
            }
            $this->handleError('Error deleting gym food', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting gym food', $e);
        }
    }
}
