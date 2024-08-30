<?php
class dbcomms {
    // Public property to track if the database connection is established
    public $isConnected;

    // Protected properties for the PDO instance and error log file path
    protected $datab;
    protected $errorLogFile = 'dbcomms.log';

    // Private property to track if a transaction is currently in progress
    private $transactionInProgress = false;

    // Constructor to establish a new PDO database connection
    public function __construct($host = '', $dbname = '', $user = '', $pass = '', $options = []) {
        // Set default PDO options if none are provided
        if (empty($options)) {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on database errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC  // Fetch data as associative arrays by default
            ];
        }

        try {
            // Create a new PDO instance (database connection)
            $this->datab = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8", $user, $pass, $options);
            $this->isConnected = true;  // Set connection flag to true on success
        } catch (PDOException $e) {
            // Handle any errors during connection setup
            $this->handleError("Connection failed", $e->getMessage(), [], $e->getCode());
            $this->isConnected = false;  // Set connection flag to false on failure
        }
    }

    // Method to disconnect from the database
    public function disconnect() {
        $this->datab = null;  // Close the PDO connection
        $this->isConnected = false;  // Set connection flag to false
        $this->transactionInProgress = false; // Reset transaction state on disconnect
    }

    // Return the PDO instance so that we can do whatever custom queries we want
    public function getDb() {
        if ($this->datab instanceof PDO) {
            return $this->datab; // Return the PDO instance
        }
        return null; // Return null if the PDO instance is not set
    }

    // Private method to manage transactions (begin, commit, rollback)
    private function manageTransaction($action) {
        try {
            if ($action === 'beginTransaction') {
                // Check if a transaction is already in progress
                if ($this->transactionInProgress) {
                    return $this->handleError("Transaction already in progress", "Cannot start a new transaction while another is active");
                }
                $this->datab->beginTransaction();  // Start a new transaction
                $this->transactionInProgress = true;  // Set transaction flag to true
            } elseif ($this->transactionInProgress) {
                // Commit or rollback if a transaction is active
                $this->datab->{$action}();  // Commit or roll back the transaction
                $this->transactionInProgress = false;  // Reset transaction flag
            } else {
                // Handle error if trying to commit or rollback without an active transaction
                return $this->handleError("No active transaction", "Cannot {$action} because no transaction is currently active");
            }
        } catch (PDOException $e) {
            // Handle any errors during transaction management
            return $this->handleError("Failed to {$action} transaction", $e->getMessage(), [], $e->getCode());
        }
        return ['success' => true];  // Return success if no exceptions
    }

    // Public method to start a transaction
    public function beginTransaction() {
        return $this->manageTransaction('beginTransaction');
    }

    // Public method to commit a transaction
    public function commit() {
        return $this->manageTransaction('commit');
    }

    // Public method to roll back a transaction
    public function rollBack() {
        return $this->manageTransaction('rollBack');
    }

    // Private method to execute an SQL query
    private function executeQuery($query, $params = [], $single = false) {
        try {
            $stmt = $this->datab->prepare($query);  // Prepare the SQL statement
            $stmt->execute($params);  // Execute the statement with bound parameters
            // Fetch one or all results based on the $single flag
            return $single ? $stmt->fetch() : $stmt->fetchAll();
        } catch (PDOException $e) {
            // Handle any errors during query execution
            return $this->handleError("Query execution failed", $e->getMessage(), [
                'query' => $query,
                'params' => $params
            ], $e->getCode());
        }
    }

    // Private method to validate if the number of columns matches the number of parameters
    private function validateCounts($columns, $params, $action) {
        if (count($columns) !== count($params)) {
            // Handle mismatch error
            return $this->handleError("{$action} failed: Mismatch between column count and parameter count", '', [
                'columns' => $columns,
                'params' => $params
            ]);
        }
        return true;
    }

    // Public method to get a single row from a table based on conditions
    public function getRow($table, $conditions = [], $operators = [], $params = [], $logicalOperator = 'AND') {
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Get row')) {
            return null;
        }
    
        // Construct the SELECT query
        $conditionStrings = [];
        foreach ($conditions as $index => $condition) {
            $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}";
        }
        $whereClause = !empty($conditionStrings) ? " WHERE " . implode(" {$logicalOperator} ", $conditionStrings) : "";
        $query = "SELECT * FROM `{$table}`{$whereClause} LIMIT 1";
    
        $queryParams = $this->buildNamedParams($conditions, $params);
        return $this->executeQuery($query, $queryParams, true);
    }

    // Public method to get multiple rows from a table based on conditions
    public function getRows($table, $conditions = [], $operators = [], $params = [], $orderBy = 'id', $ascOrDesc = 'ASC', $limit = null, $offset = null, $logicalOperator = 'AND') {
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Get rows')) {
            return null;
        }
    
        // Construct the SELECT query
        $conditionStrings = [];
        foreach ($conditions as $index => $condition) {
            $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}";
        }
        $whereClause = !empty($conditionStrings) ? " WHERE " . implode(" {$logicalOperator} ", $conditionStrings) : "";
        $extra = "ORDER BY `{$orderBy}` {$ascOrDesc}";
        if ($limit !== null) {
            $extra .= " LIMIT {$limit}";
            if ($offset !== null) {
                $extra .= " OFFSET {$offset}";
            }
        }
        $query = "SELECT * FROM `{$table}`{$whereClause} {$extra}";
    
        $queryParams = $this->buildNamedParams($conditions, $params);
        return $this->executeQuery($query, $queryParams);
    }

    // Public method to update a row in a table based on conditions
    public function updateRow($table, $column, $value, $conditions = [], $operators = [], $params = [], $logicalOperator = 'AND') {
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Update')) {
            return null;
        }
    
        try {
            $this->beginTransaction();
    
            // Construct the UPDATE query
            $conditionStrings = [];
            foreach ($conditions as $index => $condition) {
                $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}";
            }
            $whereClause = !empty($conditionStrings) ? " WHERE " . implode(" {$logicalOperator} ", $conditionStrings) : "";
            $query = "UPDATE `{$table}` SET `{$column}` = :value{$whereClause}";
    
            $paramsArray = array_merge([':value' => $value], $this->buildNamedParams($conditions, $params));
            $this->executeQuery($query, $paramsArray);
            $this->commit();
        } catch (PDOException $e) {
            $this->handleError("Update failed", $e->getMessage(), [
                'query' => $query,
                'params' => $paramsArray
            ], $e->getCode());
            $this->rollBack();
            return null;
        }
    }
    
    // Public method to delete rows from a table based on conditions
    public function deleteRow($table, $conditions = [], $operators = [], $params = [], $logicalOperator = 'AND') {
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Delete')) {
            return null;
        }
    
        try {
            $this->beginTransaction();
    
            // Construct the DELETE query
            $conditionStrings = [];
            foreach ($conditions as $index => $condition) {
                $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}";
            }
            $whereClause = !empty($conditionStrings) ? " WHERE " . implode(" {$logicalOperator} ", $conditionStrings) : "";
            $query = "DELETE FROM `{$table}`{$whereClause}";
    
            $queryParams = $this->buildNamedParams($conditions, $params);
            $this->executeQuery($query, $queryParams);
            $this->commit();
        } catch (PDOException $e) {
            $this->handleError("Delete failed", $e->getMessage(), [
                'query' => $query,
                'params' => $queryParams
            ], $e->getCode());
            $this->rollBack();
            return null;
        }
    }

    // Public method to count the number of rows matching conditions in a table
    public function countRows($table, $conditions = [], $operators = [], $params = [], $logicalOperator = 'AND') {
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Count rows')) {
            return null;
        }
    
        // Construct the COUNT query
        $conditionStrings = [];
        foreach ($conditions as $index => $condition) {
            $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}";
        }
        $whereClause = !empty($conditionStrings) ? " WHERE " . implode(" {$logicalOperator} ", $conditionStrings) : "";
        $query = "SELECT COUNT(*) AS count FROM `{$table}`{$whereClause}";
    
        $queryParams = $this->buildNamedParams($conditions, $params);
        $result = $this->executeQuery($query, $queryParams, true);
    
        if ($result === null) {
            return $this->handleError("Count failed", "Query returned null", [
                'query' => $query,
                'params' => $queryParams
            ]);
        }
    
        return $result['count'];
    }

    // Public method to perform aggregate functions (SUM, AVG, etc.) on a column
    public function getAggregate($table, $aggregateFunction, $column, $conditions = [], $operators = [], $params = [], $logicalOperator = 'AND') {
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Get aggregate')) {
            return null;
        }
    
        // Construct the AGGREGATE query
        $conditionStrings = [];
        foreach ($conditions as $index => $condition) {
            $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}";
        }
        $whereClause = !empty($conditionStrings) ? " WHERE " . implode(" {$logicalOperator} ", $conditionStrings) : "";
        $query = "SELECT {$aggregateFunction}(`{$column}`) AS aggregate FROM `{$table}`{$whereClause}";
    
        $queryParams = $this->buildNamedParams($conditions, $params);
        $result = $this->executeQuery($query, $queryParams, true);
    
        if ($result === null) {
            return $this->handleError("Aggregate failed", "Query returned null", [
                'query' => $query,
                'params' => $queryParams
            ]);
        }
    
        return $result['aggregate'];
    }

    // Public method to insert a new row into a table
    public function insertRow($table, $columns = [], $params = []) {
        // Validate inputs and parameter counts
        if (!$this->validateInputs($table, $columns, $params) || !$this->validateCounts($columns, $params, 'Insert')) {
            return null;
        }

        try {
            $this->beginTransaction();  // Start transaction
            // Prepare the INSERT statement
            $placeholders = ':' . implode(', :', $columns);
            $query = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})";

            // Build parameters array for execution
            $queryParams = [];
            foreach ($columns as $index => $column) {
                $queryParams[":{$column}"] = $params[$index];
            }

            $this->executeQuery($query, $queryParams);  // Execute the insert query
            $this->commit();  // Commit transaction
        } catch (PDOException $e) {
            // Roll back transaction and handle errors
            $this->handleError("Insert failed", $e->getMessage(), [
                'query' => $query,
                'params' => $queryParams
            ], $e->getCode());
            $this->rollBack();
            return null;
        }
    }

    // Private method to build named parameters for a prepared statement
    private function buildNamedParams($keys = [], $values = []) {
        $params = [];

        foreach ($keys as $index => $key) {
            $params[":{$key}"] = $values[$index];  // Associate named placeholders with values
        }

        return $params;  // Return the parameters array
    }

    // Private method to handle errors and optionally return structured error info
    private function handleError($message, $errorDetail, $context = [], $errorCode = null) {
        // Prepare error context with SQL error code if available
        $logContext = $context;
        if ($errorCode !== null) {
            $logContext['sql_error_code'] = $errorCode;
        }
    
        // Create a formatted error message
        $formattedMessage = "=============== ERROR | " . date('Y-m-d H:i:s') . " ===============\n";
        $formattedMessage .= "Error Message: " . $message . "\n";
        $formattedMessage .= "Error Details: " . $errorDetail . "\n";
        if (!empty($logContext)) {
            $formattedMessage .= "Context: " . json_encode($logContext, JSON_PRETTY_PRINT) . "\n";
        }
        $formattedMessage .= "===========================================================\n\n";
    
        // Log the formatted message to the error log file
        file_put_contents($this->errorLogFile, $formattedMessage, FILE_APPEND);
    
        // Return structured error info for further handling
        return [
            'success' => false,
            'message' => $message,
            'error' => $errorDetail,
            'context' => $logContext
        ];
    }

    // Private method to validate input parameters for SQL queries
    private function validateInputs($table, $columns, $params) {
        if (!is_string($table)) {
            // Validate table name is a string
            return $this->handleError("Invalid table name provided", 'Expected string, received: ' . gettype($table));
        }
        
        if (!is_array($columns) || !is_array($params)) {
            // Validate columns and params are arrays
            return $this->handleError("Columns and parameters must be arrays", '', [
                'columns' => gettype($columns),
                'params' => gettype($params)
            ]);
        }

        foreach ($columns as $column) {
            // Validate column names are safe
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                return $this->handleError("Invalid column name detected", '', $column);
            }
        }
        return true;
    }
}
?>
