<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/workouts/{id} — Aktualisiert ein Workout.
 *
 * Wenn `ended_at` von NULL auf einen Wert gesetzt wird, berechnet der Server
 * automatisch `total_volume_kg` und `duration_seconds`. Das ist der "End"-
 * Trigger — keine separate /end-Aktion nötig.
 *
 * Body (alle Felder optional):
 *  {
 *    "ended_at": "2026-05-03 19:42:11",
 *    "name": "Push Day A (umbenannt)",
 *    "notes": "...",
 *    "body_weight_kg": 82.7
 *  }
 */
class requestPutGymWorkouts extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $workoutId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($workoutId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Workout id required']);
                return;
            }

            // Bestehender Datensatz holen (für Ownership + End-Trigger-Erkennung)
            $stmt = $this->pdo->prepare(
                'SELECT workouts_id, started_at, ended_at FROM mbc_gym_workouts
                 WHERE workouts_id = ? AND user_id = ?'
            );
            $stmt->execute([$workoutId, $userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Workout not found']);
                return;
            }

            $allowedFields = ['ended_at','name','notes','body_weight_kg','plan_day_id','started_at'];
            $sets = [];
            $params = [];
            foreach ($allowedFields as $f) {
                if (array_key_exists($f, $this->data)) {
                    $sets[] = "$f = ?";
                    $params[] = $this->data[$f];
                }
            }

            // End-Trigger: ended_at wechselt von NULL → not NULL → Volumen + Dauer berechnen
            $isEnding = $existing['ended_at'] === null
                && array_key_exists('ended_at', $this->data)
                && !empty($this->data['ended_at']);

            if ($isEnding) {
                // Volumen aller non-warmup-Sets
                $vstmt = $this->pdo->prepare(
                    'SELECT COALESCE(SUM(reps * weight_kg), 0) AS total_volume_kg
                     FROM mbc_gym_workout_sets
                     WHERE workout_id = ? AND is_warmup = 0
                       AND reps IS NOT NULL AND weight_kg IS NOT NULL'
                );
                $vstmt->execute([$workoutId]);
                $totalVolume = (float)$vstmt->fetch(PDO::FETCH_ASSOC)['total_volume_kg'];

                $startTs = strtotime($existing['started_at']);
                $endTs   = strtotime($this->data['ended_at']);
                $duration = max(0, $endTs - $startTs);

                $sets[] = 'total_volume_kg = ?';
                $params[] = $totalVolume;
                $sets[] = 'duration_seconds = ?';
                $params[] = $duration;
            }

            // (Personal-Records-Recompute kommt nach dem UPDATE — siehe unten.)

            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $params[] = $workoutId;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_workouts SET ' . implode(', ', $sets)
                 . ' WHERE workouts_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            // Beim Beenden: Personal Records pro Übung neu berechnen
            if ($isEnding) {
                $this->recomputePersonalRecords($workoutId, $userId, $this->data['ended_at']);
            }

            $stmt = $this->pdo->prepare(
                'SELECT workouts_id, user_id, plan_day_id, started_at, ended_at, name,
                        notes, body_weight_kg, total_volume_kg, duration_seconds,
                        created_at, updated_at
                 FROM mbc_gym_workouts WHERE workouts_id = ?'
            );
            $stmt->execute([$workoutId]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym workout', $e);
        }
    }

    /**
     * Berechnet PRs für jede Übung im abgeschlossenen Workout neu und upserted
     * sie in mbc_gym_personal_records. Nur non-warmup-Sets fließen ein.
     *
     * Record-Typen:
     *  - 1rm_estimated  : Epley-Formel max(weight × (1 + reps/30))
     *  - volume_set     : max(reps × weight) eines einzelnen Sets
     *  - volume_workout : Summe(reps × weight) pro Übung in DIESEM Workout
     *  - reps_at_weight : max reps bei einem konkreten Gewicht (pro Gewicht eigener Datensatz)
     *  - max_distance   : max distance_m
     *  - max_time       : max time_seconds
     *
     * Nur "echte" Verbesserungen werden persistiert: ON DUPLICATE KEY UPDATE
     * vergleicht den neuen Wert mit dem alten und ersetzt ihn nur wenn größer.
     */
    private function recomputePersonalRecords(int $workoutId, int $userId, string $achievedAt): void {
        // Alle non-warmup-Sets dieses Workouts laden
        $stmt = $this->pdo->prepare(
            'SELECT workout_sets_id, exercise_id, reps, weight_kg, time_seconds, distance_m
             FROM mbc_gym_workout_sets
             WHERE workout_id = ? AND user_id = ? AND is_warmup = 0'
        );
        $stmt->execute([$workoutId, $userId]);
        $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sets)) return;

        // Pro Übung aggregieren
        /** @var array<int, array<string, mixed>> $perExercise */
        $perExercise = [];
        foreach ($sets as $s) {
            $eid = (int)$s['exercise_id'];
            if (!isset($perExercise[$eid])) {
                $perExercise[$eid] = [
                    'best1rm'        => null, // ['value' => ..., 'set_id' => ...]
                    'bestVolumeSet'  => null,
                    'volumeWorkout'  => 0.0,
                    'maxDistance'    => null,
                    'maxTime'        => null,
                    'repsByWeight'   => [], // weight_kg => ['reps' => max, 'set_id' => …]
                ];
            }
            $row =& $perExercise[$eid];

            $reps   = $s['reps']         !== null ? (int)$s['reps']           : null;
            $weight = $s['weight_kg']    !== null ? (float)$s['weight_kg']    : null;
            $tsec   = $s['time_seconds'] !== null ? (int)$s['time_seconds']   : null;
            $dist   = $s['distance_m']   !== null ? (float)$s['distance_m']   : null;
            $sid    = (int)$s['workout_sets_id'];

            // 1RM-estimated + volume_set + reps_at_weight (alle erfordern reps + weight)
            if ($reps !== null && $weight !== null && $reps > 0) {
                $oneRm = $weight * (1 + $reps / 30.0);
                if ($row['best1rm'] === null || $oneRm > $row['best1rm']['value']) {
                    $row['best1rm'] = ['value' => $oneRm, 'set_id' => $sid];
                }

                $volSet = $reps * $weight;
                if ($row['bestVolumeSet'] === null || $volSet > $row['bestVolumeSet']['value']) {
                    $row['bestVolumeSet'] = ['value' => $volSet, 'set_id' => $sid];
                }
                $row['volumeWorkout'] += $volSet;

                $weightKey = number_format($weight, 2, '.', '');
                $cur = $row['repsByWeight'][$weightKey] ?? null;
                if ($cur === null || $reps > $cur['reps']) {
                    $row['repsByWeight'][$weightKey] = ['reps' => $reps, 'set_id' => $sid];
                }
            }

            // distance / time
            if ($dist !== null && ($row['maxDistance'] === null || $dist > $row['maxDistance']['value'])) {
                $row['maxDistance'] = ['value' => $dist, 'set_id' => $sid];
            }
            if ($tsec !== null && ($row['maxTime'] === null || $tsec > $row['maxTime']['value'])) {
                $row['maxTime'] = ['value' => $tsec, 'set_id' => $sid];
            }
            unset($row);
        }

        // Upsert: Wert wird nur überschrieben wenn neuer Wert größer als alter ist.
        // Wir nutzen ON DUPLICATE KEY UPDATE mit GREATEST() — dabei wird workout_set_id
        // nur dann mitgeschrieben wenn der neue Wert wirklich gewinnt; CASE entscheidet.
        $upsert = $this->pdo->prepare(
            'INSERT INTO mbc_gym_personal_records
               (user_id, exercise_id, record_type, value, reference_weight_kg,
                workout_set_id, workout_id, achieved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               value = GREATEST(value, VALUES(value)),
               workout_set_id = IF(VALUES(value) > value, VALUES(workout_set_id), workout_set_id),
               workout_id     = IF(VALUES(value) > value, VALUES(workout_id),     workout_id),
               achieved_at    = IF(VALUES(value) > value, VALUES(achieved_at),    achieved_at)'
        );

        foreach ($perExercise as $eid => $row) {
            if ($row['best1rm']) {
                $upsert->execute([
                    $userId, $eid, '1rm_estimated', $row['best1rm']['value'], null,
                    $row['best1rm']['set_id'], $workoutId, $achievedAt,
                ]);
            }
            if ($row['bestVolumeSet']) {
                $upsert->execute([
                    $userId, $eid, 'volume_set', $row['bestVolumeSet']['value'], null,
                    $row['bestVolumeSet']['set_id'], $workoutId, $achievedAt,
                ]);
            }
            if ($row['volumeWorkout'] > 0) {
                $upsert->execute([
                    $userId, $eid, 'volume_workout', $row['volumeWorkout'], null,
                    null, $workoutId, $achievedAt,
                ]);
            }
            if ($row['maxDistance']) {
                $upsert->execute([
                    $userId, $eid, 'max_distance', $row['maxDistance']['value'], null,
                    $row['maxDistance']['set_id'], $workoutId, $achievedAt,
                ]);
            }
            if ($row['maxTime']) {
                $upsert->execute([
                    $userId, $eid, 'max_time', $row['maxTime']['value'], null,
                    $row['maxTime']['set_id'], $workoutId, $achievedAt,
                ]);
            }
            foreach ($row['repsByWeight'] as $weightKey => $rec) {
                $upsert->execute([
                    $userId, $eid, 'reps_at_weight', $rec['reps'], (float)$weightKey,
                    $rec['set_id'], $workoutId, $achievedAt,
                ]);
            }
        }
    }
}
