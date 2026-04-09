<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

class requestPut extends RequestBase {
    private array $request = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
        $this->log('requestPut');
    }

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            // CSRF Protection for state-changing operations
            CsrfHelper::requireValidToken();

            global $_PUT;
            $this->log(['_PUTs', $_PUT]);

            // Check Input
            if (array_key_exists('id', $this->request) && $this->request['id'] !== '') {

                // Determine primary key column name (e.g., "navigations" -> "navigations_id")
                $areaShort = str_replace(PREFIX . '_', '', $this->area);
                $primaryKeyColumn = $areaShort . '_id';

                $sql = 'UPDATE `' . $this->area . '`';

                // SET column1=value, column2=value2,...
                $set = [];

                foreach ($_PUT as $key => $value) {
                    if (!$this->isValidColumnName($key)) {
                        continue;
                    }

                    // Handle different value types
                    if (is_bool($value)) {
                        $quotedValue = $value ? '1' : '0';
                    } elseif (is_null($value)) {
                        $quotedValue = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        // Convert 0 to NULL for foreign key columns (ending with _id)
                        if ($value === 0 && str_ends_with($key, '_id')) {
                            $quotedValue = 'NULL';
                        } else {
                            $quotedValue = (string)$value;
                        }
                    } else {
                        $quotedValue = $this->pdo->quote((string)$value);
                    }

                    $set[] = '`' . $key . '` = ' . $quotedValue;
                }

                if (count($set) > 0) {
                    $sql .= ' SET ' . implode(', ', $set);
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'No valid fields to update', 'received' => $_PUT]);
                    return;
                }

                $this->log(['SQL', $sql]);

                // WHERE some_column=some_value
                $sql_where = [];
                if (array_key_exists('id', $this->request) && $this->request['id'] !== '') {
                    if (is_array($this->request['id'])) {
                        $ids = array_map('intval', $this->request['id']);
                        $sql_where[] = '`' . $primaryKeyColumn . '` IN (' . implode(',', $ids) . ')';
                    } else {
                        $sql_where[] = '`' . $primaryKeyColumn . '` = ' . (int)$this->request['id'];
                    }
                }

                if (count($sql_where) > 0) {
                    $sql .= ' WHERE ' . implode(' AND ', $sql_where);
                }

                $qRequest = $this->pdo->prepare($sql);
                $qRequest->execute();

                if ($this->request['id']) {
                    $aRequest = ['id' => $this->request['id']];
                    $requestGet = new requestGet($this->pdo, $this->getArea());
                    $requestGet->setRequest($aRequest);
                    $requestGet->execute();
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing id parameter']);
            }

            $this->debugOutput();
        } catch (\Throwable $e) {
            $this->handleError('Error executing PUT request', $e);
        }
    }
}

?>
