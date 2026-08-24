<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/blood-values/{id} — Aktualisiert den Wert einer Zeile.
 *
 * Body: { "value": 14.8 }
 */
class requestPutGymBloodValues extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Blood value id required']);
                return;
            }

            $value = $this->data['value'] ?? null;
            if (!is_numeric($value)) {
                http_response_code(400);
                echo json_encode(['error' => 'value (number) is required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'UPDATE mbc_gym_blood_values SET value = ?
                 WHERE blood_values_id = ? AND user_id = ?'
            );
            $stmt->execute([(float)$value, $id, $userId]);

            $stmt = $this->pdo->prepare(
                'SELECT blood_values_id, user_id, measured_at, metric, value,
                        created_at, updated_at
                 FROM mbc_gym_blood_values
                 WHERE blood_values_id = ? AND user_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$id, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Blood value not found']);
                return;
            }

            echo json_encode($row);
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym blood value', $e);
        }
    }
}
