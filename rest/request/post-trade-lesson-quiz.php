<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /trade/lessons/{id}/quiz
 *
 * Serverseitige Quiz-Bewertung. Body: {"answers":[{"question_id":1,"answer_key":"a"},…]}
 *
 * Antwort:
 * {
 *   "score": 3, "total": 4, "percent": 75, "passed": true,
 *   "results": [{"question_id":1,"correct":true,"correct_key":"a","explanation":"…"},…],
 *   "unlocked_features": ["market"]
 * }
 *
 * correct_key + explanation verlassen den Server NUR über diese Antwort —
 * nach einem echten Submit, nie im Voraus (siehe get-trade-lessons.php).
 */
class requestPostTradeLessonQuiz extends RequestBase {
    private int $lessonId = 0;
    private array $data = [];

    public function setLessonId(int $lessonId): void {
        $this->lessonId = $lessonId;
    }

    public function setData(array $data): void {
        $this->data = $data;
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

            $answers = $this->normalizeAnswers($this->data['answers'] ?? null);
            if ($answers === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payload', 'message' => 'answers[] with question_id + answer_key expected']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT quiz_questions_id, correct_key, explanation
                 FROM mbc_trade_quiz_questions
                 WHERE lesson_id = ?
                 ORDER BY sort_order ASC'
            );
            $stmt->execute([$this->lessonId]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($questions) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Lesson has no quiz']);
                return;
            }

            $score = 0;
            $results = [];
            foreach ($questions as $question) {
                $questionId = (int)$question['quiz_questions_id'];
                $given = $answers[$questionId] ?? null;
                $correct = ($given !== null && $given === $question['correct_key']);
                if ($correct) {
                    $score++;
                }
                $results[] = [
                    'question_id' => $questionId,
                    'correct'     => $correct,
                    'correct_key' => $question['correct_key'],
                    'explanation' => $question['explanation'],
                ];
            }

            $total = count($questions);
            $percent = (int)round($score / $total * 100);
            $passed = $percent >= (int)$lesson['pass_threshold'];

            // Progress-Upsert: attempts++, Scores aktualisieren; completed bleibt
            // completed (einmal Bestandenes wird nicht wieder aberkannt).
            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_trade_lesson_progress
                   (user_id, lesson_id, status, best_score, last_score, attempts, completed_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                   attempts = attempts + 1,
                   last_score = VALUES(last_score),
                   best_score = GREATEST(COALESCE(best_score, 0), VALUES(best_score)),
                   status = IF(status = \'completed\' OR VALUES(status) = \'completed\', \'completed\', \'in_progress\'),
                   completed_at = COALESCE(completed_at, VALUES(completed_at))'
            );
            $stmt->execute([
                $userId,
                $this->lessonId,
                $passed ? 'completed' : 'in_progress',
                $percent,
                $percent,
                $passed ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            ]);

            echo json_encode([
                'score'             => $score,
                'total'             => $total,
                'percent'           => $percent,
                'passed'            => $passed,
                'results'           => $results,
                'unlocked_features' => $this->unlockedFeatures($userId),
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error grading trade lesson quiz', $e);
        }
    }

    /** @return array<int, string>|null  question_id => answer_key */
    private function normalizeAnswers(mixed $raw): ?array {
        if (!is_array($raw)) {
            return null;
        }
        $answers = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['question_id'], $entry['answer_key'])) {
                return null;
            }
            $answers[(int)$entry['question_id']] = substr((string)$entry['answer_key'], 0, 1);
        }
        return $answers;
    }

    private function loadLesson(int $userId, int $lessonId): array|false {
        $stmt = $this->pdo->prepare(
            'SELECT l.lessons_id, l.pass_threshold,
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

    /** @return string[] Feature-Keys aller abgeschlossenen Lektionen */
    private function unlockedFeatures(int $userId): array {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT l.unlocks_feature
             FROM mbc_trade_lessons l
             JOIN mbc_trade_lesson_progress p
               ON p.lesson_id = l.lessons_id AND p.user_id = ?
             WHERE p.status = \'completed\' AND l.unlocks_feature IS NOT NULL'
        );
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
