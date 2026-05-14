<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/plan-assignments — Coach weist einem User einen Plan zu.
 *
 * Body:
 *  {
 *    "assignee_user_id": 42,
 *    "plan_id": 7,
 *    "message": "Push/Pull/Legs für 6 Wochen",   // optional
 *    "start_at": "2026-05-12"                    // optional
 *  }
 *
 * UNIQUE-Konstraint (coach, plan, assignee) → bei Duplikat machen wir ein
 * UPDATE des bestehenden Datensatzes (Status zurück auf 'pending').
 */
class requestPostGymPlanAssignments extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $assigneeId = (int)($this->data['assignee_user_id'] ?? 0);
            $planId     = (int)($this->data['plan_id'] ?? 0);
            if ($assigneeId <= 0 || $planId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'assignee_user_id and plan_id are required']);
                return;
            }

            // Plan muss dem Coach gehören
            $stmt = $this->pdo->prepare(
                'SELECT plans_id FROM mbc_gym_plans WHERE plans_id = ? AND user_id = ?'
            );
            $stmt->execute([$planId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan not found or not yours to assign']);
                return;
            }

            // Assignee muss existieren
            $stmt = $this->pdo->prepare('SELECT users_id FROM mbc_users WHERE users_id = ?');
            $stmt->execute([$assigneeId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Assignee user not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_plan_assignments
                  (assignee_user_id, assigned_by_user_id, plan_id, status, message, start_at)
                 VALUES (?, ?, ?, "pending", ?, ?)
                 ON DUPLICATE KEY UPDATE
                   status = "pending",
                   message = VALUES(message),
                   start_at = VALUES(start_at),
                   assigned_at = CURRENT_TIMESTAMP,
                   responded_at = NULL'
            );
            $stmt->execute([
                $assigneeId,
                $userId,
                $planId,
                $this->data['message']  ?? null,
                $this->data['start_at'] ?? null,
            ]);

            // Bei Update kennt $pdo->lastInsertId() oft 0 — daher per WHERE auflösen
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
                 WHERE a.assigned_by_user_id = ? AND a.plan_id = ? AND a.assignee_user_id = ?'
            );
            $stmt->execute([$userId, $planId, $assigneeId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym plan assignment', $e);
        }
    }
}
