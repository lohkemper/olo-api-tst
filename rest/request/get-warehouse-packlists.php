<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for warehouse-packlists endpoint
 * Handles:
 * - GET /warehouse-packlists          — Liste (mit Positions-/Status-Counts)
 * - GET /warehouse-packlists/{id}      — Detail inkl. Positionen + Lagerorte
 *
 * @version 1.0.0
 */
class requestGetWarehousePacklists extends WarehousePacklistBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
            $userId = (int)$user['users_id'];

            if (isset($this->request['id'])) {
                $this->getDetail($userId, $this->requestId($this->request));
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching packlists', $e);
        }
    }

    private function getList(int $userId): void {
        $sql = "
            SELECT p.packlists_id, p.user_id, p.template_id, p.name, p.status,
                   p.borrower_name, p.borrower_contact, p.borrowed_at, p.due_at, p.returned_at,
                   p.notes, p.created_at, p.updated_at,
                   (SELECT COUNT(*) FROM " . PREFIX . "_warehouse_packlist_items x
                      WHERE x.packlist_id = p.packlists_id) AS position_count,
                   (SELECT COUNT(*) FROM " . PREFIX . "_warehouse_packlist_items x
                      WHERE x.packlist_id = p.packlists_id AND x.checked_out = 1) AS checked_out_count,
                   (SELECT COUNT(*) FROM " . PREFIX . "_warehouse_packlist_items x
                      WHERE x.packlist_id = p.packlists_id AND x.returned = 1) AS returned_count
            FROM " . PREFIX . "_warehouse_packlists p
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC, p.packlists_id DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);

        http_response_code(200);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getDetail(int $userId, int $packlistId): void {
        $packlist = $this->buildPacklistDetail($packlistId, $userId);
        if (!$packlist) {
            http_response_code(404);
            echo json_encode(['error' => 'Packlist not found']);
            return;
        }

        http_response_code(200);
        echo json_encode($packlist);
    }
}
