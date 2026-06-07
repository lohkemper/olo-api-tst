<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/plants?cycle_id={id}  — Pflanzen eines Durchlaufs (eigene)
 * GET /grow/plants/{id}           — Einzelpflanze-Detail
 */
class requestGetGrowPlants extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($plantId !== null) {
                $this->getDetail($userId, $plantId);
            } else {
                $cycleId = isset($this->request['cycle_id']) ? (int)$this->request['cycle_id'] : null;
                $this->getList($userId, $cycleId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching grow plants', $e);
        }
    }

    private function selectColumns(): string {
        return 'p.grow_plants_id, p.cycle_id, p.user_id, p.label, p.strain,
                p.seed_item_id, p.planted_date, p.current_phase,
                p.expected_harvest_date, p.actual_harvest_date, p.status,
                p.meta, p.created_at, p.updated_at,
                i.name AS seed_item_name';
    }

    private function getList(int $userId, ?int $cycleId): void {
        $sql = 'SELECT ' . $this->selectColumns() . '
                FROM mbc_grow_plants p
                LEFT JOIN mbc_warehouse_items i ON i.items_id = p.seed_item_id
                WHERE p.user_id = ?';
        $params = [$userId];
        if ($cycleId !== null) {
            $sql .= ' AND p.cycle_id = ?';
            $params[] = $cycleId;
        }
        $sql .= ' ORDER BY p.grow_plants_id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getDetail(int $userId, int $plantId): void {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->selectColumns() . '
             FROM mbc_grow_plants p
             LEFT JOIN mbc_warehouse_items i ON i.items_id = p.seed_item_id
             WHERE p.grow_plants_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$plantId, $userId]);
        $plant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plant) {
            http_response_code(404);
            echo json_encode(['error' => 'Plant not found']);
            return;
        }

        echo json_encode($plant);
    }
}
