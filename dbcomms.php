<?php
class dbcomms {
    public $isConnected;
    protected $datab;
    protected $errorLogFile = 'error_log.txt';

    public function __construct($host = '', $dbname = '', $user = '', $pass = '', $options = []) {
        try {
            $this->datab = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8", $user, $pass, $options);
            $this->datab->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->datab->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->isConnected = true;
        } catch (PDOException $e) {
            $this->handleError("Connection failed", $e->getMessage());
            $this->isConnected = false;
        }
    }

    public function disconnect() {
        $this->datab = null;
        $this->isConnected = false;
    }

    private function manageTransaction($action) {
        try {
            $this->datab->{$action}();
        } catch (PDOException $e) {
            $this->handleError("Failed to {$action} transaction", $e->getMessage());
            return false;
        }
        return true;
    }

    public function beginTransaction() {
        return $this->manageTransaction('beginTransaction');
    }

    public function commit() {
        return $this->manageTransaction('commit');
    }

    public function rollBack() {
        return $this->manageTransaction('rollBack');
    }

    private function executeQuery($query, $params = [], $single = false) {
        try {
            $stmt = $this->datab->prepare($query);
            $stmt->execute($params);
            return $single ? $stmt->fetch() : $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->handleError("Query execution failed", $e->getMessage(), [
                'query' => $query,
                'params' => $params
            ]);
            return null;
        }
    }

    private function validateCounts($columns, $params, $action) {
        if (count($columns) !== count($params)) {
            $this->handleError("{$action} failed: Mismatch between column count and parameter count", [
                'columns' => $columns,
                'params' => $params
            ]);
            return false;
        }
        return true;
    }

    public function getRow($table, $conditions = [], $operators = [], $params = []) {
        if (!$this->validateCounts($conditions, $params, 'Get row')) {
            return null;
        }

        $query = $this->buildQuery('SELECT *', $table, $conditions, $operators, 'LIMIT 1');
        $queryParams = $this->buildNamedParams($conditions, $params); // Update parameter building
        return $this->executeQuery($query, $queryParams, true);
    }

    public function getRows($table, $conditions = [], $operators = [], $params = [], $orderBy = 'id', $ascOrDesc = 'ASC', $limit = null, $offset = null) {
        if (!$this->validateCounts($conditions, $params, 'Get rows')) {
            return null;
        }

        $extra = "ORDER BY `{$orderBy}` {$ascOrDesc}";
        if ($limit !== null) {
            $extra .= " LIMIT {$limit}";
            if ($offset !== null) {
                $extra .= " OFFSET {$offset}";
            }
        }

        $query = $this->buildQuery('SELECT *', $table, $conditions, $operators, $extra);
        $queryParams = $this->buildNamedParams($conditions, $params); // Update parameter building
        return $this->executeQuery($query, $queryParams);
    }

    public function insertRow($table, $columns = [], $params = []) {
        if (!$this->validateCounts($columns, $params, 'Insert')) {
            return null;
        }

        try {
            $this->beginTransaction();
            $placeholders = ':' . implode(', :', $columns);
            $query = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})";
            
            // Build parameters array to match column names
            $queryParams = [];
            foreach ($columns as $index => $column) {
                $queryParams[":{$column}"] = $params[$index];
            }

            $this->executeQuery($query, $queryParams);
            $this->commit();
        } catch (PDOException $e) {
            $this->handleError("Insert failed", $e->getMessage(), [
                'query' => $query,
                'params' => $queryParams
            ]);
            $this->rollBack();
            return null;
        }
    }

    public function updateRow($table, $column, $value, $conditions = [], $operators = [], $params = []) {
        if (!$this->validateCounts($conditions, $params, 'Update')) {
            return null;
        }

        try {
            $this->beginTransaction();
            $query = "UPDATE `{$table}` SET `{$column}` = :value " . $this->buildQuery('', '', $conditions, $operators);
            $paramsArray = array_merge([':value' => $value], $this->buildNamedParams($conditions, $params)); // Update parameter building
            $this->executeQuery($query, $paramsArray);
            $this->commit();
        } catch (PDOException $e) {
            $this->handleError("Update failed", $e->getMessage(), [
                'query' => $query,
                'params' => $paramsArray
            ]);
            $this->rollBack();
            return null;
        }
    }

    public function deleteRow($table, $conditions = [], $operators = [], $params = []) {
        if (!$this->validateCounts($conditions, $params, 'Delete')) {
            return null;
        }

        try {
            $this->beginTransaction();
            $query = $this->buildQuery('DELETE', $table, $conditions, $operators);
            $queryParams = $this->buildNamedParams($conditions, $params); // Update parameter building
            $this->executeQuery($query, $queryParams);
            $this->commit();
        } catch (PDOException $e) {
            $this->handleError("Delete failed", $e->getMessage(), [
                'query' => $query,
                'params' => $queryParams
            ]);
            $this->rollBack();
            return null;
        }
    }

    public function countRows($table, $conditions = [], $operators = [], $params = []) {
        if (!$this->validateCounts($conditions, $params, 'Count rows')) {
            return null;
        }

        $query = $this->buildQuery('SELECT COUNT(*) AS count', $table, $conditions, $operators);
        $queryParams = $this->buildNamedParams($conditions, $params); // Update parameter building
        $result = $this->executeQuery($query, $queryParams, true);

        if ($result === null) {
            return $this->handleError("Count failed", "Query returned null", [
                'query' => $query,
                'params' => $queryParams
            ]);
        }

        return $result['count'];
    }

    public function getAggregate($table, $aggregateFunction, $column, $conditions = [], $operators = [], $params = []) {
        if (!$this->validateCounts($conditions, $params, 'Get aggregate')) {
            return null;
        }

        $query = $this->buildQuery("SELECT {$aggregateFunction}(`{$column}`) AS aggregate", $table, $conditions, $operators);
        $queryParams = $this->buildNamedParams($conditions, $params); // Update parameter building
        $result = $this->executeQuery($query, $queryParams, true);

        if ($result === null) {
            return $this->handleError("Aggregate failed", "Query returned null", [
                'query' => $query,
                'params' => $queryParams
            ]);
        }

        return $result['aggregate'];
    }

    private function buildQuery($action, $table, $conditions = [], $operators = [], $extra = '') {
        $query = "{$action} FROM `{$table}` WHERE ";
        $conditionStrings = [];

        foreach ($conditions as $index => $condition) {
            $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}"; // Match named parameters
        }

        $query .= implode(" AND ", $conditionStrings);
        return "{$query} {$extra}";
    }

    private function buildNamedParams($keys = [], $values = []) {
        $params = [];

        foreach ($keys as $index => $key) {
            $params[":{$key}"] = $values[$index];
        }

        return $params;
    }

    private function logError($message, $context = []) {
        $logMessage = date('Y-m-d H:i:s') . " - " . $message;
        if (!empty($context)) {
            $logMessage .= ' | Context: ' . json_encode($context);
        }
        file_put_contents($this->errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);
    }

    private function handleError($message, $errorDetail, $context = []) {
        $this->logError($message, $context);
        return [
            'success' => false,
            'message' => $message,
            'error' => $errorDetail,
            'context' => $context
        ];
    }
}
?>
