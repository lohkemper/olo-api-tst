<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /warehouse-packlist-templates — neue Packlisten-Vorlage anlegen.
 *
 * Body: { name, description?, meta?, groups?[], items?[] }
 * groups/items werden (falls mitgesendet) direkt synchronisiert.
 *
 * Response: 201 + Vorlage-Detail (inkl. groups + items).
 *
 * @version 1.0.0
 */
class requestPostWarehousePacklistTemplates extends WarehousePacklistBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
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

            if (empty($this->data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Template name is required']);
                return;
            }

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                "INSERT INTO " . PREFIX . "_warehouse_packlist_templates
                   (user_id, name, description, meta)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $this->data['name'],
                $this->data['description'] ?? null,
                isset($this->data['meta']) ? json_encode($this->data['meta']) : null,
            ]);
            $templateId = (int)$this->pdo->lastInsertId();

            $this->syncTemplateChildren(
                $templateId,
                $userId,
                $this->data['groups'] ?? null,
                $this->data['items'] ?? null
            );

            $this->pdo->commit();

            http_response_code(201);
            echo json_encode($this->buildTemplateDetail($templateId, $userId));
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->handleError('Error creating packlist template', $e);
        }
    }
}
