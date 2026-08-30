<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /trade/lessons/{id}/start
 *
 * Legt den Lernfortschritt des Users für die Lektion an (Upsert auf
 * in_progress) — Basis für die "Fortsetzen"-UX. Gesperrte Lektionen
 * (Vorgänger nicht abgeschlossen) liefern 403.
 */
class requestPostTradeLessonStart extends RequestBase {
    private int $lessonId = 0;

    public function setLessonId(int $lessonId): void {
        $this->lessonId = $lessonId;
    }

    public function setData(array $data): void {
        // Kein Body nötig — Signatur-Konsistenz mit den anderen POST-Handlern.
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $lesson = $this->loadLesson($userId, $this->lessonId);
            if (!$lesson) {
                http_response_code(404);
                echo json_encode(['error' => 'Lesson not found']);
                return;
            }
            if ((int)$lesson['locked'] === 1) {
                http_response_code(403);
                echo json_encode(['error' => 'Lesson locked', 'message' => 'Complete the previous lesson first']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_trade_lesson_progress (user_id, lesson_id, status)
                 VALUES (?, ?, \'in_progress\')
                 ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$userId, $this->lessonId]);

            $stmt = $this->pdo->prepare(
                'SELECT lesson_progress_id, user_id, lesson_id, status, best_score,
                        last_score, attempts, completed_at, created_at, updated_at
                 FROM mbc_trade_lesson_progress
                 WHERE user_id = ? AND lesson_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$userId, $this->lessonId]);

            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error starting trade lesson', $e);
        }
    }

    private function loadLesson(int $userId, int $lessonId): array|false {
        $stmt = $this->pdo->prepare(
            'SELECT l.lessons_id,
                    CASE
                      WHEN l.required_lesson_id IS NULL THEN 0
                      WHEN rp.status = \'completed\' THEN 0
                      ELSE 1
                    END AS locked
             FROM mbc_trade_lessons l
             LEFT JOIN mbc_trade_lesson_progress rp
                    ON rp.lesson_id = l.required_lesson_id AND rp.user_id = ?
             WHERE l.lessons_id = ? AND l.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$userId, $lessonId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
