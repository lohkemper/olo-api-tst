<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/exercises — Erstellt eine eigene Übung (user_id = current user, is_template = 0)
 *
 * Body:
 *  {
 *    "name": "Bankdrücken (eigene Variante)",
 *    "slug": "bankdruecken-eigene-variante",
 *    "category_id": 12,
 *    "primary_muscle": "chest",
 *    "secondary_muscles": ["triceps","front_delts"],   // optional
 *    "exercise_type": "strength",                       // strength|cardio|mobility|bodyweight
 *    "measurement_type": "weight_reps",                 // weight_reps|reps_only|time|distance|weight_time
 *    "description": "...",                              // optional
 *    "equipment_article_id": null                       // optional
 *  }
 */
class requestPostGymExercises extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $name = trim((string)($this->data['name'] ?? ''));
            $slug = trim((string)($this->data['slug'] ?? ''));
            if ($name === '' || $slug === '') {
                http_response_code(400);
                echo json_encode(['error' => 'name and slug are required']);
                return;
            }

            $exerciseType    = (string)($this->data['exercise_type']    ?? 'strength');
            $measurementType = (string)($this->data['measurement_type'] ?? 'weight_reps');

            $allowedTypes        = ['strength','cardio','mobility','bodyweight'];
            $allowedMeasurements = ['weight_reps','reps_only','time','distance','weight_time'];
            if (!in_array($exerciseType, $allowedTypes, true) || !in_array($measurementType, $allowedMeasurements, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid exercise_type or measurement_type']);
                return;
            }

            $secondary = $this->data['secondary_muscles'] ?? null;
            $secondaryJson = $secondary !== null ? json_encode($secondary) : null;

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_exercises
                  (user_id, name, slug, category_id, equipment_article_id, primary_muscle,
                   secondary_muscles, exercise_type, measurement_type, description,
                   video_url, photo_url, is_template)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $stmt->execute([
                $userId,
                $name,
                $slug,
                $this->data['category_id'] ?? null,
                $this->data['equipment_article_id'] ?? null,
                $this->data['primary_muscle'] ?? null,
                $secondaryJson,
                $exerciseType,
                $measurementType,
                $this->data['description'] ?? null,
                $this->data['video_url'] ?? null,
                $this->data['photo_url'] ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT exercises_id, user_id, name, slug, category_id, equipment_article_id,
                        primary_muscle, secondary_muscles, exercise_type, measurement_type,
                        description, video_url, photo_url, is_template, created_at, updated_at
                 FROM mbc_gym_exercises WHERE exercises_id = ?'
            );
            $stmt->execute([$newId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\PDOException $e) {
            // Slug-Duplikat → 409
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Slug already exists for this user']);
                return;
            }
            $this->handleError('Error creating gym exercise', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym exercise', $e);
        }
    }
}
