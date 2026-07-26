<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/feeding-upcoming?days=14 — kommende Dünge-Termine über alle aktiven
 * Durchläufe des Users (Kalender-Vorschau, Anforderung A5).
 *
 * Generiert die Termine serverseitig aus dem Feeding-Schedule (analog
 * post-grow-gcal-sync.php), filtert auf das Fenster [heute, heute+days] und
 * liefert eine flache, nach Datum sortierte Liste.
 */
class requestGetGrowFeedingUpcoming extends RequestBase {
    private array $request = [];

    private const DEFAULT_PHASE_DAYS = [
        'germination' => 7, 'seedling' => 10, 'vegetative' => 21, 'harvest' => 1, 'cure' => 14,
    ];
    private const MAX_EVENTS = 200;

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $days = isset($this->request['days']) ? (int)$this->request['days'] : 14;
            if ($days < 1) $days = 14;
            if ($days > 90) $days = 90;

            $today = date('Y-m-d');
            $windowEnd = date('Y-m-d', strtotime($today . ' +' . $days . ' days'));

            // Schedule-Einträge aller aktiven/geplanten Durchläufe des Users, inkl.
            // Anker (planted_date|start_date), Reifewochen und Präparat-Metadaten.
            $stmt = $this->pdo->prepare(
                "SELECT s.preparation_id, s.phase, s.interval_days, s.dosage, s.dosage_unit,
                        s.start_offset_days, s.end_offset_days,
                        pr.name AS preparation_name, pr.color AS preparation_color,
                        pl.label AS plant_label, pl.planted_date,
                        c.name AS cycle_name, c.start_date, c.flowering_weeks
                 FROM mbc_grow_feeding_schedule s
                 INNER JOIN mbc_grow_plants pl ON pl.grow_plants_id = s.plant_id
                 INNER JOIN mbc_grow_cycles c ON c.grow_cycles_id = pl.cycle_id
                 INNER JOIN mbc_grow_preparations pr ON pr.grow_preparations_id = s.preparation_id
                 WHERE pl.user_id = ? AND c.status IN ('active','planned')"
            );
            $stmt->execute([$userId]);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $events = [];
            foreach ($entries as $e) {
                $anchor = !empty($e['planted_date']) ? (string)$e['planted_date'] : (string)$e['start_date'];
                $horizon = $this->horizonDays((int)$e['flowering_weeks']);
                $interval = max(1, (int)$e['interval_days']);
                $start = max(0, (int)$e['start_offset_days']);
                $end = ($e['end_offset_days'] === null || $e['end_offset_days'] === '')
                    ? $horizon : (int)$e['end_offset_days'];

                for ($day = $start; $day <= $end; $day += $interval) {
                    $date = date('Y-m-d', strtotime($anchor . ' +' . $day . ' days'));
                    if ($date < $today || $date > $windowEnd) continue;
                    $events[] = [
                        'due_date' => $date,
                        'plant_label' => (string)$e['plant_label'],
                        'cycle_name' => (string)$e['cycle_name'],
                        'preparation_name' => (string)$e['preparation_name'],
                        'preparation_color' => $e['preparation_color'],
                        'dosage' => (float)$e['dosage'],
                        'dosage_unit' => (string)$e['dosage_unit'],
                        'phase' => $e['phase'],
                    ];
                    if (count($events) >= self::MAX_EVENTS) break;
                }
                if (count($events) >= self::MAX_EVENTS) break;
            }

            usort($events, static function ($a, $b) {
                return [$a['due_date'], $a['preparation_name']] <=> [$b['due_date'], $b['preparation_name']];
            });

            echo json_encode($events);
        } catch (\Throwable $e) {
            $this->handleError('Error fetching upcoming feeding events', $e);
        }
    }

    private function horizonDays(int $floweringWeeks): int {
        $d = self::DEFAULT_PHASE_DAYS;
        return $d['germination'] + $d['seedling'] + $d['vegetative']
            + max(1, $floweringWeeks) * 7 + $d['harvest'] + $d['cure'];
    }
}
