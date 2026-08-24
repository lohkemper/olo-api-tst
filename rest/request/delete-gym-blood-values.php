<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /gym/blood-values/{id}                  — Löscht eine Zeile.
 * DELETE /gym/blood-values?measured_at=YYYY-MM-DD — Löscht alle Werte eines Tages.
 */
class requestDeleteGymBloodValues extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $measuredAt = trim((string)($this->request['measured_at'] ?? ''));
            if ($measuredAt !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'measured_at must be YYYY-MM-DD']);
                    return;
                }
                $stmt = $this->pdo->prepare(
                    'DELETE FROM mbc_gym_blood_values
                     WHERE user_id = ? AND measured_at = ?'
                );
                $stmt->execute([$userId, $measuredAt]);

                if ($stmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode(['error' => 'No blood values found for this day']);
                    return;
                }

                http_response_code(204);
                return;
            }

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Blood value id or measured_at required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_gym_blood_values
                 WHERE blood_values_id = ? AND user_id = ?'
            );
            $stmt->execute([$id, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Blood value not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting gym blood values', $e);
        }
    }
}
