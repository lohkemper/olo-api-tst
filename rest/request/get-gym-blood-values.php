<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/blood-values                — Liste (Tag DESC, Metric ASC)
 * GET /gym/blood-values/{id}           — einzelne Zeile
 * GET /gym/blood-values?from=YYYY-MM-DD&to=YYYY-MM-DD&metric=key — filtern
 *
 * Key-Value-Zeitreihe: eine Zeile pro (User, Tag, Messwert-Key). Der Katalog
 * der Keys lebt im Frontend (blood-metric.catalog.ts) — das Backend validiert
 * nur das Format, nicht die Bedeutung.
 */
class requestGetGymBloodValues extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($id !== null) {
                $this->getById($userId, $id);
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym blood values', $e);
        }
    }

    private function getList(int $userId): void {
        $sql = 'SELECT blood_values_id, user_id, measured_at, metric, value,
                       created_at, updated_at
                FROM mbc_gym_blood_values
                WHERE user_id = ?';
        $params = [$userId];

        if (!empty($this->request['from'])) {
            $sql .= ' AND measured_at >= ?';
            $params[] = $this->request['from'];
        }
        if (!empty($this->request['to'])) {
            $sql .= ' AND measured_at <= ?';
            $params[] = $this->request['to'];
        }
        if (!empty($this->request['metric'])) {
            $sql .= ' AND metric = ?';
            $params[] = $this->request['metric'];
        }

        $sql .= ' ORDER BY measured_at DESC, metric ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getById(int $userId, int $id): void {
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
    }
}
