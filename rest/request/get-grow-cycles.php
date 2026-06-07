<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/cycles        — Liste der eigenen Aufzucht-Durchläufe (mit Plant-Count)
 * GET /grow/cycles/{id}   — Durchlauf-Detail inklusive Pflanzen
 */
class requestGetGrowCycles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $cycleId = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($cycleId !== null) {
                $this->getDetail($userId, $cycleId);
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching grow cycles', $e);
        }
    }

    private function getList(int $userId): void {
        $stmt = $this->pdo->prepare(
            'SELECT c.grow_cycles_id, c.user_id, c.name, c.description, c.start_date,
                    c.flowering_weeks, c.expected_harvest_date, c.status,
                    c.phase_config, c.meta, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM mbc_grow_plants p WHERE p.cycle_id = c.grow_cycles_id) AS plant_count
             FROM mbc_grow_cycles c
             WHERE c.user_id = ?
             ORDER BY c.start_date DESC, c.grow_cycles_id DESC'
        );
        $stmt->execute([$userId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getDetail(int $userId, int $cycleId): void {
        $stmt = $this->pdo->prepare(
            'SELECT grow_cycles_id, user_id, name, description, start_date,
                    flowering_weeks, expected_harvest_date, status,
                    phase_config, meta, created_at, updated_at
             FROM mbc_grow_cycles
             WHERE grow_cycles_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$cycleId, $userId]);
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cycle) {
            http_response_code(404);
            echo json_encode(['error' => 'Cycle not found']);
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.grow_plants_id, p.cycle_id, p.user_id, p.label, p.strain,
                    p.seed_item_id, p.planted_date, p.current_phase,
                    p.expected_harvest_date, p.actual_harvest_date, p.status,
                    p.meta, p.created_at, p.updated_at,
                    i.name AS seed_item_name
             FROM mbc_grow_plants p
             LEFT JOIN mbc_warehouse_items i ON i.items_id = p.seed_item_id
             WHERE p.cycle_id = ? AND p.user_id = ?
             ORDER BY p.grow_plants_id ASC'
        );
        $stmt->execute([$cycleId, $userId]);
        $cycle['plants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($cycle);
    }
}
