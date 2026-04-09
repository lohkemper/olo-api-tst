<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET Tables Handler
 *
 * Gibt eine Liste aller verfügbaren Tabellen mit Metadaten aus
 *
 * @package MBC REST API - Public Server
 * @version 1.0
 */
class requestGetTables extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    /**
     * Execute Handler
     */
    public function execute(): void {
        $this->log('requestGetTables::execute');

        try {
            // Hole alle Tabellen
            global $prefix;
            $tables = $this->getAllTables($prefix);

            // Detaillierte Informationen gewünscht?
            $detailed = ($this->request['detailed'] ?? 'false') === 'true';

            if ($detailed) {
                $result = $this->getDetailedTableList($tables, $prefix);
            } else {
                $result = $this->getSimpleTableList($tables, $prefix);
            }

            // JSON Response senden
            $this->sendJson($result);

        } catch (PDOException $e) {
            $this->log(['error' => $e->getMessage()], 'error');
            $this->sendError(500, 'Database Error', DEBUG ? $e->getMessage() : 'Could not retrieve tables');
        } catch (\Throwable $e) {
            $this->log(['error' => $e->getMessage()], 'error');
            $this->sendError(500, 'Internal Server Error', DEBUG ? $e->getMessage() : 'An error occurred');
        }
    }

    /**
     * Holt alle Tabellen mit dem Präfix
     */
    private function getAllTables(string $prefix): array {
        $stmt = $this->pdo->query('SHOW TABLES');
        $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $tables = [];
        $prefixWithUnderscore = $prefix . '_';

        foreach ($allTables as $fullTableName) {
            if (str_starts_with($fullTableName, $prefixWithUnderscore)) {
                $tableName = substr($fullTableName, strlen($prefixWithUnderscore));
                $tables[$tableName] = $fullTableName;
            }
        }

        return $tables;
    }

    /**
     * Einfache Tabellen-Liste (nur Namen)
     */
    private function getSimpleTableList(array $tables, string $prefix): array {
        $result = [
            'prefix' => $prefix,
            'count' => count($tables),
            'tables' => []
        ];

        foreach ($tables as $tableName => $fullTableName) {
            $result['tables'][] = [
                'name' => $tableName,
                'fullName' => $fullTableName,
                'endpoint' => "/public-server/rest/index.php?r={$tableName}",
                'schemaEndpoint' => "/public-server/rest/index.php?r={$tableName}/schema"
            ];
        }

        return $result;
    }

    /**
     * Detaillierte Tabellen-Liste mit Metadaten
     */
    private function getDetailedTableList(array $tables, string $prefix): array {
        $result = [
            'prefix' => $prefix,
            'count' => count($tables),
            'tables' => []
        ];

        foreach ($tables as $tableName => $fullTableName) {
            $tableInfo = $this->getTableMetadata($fullTableName);

            $result['tables'][] = [
                'name' => $tableName,
                'fullName' => $fullTableName,
                'endpoint' => "/public-server/rest/index.php?r={$tableName}",
                'schemaEndpoint' => "/public-server/rest/index.php?r={$tableName}/schema",
                'metadata' => $tableInfo
            ];
        }

        return $result;
    }

    /**
     * Holt Metadaten einer Tabelle
     */
    private function getTableMetadata(string $fullTableName): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ENGINE,
                TABLE_ROWS,
                AVG_ROW_LENGTH,
                DATA_LENGTH,
                INDEX_LENGTH,
                DATA_FREE,
                AUTO_INCREMENT,
                CREATE_TIME,
                UPDATE_TIME,
                TABLE_COLLATION,
                TABLE_COMMENT
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$fullTableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return [];
        }

        // Primary Key ermitteln
        $primaryKey = $this->getPrimaryKey($fullTableName);

        // Spalten-Anzahl ermitteln
        $columnCount = $this->getColumnCount($fullTableName);

        // Foreign Keys ermitteln
        $foreignKeyCount = $this->getForeignKeyCount($fullTableName);

        return [
            'engine' => $result['ENGINE'],
            'collation' => $result['TABLE_COLLATION'],
            'comment' => $result['TABLE_COMMENT'],
            'primaryKey' => $primaryKey,
            'columnCount' => $columnCount,
            'foreignKeyCount' => $foreignKeyCount,
            'statistics' => [
                'rows' => (int)$result['TABLE_ROWS'],
                'avgRowLength' => (int)$result['AVG_ROW_LENGTH'],
                'dataLength' => (int)$result['DATA_LENGTH'],
                'dataSizeMB' => round((int)$result['DATA_LENGTH'] / 1024 / 1024, 2),
                'indexLength' => (int)$result['INDEX_LENGTH'],
                'indexSizeMB' => round((int)$result['INDEX_LENGTH'] / 1024 / 1024, 2),
                'totalSizeMB' => round(((int)$result['DATA_LENGTH'] + (int)$result['INDEX_LENGTH']) / 1024 / 1024, 2),
                'dataFree' => (int)$result['DATA_FREE'],
                'autoIncrement' => $result['AUTO_INCREMENT'] ? (int)$result['AUTO_INCREMENT'] : null
            ],
            'timestamps' => [
                'created' => $result['CREATE_TIME'],
                'updated' => $result['UPDATE_TIME']
            ]
        ];
    }

    /**
     * Ermittelt den Primary Key einer Tabelle
     */
    private function getPrimaryKey(string $fullTableName): ?string {
        $stmt = $this->pdo->prepare("SHOW KEYS FROM `{$fullTableName}` WHERE Key_name = 'PRIMARY'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['Column_name'] : null;
    }

    /**
     * Ermittelt die Anzahl der Spalten
     */
    private function getColumnCount(string $fullTableName): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$fullTableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['count'] ?? 0);
    }

    /**
     * Ermittelt die Anzahl der Foreign Keys
     */
    private function getForeignKeyCount(string $fullTableName): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT CONSTRAINT_NAME) as count
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$fullTableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['count'] ?? 0);
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
