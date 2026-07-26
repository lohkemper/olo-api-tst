<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /grow/preparations/{id} — Aktualisiert ein Präparat.
 * Body (optional): name, type, default_unit, warehouse_item_id, meta
 */
class requestPutGrowPreparations extends RequestBase {
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
                echo json_encode(['error' => 'Preparation id required']);
                return;
            }

            $stmt = $this->pdo->prepare('SELECT grow_preparations_id FROM mbc_grow_preparations WHERE grow_preparations_id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Preparation not found']);
                return;
            }

            $allowedTypes = ['base', 'grow', 'bloom', 'additive', 'booster', 'flush'];
            $allowedPhases = ['any', 'germination', 'seedling', 'vegetative', 'flowering', 'harvest', 'cure'];
            $allowed = [
                'name', 'type', 'status', 'color', 'default_unit', 'default_dosage',
                'phase', 'ec_contribution', 'brand', 'notes', 'warehouse_item_id', 'meta',
            ];
            $sets = [];
            $params = [];

            foreach ($allowed as $f) {
                if (!array_key_exists($f, $this->data)) continue;
                $value = $this->data[$f];
                if ($f === 'type' && !in_array((string)$value, $allowedTypes, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid type']);
                    return;
                }
                if ($f === 'status') {
                    $value = ((string)$value) === 'inactive' ? 'inactive' : 'active';
                }
                if ($f === 'phase' && !in_array((string)$value, $allowedPhases, true)) {
                    $value = 'any';
                }
                if ($f === 'default_dosage' || $f === 'ec_contribution') {
                    $value = is_numeric($value) ? (float)$value : 0;
                }
                if ($f === 'color' || $f === 'brand' || $f === 'notes') {
                    $value = ($value === null || $value === '') ? null : (string)$value;
                }
                if ($f === 'warehouse_item_id') {
                    $value = ($value === null || $value === '' || !is_numeric($value) || (int)$value <= 0) ? null : (int)$value;
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

            $params[] = $id;
            $params[] = $userId;
            $stmt = $this->pdo->prepare(
                'UPDATE mbc_grow_preparations SET ' . implode(', ', $sets) . ' WHERE grow_preparations_id = ? AND user_id = ?'
            );
            $stmt->execute($params);

            $stmt = $this->pdo->prepare(
                'SELECT grow_preparations_id, user_id, name, type, status, color, default_unit,
                        default_dosage, phase, ec_contribution, brand, notes, warehouse_item_id,
                        meta, created_at, updated_at
                 FROM mbc_grow_preparations WHERE grow_preparations_id = ?'
            );
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating grow preparation', $e);
        }
    }
}
