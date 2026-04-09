<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET Schema Handler
 *
 * Gibt vollständige Tabellenstruktur als JSON aus
 *
 * @package MBC REST API - Public Server
 * @version 1.0
 */
class requestGetSchema extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    /**
     * Execute Handler
     */
    public function execute(): void {
        $this->log('requestGetSchema::execute');

        try {
            // Area-Parameter extrahieren
            $area = $this->request['area'] ?? null;

            if (!$area) {
                $this->sendError(400, 'Bad Request', 'Area parameter required');
                return;
            }

            // Prüfe ob Tabelle existiert
            global $prefix;
            $fullTableName = $prefix . '_' . $area;

            // Validiere Tabellenname
            if (!$this->tableExists($fullTableName)) {
                $this->sendError(404, 'Not Found', "Table '{$area}' not found");
                return;
            }

            // Schema abrufen
            $schema = $this->getCompleteSchema($fullTableName, $area);

            // JSON Response senden
            $this->sendJson($schema);

        } catch (PDOException $e) {
            $this->log(['error' => $e->getMessage()], 'error');
            $this->sendError(500, 'Database Error', DEBUG ? $e->getMessage() : 'Could not retrieve schema');
        } catch (\Throwable $e) {
            $this->log(['error' => $e->getMessage()], 'error');
            $this->sendError(500, 'Internal Server Error', DEBUG ? $e->getMessage() : 'An error occurred');
        }
    }

    /**
     * Prüft ob Tabelle existiert
     */
    private function tableExists(string $tableName): bool {
        try {
            // Use INFORMATION_SCHEMA instead of SHOW TABLES for better reliability
            $stmt = $this->pdo->prepare("
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$tableName]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->log(['tableExists error' => $e->getMessage()], 'error');
            return false;
        }
    }

    /**
     * Holt das komplette Schema als strukturiertes Array
     */
    private function getCompleteSchema(string $fullTableName, string $area): array {
        $schema = [
            'table' => $area,
            'fullTableName' => $fullTableName,
            'columns' => $this->getColumns($fullTableName),
            'indexes' => $this->getIndexes($fullTableName),
            'foreignKeys' => $this->getForeignKeys($fullTableName),
            'tableComment' => $this->getTableComment($fullTableName),
            'engine' => $this->getTableEngine($fullTableName),
            'collation' => $this->getTableCollation($fullTableName),
            'statistics' => $this->getTableStatistics($fullTableName)
        ];

        return $schema;
    }

    /**
     * Holt Spalten-Informationen
     */
    private function getColumns(string $fullTableName): array {
        $stmt = $this->pdo->query("SHOW FULL COLUMNS FROM `{$fullTableName}`");
        $columns = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = [
                'name' => $row['Field'],
                'type' => $row['Type'],
                'collation' => $row['Collation'],
                'null' => $row['Null'] === 'YES',
                'key' => $row['Key'],
                'default' => $row['Default'],
                'extra' => $row['Extra'],
                'privileges' => $row['Privileges'],
                'comment' => $row['Comment']
            ];
        }

        return $columns;
    }

    /**
     * Holt Index-Informationen
     */
    private function getIndexes(string $fullTableName): array {
        $stmt = $this->pdo->query("SHOW INDEX FROM `{$fullTableName}`");
        $indexGroups = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $keyName = $row['Key_name'];

            if (!isset($indexGroups[$keyName])) {
                $indexGroups[$keyName] = [
                    'name' => $keyName,
                    'unique' => $row['Non_unique'] == 0,
                    'type' => $row['Index_type'],
                    'comment' => $row['Index_comment'] ?? '',
                    'columns' => []
                ];
            }

            $indexGroups[$keyName]['columns'][] = [
                'name' => $row['Column_name'],
                'sequence' => (int)$row['Seq_in_index'],
                'collation' => $row['Collation'],
                'cardinality' => $row['Cardinality'],
                'subPart' => $row['Sub_part'],
                'packed' => $row['Packed'],
                'null' => $row['Null'],
            ];
        }

        return array_values($indexGroups);
    }

    /**
     * Holt Foreign Key Informationen
     */
    private function getForeignKeys(string $fullTableName): array {
        $stmt = $this->pdo->prepare("
            SELECT
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = DATABASE()
              AND kcu.TABLE_NAME = ?
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.ORDINAL_POSITION
        ");
        $stmt->execute([$fullTableName]);

        $foreignKeys = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $foreignKeys[] = [
                'name' => $row['CONSTRAINT_NAME'],
                'column' => $row['COLUMN_NAME'],
                'referencedTable' => $row['REFERENCED_TABLE_NAME'],
                'referencedColumn' => $row['REFERENCED_COLUMN_NAME'],
                'onUpdate' => $row['UPDATE_RULE'],
                'onDelete' => $row['DELETE_RULE']
            ];
        }

        return $foreignKeys;
    }

    /**
     * Holt Tabellen-Kommentar
     */
    private function getTableComment(string $fullTableName): ?string {
        $stmt = $this->pdo->prepare("
            SELECT TABLE_COMMENT
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$fullTableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['TABLE_COMMENT'] ?? null;
    }

    /**
     * Holt Tabellen-Engine
     */
    private function getTableEngine(string $fullTableName): ?string {
        $stmt = $this->pdo->prepare("
            SELECT ENGINE
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$fullTableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['ENGINE'] ?? null;
    }

    /**
     * Holt Tabellen-Collation
     */
    private function getTableCollation(string $fullTableName): ?string {
        $stmt = $this->pdo->prepare("
            SELECT TABLE_COLLATION
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$fullTableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['TABLE_COLLATION'] ?? null;
    }

    /**
     * Holt Tabellen-Statistiken
     */
    private function getTableStatistics(string $fullTableName): array {
        $stmt = $this->pdo->prepare("
            SELECT
                TABLE_ROWS,
                AVG_ROW_LENGTH,
                DATA_LENGTH,
                MAX_DATA_LENGTH,
                INDEX_LENGTH,
                DATA_FREE,
                AUTO_INCREMENT,
                CREATE_TIME,
                UPDATE_TIME,
                CHECK_TIME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$fullTableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return [];
        }

        return [
            'rows' => (int)$result['TABLE_ROWS'],
            'avgRowLength' => (int)$result['AVG_ROW_LENGTH'],
            'dataLength' => (int)$result['DATA_LENGTH'],
            'maxDataLength' => (int)$result['MAX_DATA_LENGTH'],
            'indexLength' => (int)$result['INDEX_LENGTH'],
            'dataFree' => (int)$result['DATA_FREE'],
            'autoIncrement' => $result['AUTO_INCREMENT'] ? (int)$result['AUTO_INCREMENT'] : null,
            'createTime' => $result['CREATE_TIME'],
            'updateTime' => $result['UPDATE_TIME'],
            'checkTime' => $result['CHECK_TIME']
        ];
    }

    /**
     * Sendet JSON Response
     */
    private function sendJson(array $data): void {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Sendet Error Response
     */
    private function sendError(int $code, string $error, string $message): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $error,
            'message' => $message
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
