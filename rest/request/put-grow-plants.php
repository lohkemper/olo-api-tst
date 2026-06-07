<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /grow/plants/{id} — Aktualisiert eine Pflanze.
 *
 * Body (alles optional): label, strain, seed_item_id, planted_date,
 *   current_phase, expected_harvest_date, actual_harvest_date, status, meta
 */
class requestPutGrowPlants extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($plantId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plant id required']);
                return;
            }

            // Ownership prüfen
            $stmt = $this->pdo->prepare('SELECT grow_plants_id FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            $allowedPhases = ['germination', 'seedling', 'vegetative', 'flowering', 'harvest', 'cure'];
            $allowedStatus = ['active', 'harvested', 'dead', 'archived'];
            $allowed = ['label', 'strain', 'seed_item_id', 'planted_date', 'current_phase',
                        'expected_harvest_date', 'actual_harvest_date', 'status', 'meta'];
            $sets = [];
            $params = [];

            foreach ($allowed as $f) {
                if (!array_key_exists($f, $this->data)) continue;
                $value = $this->data[$f];

                if ($f === 'current_phase' && !in_array((string)$value, $allowedPhases, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid current_phase']);
                    return;
                }
                if ($f === 'status' && !in_array((string)$value, $allowedStatus, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid status']);
                    return;
                }
                if ($f === 'seed_item_id') {
                    $value = $this->nullableUint($value);
                    if ($value !== null) {
                        $check = $this->pdo->prepare('SELECT items_id FROM mbc_warehouse_items WHERE items_id = ? AND user_id = ?');
                        $check->execute([$value, $userId]);
                        if (!$check->fetch()) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Seed item not found or access denied']);
                            return;
                        }
                    }
                }
                if ($f === 'meta') {
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

            $params[] = $plantId;
            $params[] = $userId;
            $sql = 'UPDATE mbc_grow_plants SET ' . implode(', ', $sets)
                 . ' WHERE grow_plants_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $stmt = $this->pdo->prepare(
                'SELECT p.grow_plants_id, p.cycle_id, p.user_id, p.label, p.strain,
                        p.seed_item_id, p.planted_date, p.current_phase,
                        p.expected_harvest_date, p.actual_harvest_date, p.status,
                        p.meta, p.created_at, p.updated_at,
                        i.name AS seed_item_name
                 FROM mbc_grow_plants p
                 LEFT JOIN mbc_warehouse_items i ON i.items_id = p.seed_item_id
                 WHERE p.grow_plants_id = ?'
            );
            $stmt->execute([$plantId]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating grow plant', $e);
        }
    }

    /** Normalisiert zu positivem INT oder NULL. */
    private function nullableUint(mixed $value): ?int {
        if ($value === null || $value === '' || $value === false) return null;
        if (!is_numeric($value)) return null;
        $i = (int)$value;
        return $i > 0 ? $i : null;
    }
}
