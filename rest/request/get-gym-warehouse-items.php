<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/warehouse-items[?q=suche]
 *   → Liefert die Warehouse-Items des Users als Equipment-Optionen für
 *     Übungen. Nur eigene Items (user_id-Filter wie im Warehouse-Modul üblich).
 *
 * Stellt eine schmale Sicht auf mbc_warehouse_items bereit, damit das Gym-
 * Modul nicht direkt auf die Warehouse-Endpunkte zugreifen muss.
 */
class requestGetGymWarehouseItems extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $sql = 'SELECT items_id, name, description, location_id, image_url
                    FROM mbc_warehouse_items
                    WHERE user_id = ?';
            $params = [$userId];

            if (!empty($this->request['q'])) {
                $sql .= ' AND name LIKE ?';
                $params[] = '%' . $this->request['q'] . '%';
            }

            $sql .= ' ORDER BY name ASC LIMIT 100';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym warehouse items', $e);
        }
    }
}
