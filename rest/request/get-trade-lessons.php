<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/lessons       — Lernpfad-Liste inkl. Progress des Users + locked-Flag
 * GET /trade/lessons/{id}  — Detail: Lektion + sections[] + questions[]
 *
 * SICHERHEIT: correct_key und explanation werden bei den Fragen strukturell
 * NIE mitgeliefert — die Bewertung passiert ausschließlich serverseitig in
 * post-trade-lesson-quiz.php.
 */
class requestGetTradeLessons extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($id !== null) {
                $this->getById($userId, $id);
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching trade lessons', $e);
        }
    }

    /** Basis-SELECT: Lektion + eigener Progress + locked aus Vorgänger-Progress. */
    private const BASE_SQL =
        'SELECT l.lessons_id, l.slug, l.title, l.description, l.sort_order,
                l.required_lesson_id, l.unlocks_feature, l.pass_threshold,
                p.status AS progress_status, p.best_score, p.last_score,
                p.attempts, p.completed_at,
                CASE
                  WHEN l.required_lesson_id IS NULL THEN 0
                  WHEN rp.status = \'completed\' THEN 0
                  ELSE 1
                END AS locked
         FROM mbc_trade_lessons l
         LEFT JOIN mbc_trade_lesson_progress p
                ON p.lesson_id = l.lessons_id AND p.user_id = ?
         LEFT JOIN mbc_trade_lesson_progress rp
                ON rp.lesson_id = l.required_lesson_id AND rp.user_id = ?
         WHERE l.is_active = 1';

    private function getList(int $userId): void {
        $stmt = $this->pdo->prepare(
            self::BASE_SQL . ' ORDER BY l.sort_order ASC, l.lessons_id ASC'
        );
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['locked'] = (int)$row['locked'];
        }
        unset($row);

        echo json_encode($rows);
    }

    private function getById(int $userId, int $id): void {
        $stmt = $this->pdo->prepare(self::BASE_SQL . ' AND l.lessons_id = ? LIMIT 1');
        $stmt->execute([$userId, $userId, $id]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lesson) {
            http_response_code(404);
            echo json_encode(['error' => 'Lesson not found']);
            return;
        }

        $lesson['locked'] = (int)$lesson['locked'];

        $stmt = $this->pdo->prepare(
            'SELECT lesson_sections_id, sort_order, title, kind, content
             FROM mbc_trade_lesson_sections
             WHERE lesson_id = ?
             ORDER BY sort_order ASC'
        );
        $stmt->execute([$id]);
        $lesson['sections'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Bewusst OHNE correct_key / explanation (siehe Klassen-Doku)
        $stmt = $this->pdo->prepare(
            'SELECT quiz_questions_id, sort_order, question, options
             FROM mbc_trade_quiz_questions
             WHERE lesson_id = ?
             ORDER BY sort_order ASC'
        );
        $stmt->execute([$id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($questions as &$question) {
            $decoded = json_decode((string)$question['options'], true);
            $question['options'] = is_array($decoded) ? $decoded : [];
        }
        unset($question);

        $lesson['questions'] = $questions;

        echo json_encode($lesson);
    }
}
