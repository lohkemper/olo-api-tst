<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/plan-assignments/{id} — Reagiert auf eine Zuweisung.
 *
 * Body:
 *  { "status": "accepted" | "declined" | "completed" }
 *
 * Berechtigung:
 *  - assignee darf accepted/declined/completed setzen
 *  - coach   darf completed setzen
 */
class requestPutGymPlanAssignments extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan-assignment id required']);
                return;
            }

            $allowedStatus = ['accepted','declined','completed','pending'];
            $newStatus = (string)($this->data['status'] ?? '');
            if (!in_array($newStatus, $allowedStatus, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status']);
                return;
            }

            // Existing laden für Berechtigungs-Check
            $stmt = $this->pdo->prepare(
                'SELECT assignee_user_id, assigned_by_user_id, status
                 FROM mbc_gym_plan_assignments WHERE plan_assignments_id = ?'
            );
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan assignment not found']);
                return;
            }

            $isAssignee = (int)$existing['assignee_user_id'] === $userId;
            $isCoach    = (int)$existing['assigned_by_user_id'] === $userId;

            if (!$isAssignee && !$isCoach) {
                http_response_code(403);
                echo json_encode(['error' => 'Not your assignment']);
                return;
            }

            // Coach darf nur completed setzen, Assignee alles
            if (!$isAssignee && $isCoach && !in_array($newStatus, ['completed'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Coach may only mark as completed']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'UPDATE mbc_gym_plan_assignments
                 SET status = ?, responded_at = CURRENT_TIMESTAMP
                 WHERE plan_assignments_id = ?'
            );
            $stmt->execute([$newStatus, $id]);

            $stmt = $this->pdo->prepare(
                'SELECT a.plan_assignments_id, a.assignee_user_id, a.assigned_by_user_id,
                        a.plan_id, a.status, a.message, a.start_at,
                        a.assigned_at, a.responded_at, a.created_at, a.updated_at,
                        p.name AS plan_name, p.goal AS plan_goal,
                        coach.username AS coach_username,
                        assignee.username AS assignee_username
                 FROM mbc_gym_plan_assignments a
                 INNER JOIN mbc_gym_plans p ON p.plans_id = a.plan_id
                 INNER JOIN mbc_users coach    ON coach.users_id    = a.assigned_by_user_id
                 INNER JOIN mbc_users assignee ON assignee.users_id = a.assignee_user_id
                 WHERE a.plan_assignments_id = ?'
            );
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym plan assignment', $e);
        }
    }
}
