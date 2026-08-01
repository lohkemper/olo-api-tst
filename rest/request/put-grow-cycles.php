<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /grow/cycles/{id} — Aktualisiert Metadaten eines Durchlaufs.
 *
 * Body (alles optional): name, description, start_date, flowering_weeks,
 *                        expected_harvest_date, status, phase_config, meta
 */
class requestPutGrowCycles extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $cycleId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($cycleId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cycle id required']);
                return;
            }

            // Ownership prüfen
            $stmt = $this->pdo->prepare('SELECT grow_cycles_id FROM mbc_grow_cycles WHERE grow_cycles_id = ? AND user_id = ?');
            $stmt->execute([$cycleId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Cycle not found']);
                return;
            }

            $allowedStatus = ['planned', 'active', 'harvested', 'archived'];
            $allowed = ['name', 'description', 'start_date', 'flowering_weeks',
                        'expected_harvest_date', 'status', 'phase_config', 'meta'];
            $jsonFields = ['phase_config', 'meta'];
            $sets = [];
            $params = [];

            foreach ($allowed as $f) {
                if (!array_key_exists($f, $this->data)) continue;
                $value = $this->data[$f];

                if ($f === 'status' && !in_array((string)$value, $allowedStatus, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid status']);
                    return;
                }
                if ($f === 'flowering_weeks') {
                    $value = (int)$value;
                    if ($value < 1 || $value > 52) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid flowering_weeks']);
                        return;
                    }
                }
                if (in_array($f, $jsonFields, true)) {
                    $value = $value === null ? null : json_encode($value);
                }

                $sets[] = "$f = ?";
                $params[] = $value;
            }

            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $params[] = $cycleId;
            $params[] = $userId;
            $sql = 'UPDATE mbc_grow_cycles SET ' . implode(', ', $sets)
                 . ' WHERE grow_cycles_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $stmt = $this->pdo->prepare(
                'SELECT grow_cycles_id, user_id, name, description, start_date,
                        flowering_weeks, expected_harvest_date, status,
                        phase_config, meta, created_at, updated_at
                 FROM mbc_grow_cycles WHERE grow_cycles_id = ?'
            );
            $stmt->execute([$cycleId]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating grow cycle', $e);
        }
    }
}
