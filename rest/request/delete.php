<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

class requestDelete extends RequestBase {
    private array $request = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
        $this->log('requestDelete');
    }

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            // CSRF Protection for state-changing operations
            CsrfHelper::requireValidToken();

            // Check Input
            if (array_key_exists('id', $this->request) && $this->request['id'] !== '') {
                // Determine primary key column name (e.g., "navigations" -> "navigations_id")
                $areaShort = str_replace(PREFIX . '_', '', $this->getArea());
                $primaryKeyColumn = $areaShort . '_id';

                // Ensure IDs are integers to prevent SQL injection
                $ids = is_array($this->request['id'])
                    ? array_map('intval', $this->request['id'])
                    : [(int)$this->request['id']];

                $sql = 'DELETE FROM `' . $this->getArea() . '` WHERE `' . $primaryKeyColumn . '` IN (' . implode(',', $ids) . ')';

                $qRequest = $this->pdo->prepare($sql);
                $qRequest->execute();

                echo json_encode(['success' => true, 'deleted' => count($ids)]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing id parameter']);
            }

            $this->debugOutput();
        } catch (\Throwable $e) {
            $this->handleError('Error executing DELETE request', $e);
        }
    }
}

?>