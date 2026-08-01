<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/gcal-sync — synchronisiert die Dünge-Termine einer Pflanze in den
 * Google-Kalender des Users (Anforderung A5, Event-Push).
 *
 * Body: { "plant_id": 5 }
 *
 * Ablauf:
 *  1. Schedule-Einträge der Pflanze laden, datierte Termine generieren
 *     (Anker = planted_date | cycle.start_date, Horizont = Plan-Gesamtdauer).
 *  2. Termine idempotent in mbc_grow_feeding_events upserten
 *     (Dedup über plant_id + due_date + preparation_id).
 *  3. Noch nicht synchronisierte Termine als Ganztages-Events anlegen und
 *     calendar_event_id zurückschreiben.
 *
 * Idempotent: erneuter Aufruf legt keine Duplikate an.
 */
class requestPostGrowGcalSync extends RequestBase {
    private array $data = [];

    private const DEFAULT_PHASE_DAYS = [
        'germination' => 7, 'seedling' => 10, 'vegetative' => 21, 'harvest' => 1, 'cure' => 14,
    ];
    private const MAX_EVENTS = 200;

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = (int)($this->data['plant_id'] ?? 0);
            if ($plantId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plant_id is required']);
                return;
            }

            // Pflanze + zugehöriger Cycle (Ownership)
            $stmt = $this->pdo->prepare(
                'SELECT p.grow_plants_id, p.label, p.planted_date,
                        c.grow_cycles_id, c.name AS cycle_name, c.start_date, c.flowering_weeks
                 FROM mbc_grow_plants p
                 INNER JOIN mbc_grow_cycles c ON c.grow_cycles_id = p.cycle_id
                 WHERE p.grow_plants_id = ? AND p.user_id = ? LIMIT 1'
            );
            $stmt->execute([$plantId, $userId]);
            $plant = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$plant) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            // Google-Verbindung + Ziel-Kalender
            $accessToken = GoogleOAuth::validAccessTokenForUser($this->pdo, $userId);
            if ($accessToken === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Google Calendar not connected']);
                return;
            }
            $calStmt = $this->pdo->prepare('SELECT gcal_calendar_id FROM mbc_user_settings WHERE user_id = ?');
            $calStmt->execute([$userId]);
            $calendarId = (string)($calStmt->fetchColumn() ?: 'primary');

            // Schedule-Einträge
            $stmt = $this->pdo->prepare(
                'SELECT s.preparation_id, s.phase, s.interval_days, s.dosage, s.dosage_unit,
                        s.start_offset_days, s.end_offset_days, p.name AS preparation_name
                 FROM mbc_grow_feeding_schedule s
                 INNER JOIN mbc_grow_preparations p ON p.grow_preparations_id = s.preparation_id
                 WHERE s.plant_id = ?'
            );
            $stmt->execute([$plantId]);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($entries)) {
                echo json_encode(['created' => 0, 'synced' => 0, 'skipped' => 0, 'message' => 'No schedule entries']);
                return;
            }

            $anchor = !empty($plant['planted_date']) ? (string)$plant['planted_date'] : (string)$plant['start_date'];
            $horizon = $this->horizonDays((int)$plant['flowering_weeks']);

            $rows = $this->generateRows($entries, $anchor, $horizon);

            $created = 0; $synced = 0; $skipped = 0;
            $findStmt = $this->pdo->prepare(
                'SELECT grow_feeding_events_id, calendar_event_id FROM mbc_grow_feeding_events
                 WHERE plant_id = ? AND due_date = ? AND preparation_id = ? LIMIT 1'
            );
            $insStmt = $this->pdo->prepare(
                'INSERT INTO mbc_grow_feeding_events
                   (plant_id, preparation_id, due_date, dosage, dosage_unit, status)
                 VALUES (?, ?, ?, ?, ?, "pending")'
            );
            $updCalStmt = $this->pdo->prepare(
                'UPDATE mbc_grow_feeding_events SET calendar_event_id = ? WHERE grow_feeding_events_id = ?'
            );

            foreach ($rows as $row) {
                $findStmt->execute([$plantId, $row['date'], $row['preparation_id']]);
                $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $eventRowId = (int)$existing['grow_feeding_events_id'];
                    if (!empty($existing['calendar_event_id'])) {
                        $skipped++;
                        continue; // bereits synchronisiert
                    }
                } else {
                    $insStmt->execute([
                        $plantId, $row['preparation_id'], $row['date'], $row['dosage'], $row['dosage_unit'],
                    ]);
                    $eventRowId = (int)$this->pdo->lastInsertId();
                    $created++;
                }

                // Kalender-Event anlegen
                $summary = '💧 ' . $row['preparation_name'] . ' ' . rtrim(rtrim((string)$row['dosage'], '0'), '.')
                    . ' ' . $row['dosage_unit'] . ' — ' . $plant['label'];
                $description = 'Grow: ' . $plant['cycle_name'] . ' / ' . $plant['label']
                    . ($row['phase'] ? ' (' . $row['phase'] . ')' : '');

                $eventId = GoogleOAuth::createAllDayEvent($accessToken, $calendarId, [
                    'summary' => $summary,
                    'description' => $description,
                    'date' => $row['date'],
                ]);
                if ($eventId !== null) {
                    $updCalStmt->execute([$eventId, $eventRowId]);
                    $synced++;
                }
            }

            echo json_encode(['created' => $created, 'synced' => $synced, 'skipped' => $skipped]);
        } catch (\Throwable $e) {
            $this->handleError('Error syncing feeding events to Google Calendar', $e);
        }
    }

    private function horizonDays(int $floweringWeeks): int {
        $d = self::DEFAULT_PHASE_DAYS;
        return $d['germination'] + $d['seedling'] + $d['vegetative']
            + max(1, $floweringWeeks) * 7 + $d['harvest'] + $d['cure'];
    }

    /**
     * Erzeugt datierte Dünge-Zeilen aus den Schedule-Einträgen (analog
     * feeding-plan.util.buildDosageRows im Frontend).
     */
    private function generateRows(array $entries, string $anchor, int $horizon): array {
        $rows = [];
        foreach ($entries as $e) {
            $interval = max(1, (int)$e['interval_days']);
            $start = max(0, (int)$e['start_offset_days']);
            $end = ($e['end_offset_days'] === null || $e['end_offset_days'] === '')
                ? $horizon : (int)$e['end_offset_days'];

            for ($day = $start; $day <= $end; $day += $interval) {
                if (count($rows) >= self::MAX_EVENTS) {
                    break 2;
                }
                $rows[] = [
                    'date' => date('Y-m-d', strtotime($anchor . ' +' . $day . ' days')),
                    'preparation_id' => (int)$e['preparation_id'],
                    'preparation_name' => (string)$e['preparation_name'],
                    'phase' => $e['phase'],
                    'dosage' => $e['dosage'],
                    'dosage_unit' => (string)$e['dosage_unit'],
                ];
            }
        }
        return $rows;
    }
}
