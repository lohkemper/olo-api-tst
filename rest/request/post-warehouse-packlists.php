<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /warehouse-packlists — neue Packliste anlegen (leer oder aus Vorlage).
 *
 * Body:
 *  {
 *    name, template_id?, borrower_name?, borrower_contact?,
 *    borrowed_at?, due_at?, notes?, meta?,
 *    selections?: { "<groups_id>": [itemId, ...] },  // Gruppen-Auswahl (1..n)
 *    quantities?: { "<template_items_id>": qty }      // Mengen-Overrides
 *  }
 *
 * Response: 201 + Packlisten-Detail (inkl. Positionen).
 *
 * @version 1.0.0
 */
class requestPostWarehousePacklists extends WarehousePacklistBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

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

            // Sub-Action: POST /warehouse-packlists/{id}/items — Position hinzufügen
            if (($this->request['subroute'] ?? '') === 'items') {
                $this->handleAddItem($userId);
                return;
            }

            if (empty($this->data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Packlist name is required']);
                return;
            }

            // Optionale Vorlage validieren
            $templateId = null;
            if (!empty($this->data['template_id'])) {
                $templateId = (int)$this->data['template_id'];
                if (!$this->findTemplate($templateId, $userId)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Template not found or access denied']);
                    return;
                }
            }

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                "INSERT INTO " . PREFIX . "_warehouse_packlists
                   (user_id, template_id, name, status, borrower_name, borrower_contact,
                    borrowed_at, due_at, notes, meta)
                 VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $templateId,
                $this->data['name'],
                $this->data['borrower_name'] ?? null,
                $this->data['borrower_contact'] ?? null,
                $this->nullableDate($this->data['borrowed_at'] ?? null),
                $this->nullableDate($this->data['due_at'] ?? null),
                $this->data['notes'] ?? null,
                isset($this->data['meta']) ? json_encode($this->data['meta']) : null,
            ]);
            $packlistId = (int)$this->pdo->lastInsertId();

            if ($templateId !== null) {
                $this->instantiateFromTemplate(
                    $packlistId,
                    $templateId,
                    $userId,
                    is_array($this->data['selections'] ?? null) ? $this->data['selections'] : [],
                    is_array($this->data['quantities'] ?? null) ? $this->data['quantities'] : []
                );
            }

            $this->pdo->commit();

            http_response_code(201);
            echo json_encode($this->buildPacklistDetail($packlistId, $userId));
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->handleError('Error creating packlist', $e);
        }
    }

    /**
     * POST /warehouse-packlists/{id}/items — Position nachträglich hinzufügen.
     * Body: { item_id, quantity?, promote_to_template? }
     */
    private function handleAddItem(int $userId): void {
        $packlistId = $this->requestId($this->request);
        if ($packlistId <= 0 || !$this->findPacklist($packlistId, $userId)) {
            http_response_code(404);
            echo json_encode(['error' => 'Packlist not found']);
            return;
        }

        $itemId = (int)($this->data['item_id'] ?? 0);
        if ($itemId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'item_id is required']);
            return;
        }

        $qty = $this->normalizeQty($this->data['quantity'] ?? 1);
        $promote = !empty($this->data['promote_to_template']);

        $this->pdo->beginTransaction();
        $posId = $this->addPosition($packlistId, $userId, $itemId, $qty, $promote);
        if ($posId === null) {
            $this->pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Item not found or access denied']);
            return;
        }
        $this->pdo->commit();

        http_response_code(201);
        echo json_encode($this->buildPacklistDetail($packlistId, $userId));
    }

    /** Leeres/ungültiges Datum → NULL, sonst YYYY-MM-DD. */
    private function nullableDate(mixed $value): ?string {
        if (!is_string($value) || $value === '') return null;
        return substr($value, 0, 10);
    }
}
