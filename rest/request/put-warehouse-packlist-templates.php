<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /warehouse-packlist-templates/{id} — Vorlage aktualisieren.
 *
 * Body: { name?, description?, meta?, groups?[], items?[] }
 * Header wird als Diff aktualisiert; groups/items werden — falls im Body
 * enthalten — komplett neu synchronisiert (delete + re-insert).
 *
 * Response: 200 + Vorlage-Detail.
 *
 * @version 1.0.0
 */
class requestPutWarehousePacklistTemplates extends WarehousePacklistBase {
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

            $templateId = $this->requestId($this->request);
            if ($templateId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Template ID is required']);
                return;
            }

            if (!$this->findTemplate($templateId, $userId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Template not found']);
                return;
            }

            $this->pdo->beginTransaction();

            // Header-Diff
            $updates = [];
            $params = [];
            if (isset($this->data['name'])) {
                $updates[] = 'name = ?';
                $params[] = $this->data['name'];
            }
            if (array_key_exists('description', $this->data)) {
                $updates[] = 'description = ?';
                $params[] = $this->data['description'];
            }
            if (array_key_exists('meta', $this->data)) {
                $updates[] = 'meta = ?';
                $params[] = $this->data['meta'] !== null ? json_encode($this->data['meta']) : null;
            }
            if (!empty($updates)) {
                $params[] = $templateId;
                $params[] = $userId;
                $sql = "UPDATE " . PREFIX . "_warehouse_packlist_templates
                        SET " . implode(', ', $updates) . "
                        WHERE templates_id = ? AND user_id = ?";
                $this->pdo->prepare($sql)->execute($params);
            }

            // Kinder-Sync nur wenn mitgesendet
            if (array_key_exists('groups', $this->data) || array_key_exists('items', $this->data)) {
                $this->syncTemplateChildren(
                    $templateId,
                    $userId,
                    $this->data['groups'] ?? [],
                    $this->data['items'] ?? []
                );
            }

            $this->pdo->commit();

            http_response_code(200);
            echo json_encode($this->buildTemplateDetail($templateId, $userId));
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->handleError('Error updating packlist template', $e);
        }
    }
}
