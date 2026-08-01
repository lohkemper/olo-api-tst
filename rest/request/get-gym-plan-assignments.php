<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/plan-assignments               — Mir zugewiesen (assignee_user_id = current)
 * GET /gym/plan-assignments?as=coach      — Von mir zugewiesen (assigned_by_user_id = current)
 *
 * Liefert je Eintrag den Plan-Namen + Coach-Username + Assignee-Username
 * gejoined, damit das Frontend ohne extra Lookups arbeiten kann.
 */
class requestGetGymPlanAssignments extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $asCoach = (string)($this->request['as'] ?? '') === 'coach';

            $sql = 'SELECT a.plan_assignments_id, a.assignee_user_id, a.assigned_by_user_id,
                           a.plan_id, a.status, a.message, a.start_at,
                           a.assigned_at, a.responded_at, a.created_at, a.updated_at,
                           p.name AS plan_name,
                           p.goal AS plan_goal,
                           coach.username AS coach_username,
                           assignee.username AS assignee_username
                    FROM mbc_gym_plan_assignments a
                    INNER JOIN mbc_gym_plans p ON p.plans_id = a.plan_id
                    INNER JOIN mbc_users coach    ON coach.users_id    = a.assigned_by_user_id
                    INNER JOIN mbc_users assignee ON assignee.users_id = a.assignee_user_id
                    WHERE ' . ($asCoach ? 'a.assigned_by_user_id = ?' : 'a.assignee_user_id = ?') . '
                    ORDER BY a.assigned_at DESC';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym plan assignments', $e);
        }
    }
}
