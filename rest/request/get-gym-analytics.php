<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/analytics/volume[?range=4w]
 *   → wöchentliches Volumen pro Muskelgruppe für die letzten N Wochen.
 *   Output: [
 *     { "week_start": "2026-04-13", "muscle_group": "chest", "volume_kg": 1280.0 },
 *     ...
 *   ]
 *
 * GET /gym/analytics/one-rm-history?exercise_id=X[&range=12w]
 *   → 1RM-estimated pro Workout über die letzten N Wochen für die angegebene Übung.
 *   Output: [
 *     { "workout_id": 42, "ended_at": "2026-04-13 19:01:11", "one_rm_kg": 92.4 },
 *     ...
 *   ]
 *
 * GET /gym/analytics/adherence[?range=30d]
 *   → Adherence: aktiver Plan vs. durchgeführte Workouts in den letzten N Tagen.
 *   Output: { "active_plan_id": 7, "active_plan_name": "...", "expected": 12, "completed": 10, "rate": 0.833 }
 *
 * Range-Format: <N><unit> mit unit ∈ {d,w,m}. Beispiele: 4w, 30d, 3m. Default 4w.
 */
class requestGetGymAnalytics extends RequestBase {
    private array $request = [];
    private string $report = '';

    public function setRequest(array $request): void { $this->request = $request; }
    public function setReport(string $report): void { $this->report = $report; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            switch ($this->report) {
                case 'volume':           $this->volume($userId);          return;
                case 'one-rm-history':   $this->oneRmHistory($userId);    return;
                case 'adherence':        $this->adherence($userId);       return;
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Unknown analytics report']);
                    return;
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym analytics', $e);
        }
    }

    /**
     * Wandelt "4w" → 28, "30d" → 30, "3m" → 90. Default 28.
     */
    private function rangeToDays(string $default = '4w'): int {
        $range = (string)($this->request['range'] ?? $default);
        if (preg_match('/^(\d+)([dwm])$/', $range, $m)) {
            $n = (int)$m[1];
            return match ($m[2]) {
                'd' => $n,
                'w' => $n * 7,
                'm' => $n * 30,
                default => 28,
            };
        }
        return 28;
    }

    private function volume(int $userId): void {
        $days = $this->rangeToDays('4w');
        $sinceTs = strtotime("-$days days");
        $since = date('Y-m-d 00:00:00', $sinceTs);

        // Gruppierung nach (ISO-Wochenanfang Mo) × Muskelgruppe.
        // Nur abgeschlossene Sets, die in den Range fallen, non-warmup.
        $stmt = $this->pdo->prepare(
            "SELECT
               DATE_SUB(DATE(ws.performed_at), INTERVAL WEEKDAY(ws.performed_at) DAY) AS week_start,
               COALESCE(c.muscle_group, COALESCE(e.primary_muscle, 'other')) AS muscle_group,
               ROUND(SUM(ws.reps * ws.weight_kg), 2) AS volume_kg
             FROM mbc_gym_workout_sets ws
             INNER JOIN mbc_gym_workouts w  ON w.workouts_id = ws.workout_id AND w.user_id = ws.user_id
             INNER JOIN mbc_gym_exercises e ON e.exercises_id = ws.exercise_id
             LEFT  JOIN mbc_gym_exercise_categories c ON c.exercise_categories_id = e.category_id
             WHERE ws.user_id = ?
               AND ws.is_warmup = 0
               AND ws.reps IS NOT NULL AND ws.weight_kg IS NOT NULL
               AND ws.performed_at >= ?
             GROUP BY week_start, muscle_group
             ORDER BY week_start ASC, muscle_group ASC"
        );
        $stmt->execute([$userId, $since]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function oneRmHistory(int $userId): void {
        $exerciseId = (int)($this->request['exercise_id'] ?? 0);
        if ($exerciseId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'exercise_id is required']);
            return;
        }

        $days = $this->rangeToDays('12w');
        $sinceTs = strtotime("-$days days");
        $since = date('Y-m-d 00:00:00', $sinceTs);

        // Pro abgeschlossenem Workout der höchste 1RM-estimated der Übung.
        $stmt = $this->pdo->prepare(
            "SELECT w.workouts_id AS workout_id,
                    w.ended_at,
                    ROUND(MAX(ws.weight_kg * (1 + ws.reps / 30.0)), 2) AS one_rm_kg
             FROM mbc_gym_workout_sets ws
             INNER JOIN mbc_gym_workouts w ON w.workouts_id = ws.workout_id AND w.user_id = ws.user_id
             WHERE ws.user_id = ?
               AND ws.exercise_id = ?
               AND ws.is_warmup = 0
               AND ws.reps IS NOT NULL AND ws.reps > 0
               AND ws.weight_kg IS NOT NULL
               AND w.ended_at IS NOT NULL
               AND w.ended_at >= ?
             GROUP BY w.workouts_id, w.ended_at
             ORDER BY w.ended_at ASC"
        );
        $stmt->execute([$userId, $exerciseId, $since]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function adherence(int $userId): void {
        $days = $this->rangeToDays('30d');
        $sinceTs = strtotime("-$days days");
        $since = date('Y-m-d 00:00:00', $sinceTs);

        // Aktiver Plan (oder NULL)
        $stmt = $this->pdo->prepare(
            'SELECT plans_id, name, weeks
             FROM mbc_gym_plans
             WHERE user_id = ? AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        // Tage mit Plan
        $expected = 0;
        $planDayCount = 0;
        if ($plan) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS c FROM mbc_gym_plan_days WHERE plan_id = ? AND user_id = ?'
            );
            $stmt->execute([(int)$plan['plans_id'], $userId]);
            $planDayCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
        }

        // Erwartung: Plan-Days × (Tage / 7) als grobe Wochen-Schätzung
        if ($planDayCount > 0) {
            $expected = (int)round($planDayCount * ($days / 7.0));
        }

        // Durchgeführte (abgeschlossene) Workouts im Range
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM mbc_gym_workouts
             WHERE user_id = ? AND ended_at IS NOT NULL AND started_at >= ?'
        );
        $stmt->execute([$userId, $since]);
        $completed = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];

        $rate = $expected > 0 ? round(min(1.0, $completed / $expected), 3) : null;

        echo json_encode([
            'range_days'        => $days,
            'active_plan_id'    => $plan ? (int)$plan['plans_id'] : null,
            'active_plan_name'  => $plan['name'] ?? null,
            'plan_days_per_cycle' => $planDayCount,
            'expected'          => $expected,
            'completed'         => $completed,
            'rate'              => $rate,
        ]);
    }
}
