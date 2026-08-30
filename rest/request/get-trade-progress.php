<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/progress
 *
 * Zusammenfassung des Lernstands für Stats + Feature-Gates (Soft-Gate Markt,
 * V2: Trading): {"lessons_total":3,"lessons_completed":1,"unlocked_features":["market"]}
 */
class requestGetTradeProgress extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM mbc_trade_lessons WHERE is_active = 1'
            );
            $stmt->execute();
            $total = (int)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM mbc_trade_lesson_progress p
                 JOIN mbc_trade_lessons l ON l.lessons_id = p.lesson_id AND l.is_active = 1
                 WHERE p.user_id = ? AND p.status = \'completed\''
            );
            $stmt->execute([$userId]);
            $completed = (int)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare(
                'SELECT DISTINCT l.unlocks_feature
                 FROM mbc_trade_lessons l
                 JOIN mbc_trade_lesson_progress p
                   ON p.lesson_id = l.lessons_id AND p.user_id = ?
                 WHERE p.status = \'completed\' AND l.unlocks_feature IS NOT NULL'
            );
            $stmt->execute([$userId]);
            $features = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            echo json_encode([
                'lessons_total'     => $total,
                'lessons_completed' => $completed,
                'unlocked_features' => $features,
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error fetching trade progress', $e);
        }
    }
}
