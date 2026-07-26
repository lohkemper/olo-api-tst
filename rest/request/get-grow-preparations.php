<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/preparations        — Düngepräparat-Katalog des Users
 * GET /grow/preparations/{id}   — Einzelnes Präparat
 */
class requestGetGrowPreparations extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            // Nutzungs-Zähler: Anzahl distinct Durchläufe, in deren Pflanzen-
            // Schedule dieses Präparat vorkommt.
            $usage = '(SELECT COUNT(DISTINCT pl.cycle_id)
                         FROM mbc_grow_feeding_schedule fs
                         JOIN mbc_grow_plants pl ON pl.grow_plants_id = fs.plant_id
                        WHERE fs.preparation_id = p.grow_preparations_id) AS usage_count';

            $cols = "p.grow_preparations_id, p.user_id, p.name, p.type, p.status, p.color,
                     p.default_unit, p.default_dosage, p.phase, p.ec_contribution,
                     p.brand, p.notes, p.warehouse_item_id, p.meta, p.created_at, p.updated_at,
                     $usage";

            if (isset($this->request['id'])) {
                $id = (int)$this->request['id'];
                $stmt = $this->pdo->prepare(
                    "SELECT $cols FROM mbc_grow_preparations p
                      WHERE p.grow_preparations_id = ? AND p.user_id = ? LIMIT 1"
                );
                $stmt->execute([$id, $userId]);
                $prep = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prep) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Preparation not found']);
                    return;
                }
                echo json_encode($prep);
                return;
            }

            $stmt = $this->pdo->prepare(
                "SELECT $cols FROM mbc_grow_preparations p WHERE p.user_id = ? ORDER BY p.name ASC"
            );
            $stmt->execute([$userId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching grow preparations', $e);
        }
    }
}
