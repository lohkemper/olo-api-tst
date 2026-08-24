<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/blood-values — Panel-Upsert: alle Blutwerte eines Tages auf einmal.
 *
 * Body:
 *  {
 *    "measured_at": "2026-05-04",
 *    "values": {
 *      "hemoglobin": 15.2,      // Zahl  → Upsert der Zeile (UNIQUE user+Tag+metric)
 *      "leukocytes": 6.1,
 *      "tsh": null              // null  → Zeile für diesen Tag löschen
 *    }
 *  }
 *
 * Metric-Keys sind Frontend-Katalog-Keys (blood-metric.catalog.ts); hier wird
 * nur das Format validiert (^[a-z0-9_]{1,40}$), damit neue Katalog-Werte ohne
 * Backend-Deploy funktionieren.
 *
 * Antwort: 201 + alle Zeilen des Tages nach dem Schreiben (kann leer sein).
 */
class requestPostGymBloodValues extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $measuredAt = trim((string)($this->data['measured_at'] ?? ''));
            if ($measuredAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
                http_response_code(400);
                echo json_encode(['error' => 'measured_at (YYYY-MM-DD) is required']);
                return;
            }

            $values = $this->data['values'] ?? null;
            if (!is_array($values)) {
                http_response_code(400);
                echo json_encode(['error' => 'values (object of metric => number|null) is required']);
                return;
            }

            $upserts = [];
            $deletes = [];
            foreach ($values as $metric => $value) {
                if (!is_string($metric) || !preg_match('/^[a-z0-9_]{1,40}$/', $metric)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid metric key: ' . (string)$metric]);
                    return;
                }
                if ($value === null || $value === '') {
                    $deletes[] = $metric;
                } elseif (is_numeric($value)) {
                    $upserts[$metric] = (float)$value;
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Value for ' . $metric . ' must be a number or null']);
                    return;
                }
            }

            $this->pdo->beginTransaction();

            if (!empty($upserts)) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO mbc_gym_blood_values (user_id, measured_at, metric, value)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value = VALUES(value)'
                );
                foreach ($upserts as $metric => $value) {
                    $stmt->execute([$userId, $measuredAt, $metric, $value]);
                }
            }

            if (!empty($deletes)) {
                $placeholders = implode(',', array_fill(0, count($deletes), '?'));
                $stmt = $this->pdo->prepare(
                    'DELETE FROM mbc_gym_blood_values
                     WHERE user_id = ? AND measured_at = ? AND metric IN (' . $placeholders . ')'
                );
                $stmt->execute(array_merge([$userId, $measuredAt], $deletes));
            }

            $this->pdo->commit();

            $stmt = $this->pdo->prepare(
                'SELECT blood_values_id, user_id, measured_at, metric, value,
                        created_at, updated_at
                 FROM mbc_gym_blood_values
                 WHERE user_id = ? AND measured_at = ?
                 ORDER BY metric ASC'
            );
            $stmt->execute([$userId, $measuredAt]);
            http_response_code(201);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error saving gym blood values', $e);
        }
    }
}
