<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /warehouse-packlist-templates/{id} — Vorlage löschen.
 * Kinder (groups/items) werden per FK CASCADE mitgelöscht. Bereits erstellte
 * Packlisten behalten ihre Positionen (template_id → NULL via SET NULL).
 *
 * @version 1.0.0
 */
class requestDeleteWarehousePacklistTemplates extends WarehousePacklistBase {
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

            $stmt = $this->pdo->prepare(
                "DELETE FROM " . PREFIX . "_warehouse_packlist_templates
                 WHERE templates_id = ? AND user_id = ?"
            );
            $stmt->execute([$templateId, $userId]);

            http_response_code(200);
            echo json_encode(['success' => true, 'templates_id' => $templateId]);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting packlist template', $e);
        }
    }
}
