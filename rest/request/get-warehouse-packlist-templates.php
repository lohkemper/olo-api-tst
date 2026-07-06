<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for warehouse-packlist-templates endpoint
 * Handles:
 * - GET /warehouse-packlist-templates          — Liste (mit Positions-Count)
 * - GET /warehouse-packlist-templates/{id}      — Detail inkl. groups + items
 *
 * Positionen werden mit Live-Artikeldaten (Name, Einheit, Bestand, verfügbar)
 * angereichert. Verfügbar = quantity − aktive Reservierungen (Ledger).
 *
 * @version 1.0.0
 */
class requestGetWarehousePacklistTemplates extends WarehousePacklistBase {
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
            $this->handleError('Error fetching packlist templates', $e);
        }
    }

    private function getList(int $userId): void {
        $sql = "
            SELECT t.templates_id, t.user_id, t.name, t.description, t.meta,
                   t.created_at, t.updated_at,
                   (SELECT COUNT(*) FROM " . PREFIX . "_warehouse_packlist_template_items ti
                      WHERE ti.template_id = t.templates_id) AS item_count,
                   (SELECT COUNT(*) FROM " . PREFIX . "_warehouse_packlist_template_groups tg
                      WHERE tg.template_id = t.templates_id) AS group_count
            FROM " . PREFIX . "_warehouse_packlist_templates t
            WHERE t.user_id = ?
            ORDER BY t.name ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);

        http_response_code(200);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getDetail(int $userId, int $templateId): void {
        $template = $this->buildTemplateDetail($templateId, $userId);
        if (!$template) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            return;
        }

        http_response_code(200);
        echo json_encode($template);
    }
}
