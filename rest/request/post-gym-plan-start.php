<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/plans/{id}/start-day — Startet ein Workout aus einem Plan-Tag.
 *
 * Body:
 *  { "plan_day_id": 7 }
 *
 * Effekt:
 *  - Erstellt ein neues mbc_gym_workouts mit started_at = jetzt, plan_day_id = body
 *  - Übungen aus dem Plan werden NICHT vorab als Sets eingefügt — der User
 *    erfasst sie live beim Tracking. Die Plan-Day-Referenz kann das Frontend
 *    nutzen um Soll-Werte als Vorschläge anzuzeigen.
 *
 * Response: 201 + Workout-Objekt
 */
class requestPostGymPlanStart extends RequestBase {
    private array $request = [];
    private array $data = [];
    private int $planId = 0;

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }
    public function setPlanId(int $planId): void { $this->planId = $planId; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            if ($this->planId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan id required in path']);
                return;
            }

            $planDayId = (int)($this->data['plan_day_id'] ?? 0);
            if ($planDayId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plan_day_id is required']);
                return;
            }

            // Plan-Day-Ownership + Konsistenz mit Plan-ID prüfen
            $stmt = $this->pdo->prepare(
                'SELECT pd.plan_days_id, pd.name AS day_name, p.name AS plan_name
                 FROM mbc_gym_plan_days pd
                 INNER JOIN mbc_gym_plans p ON p.plans_id = pd.plan_id
                 WHERE pd.plan_days_id = ? AND pd.plan_id = ? AND pd.user_id = ?'
            );
            $stmt->execute([$planDayId, $this->planId, $userId]);
            $pd = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pd) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan day not found in this plan']);
                return;
            }

            $workoutName = $pd['plan_name'] . ' — ' . $pd['day_name'];

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_workouts
                   (user_id, plan_day_id, started_at, name)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $planDayId,
                date('Y-m-d H:i:s'),
                $workoutName,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT workouts_id, user_id, plan_day_id, started_at, ended_at, name,
                        notes, body_weight_kg, total_volume_kg, duration_seconds,
                        created_at, updated_at
                 FROM mbc_gym_workouts WHERE workouts_id = ?'
            );
            $stmt->execute([$newId]);

            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error starting workout from plan', $e);
        }
    }
}
