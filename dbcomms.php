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
            $this->logError("Connection failed: " . $e->getMessage());
            $this->isConnected = false;
        }
    }

    public function disconnect() {
        $this->datab = null;
        $this->isConnected = false;
    }

    public function beginTransaction() {
        try {
            $this->datab->beginTransaction();
        } catch (PDOException $e) {
            $this->logError("Failed to begin transaction: " . $e->getMessage());
        }
    }

    public function commit() {
        try {
            $this->datab->commit();
        } catch (PDOException $e) {
            $this->logError("Failed to commit transaction: " . $e->getMessage());
        }
    }

    public function rollBack() {
        try {
            if ($this->datab->inTransaction()) {
                $this->datab->rollBack();
            }
        } catch (PDOException $e) {
            $this->logError("Failed to roll back transaction: " . $e->getMessage());
        }
    }

    private function executeQuery($query, $params = [], $single = false) {
        try {
            $stmt = $this->datab->prepare($query);
            $stmt->execute($params);
            return $single ? $stmt->fetch() : $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logError("Query failed: {$query} with params " . json_encode($params) . " - Error: " . $e->getMessage());
            if ($this->datab->inTransaction()) {
                $this->rollBack();
            }
            return null;
        }
    }

    public function getRow($table, $conditions, $operators, $params) {
        $query = $this->buildQuery('SELECT *', $table, $conditions, $operators, 'LIMIT 1');
        return $this->executeQuery($query, $this->buildParams($conditions, $params), true);
    }

    public function getRows($table, $conditions, $operators, $params, $orderBy = 'id', $ascOrDesc = 'ASC', $limit = null, $offset = null) {
        $extra = "ORDER BY `{$orderBy}` {$ascOrDesc}";
        if ($limit !== null) {
            $extra .= " LIMIT {$limit}";
            if ($offset !== null) {
                $extra .= " OFFSET {$offset}";
            }
        }
        $query = $this->buildQuery('SELECT *', $table, $conditions, $operators, $extra);
        return $this->executeQuery($query, $this->buildParams($conditions, $params));
    }

    public function insertRow($table, $columns, $params) {
        try {
            $this->beginTransaction();
            $columnsArray = explode(",", $columns);
            $placeholders = ':' . implode(', :', $columnsArray);
            $query = "INSERT INTO `{$table}` (`" . implode('`, `', $columnsArray) . "`) VALUES ({$placeholders})";
            $this->executeQuery($query, $this->buildParams($columnsArray, $params));
            $this->commit();
        } catch (PDOException $e) {
            $this->logError("Insert failed: {$query} with params " . json_encode($params) . " - Error: " . $e->getMessage());
            $this->rollBack();
        }
    }

    public function updateRow($table, $column, $value, $conditions, $operators, $params) {
        try {
            $this->beginTransaction();
            $conditionsArray = explode(",", $conditions);
            $operatorsArray = explode(",", $operators);
            $query = "UPDATE `{$table}` SET `{$column}` = :value " . $this->buildQuery('', '', $conditionsArray, $operatorsArray);
            $paramsArray = array_merge([':value' => $value], $this->buildParams($conditionsArray, $params));
            $this->executeQuery($query, $paramsArray);
            $this->commit();
        } catch (PDOException $e) {
            $this->logError("Update failed: {$query} with params " . json_encode($paramsArray) . " - Error: " . $e->getMessage());
            $this->rollBack();
        }
    }

    public function deleteRow($table, $conditions, $operators, $params) {
        try {
            $this->beginTransaction();
            $query = $this->buildQuery('DELETE', $table, $conditions, $operators);
            $this->executeQuery($query, $this->buildParams($conditions, $params));
            $this->commit();
        } catch (PDOException $e) {
            $this->logError("Delete failed: {$query} with params " . json_encode($params) . " - Error: " . $e->getMessage());
            $this->rollBack();
        }
    }

    public function countRows($table, $conditions, $operators, $params) {
        $query = $this->buildQuery('SELECT COUNT(*) AS count', $table, $conditions, $operators);
        $result = $this->executeQuery($query, $this->buildParams($conditions, $params), true);

        if ($result === null) {
            $this->logError("Count failed: {$query} with params " . json_encode($this->buildParams($conditions, $params)));
            return 0;
        }

        return $result['count'];
    }

    public function getAggregate($table, $aggregateFunction, $column, $conditions, $operators, $params) {
        $query = $this->buildQuery("SELECT {$aggregateFunction}(`{$column}`) AS aggregate", $table, $conditions, $operators);
        $result = $this->executeQuery($query, $this->buildParams($conditions, $params), true);

        if ($result === null) {
            $this->logError("Aggregate failed: {$query} with params " . json_encode($this->buildParams($conditions, $params)));
            return null;
        }

        return $result['aggregate'];
    }

    private function buildQuery($action, $table, $conditions, $operators, $extra = '') {
        $conditionsArray = explode(",", $conditions);
        $operatorsArray = explode(",", $operators);
        $query = "{$action} FROM `{$table}` WHERE ";

        for ($i = 0; $i < count($conditionsArray); $i++) {
            $query .= "`{$conditionsArray[$i]}` {$operatorsArray[$i]} :param{$i}";
            if (($i + 1) < count($conditionsArray)) {
                $query .= " AND ";
            }
        }

        return "{$query} {$extra}";
    }

    private function buildParams($keys, $values) {
        $keysArray = explode(",", $keys);
        $valuesArray = explode(",", $values);
        $params = [];

        foreach ($keysArray as $index => $key) {
            $params[":param{$index}"] = $valuesArray[$index];
        }

        return $params;
    }

    private function logError($message) {
        file_put_contents($this->errorLogFile, date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, FILE_APPEND);
    }
}
?>
