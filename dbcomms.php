<?php
class dbcomms {
    // Public property to track if the database connection is established
    public $isConnected;

    // Protected properties for the PDO instance and error log file path
    protected $datab;
    protected $errorLogFile = 'error_log.txt';

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
    
        $query = $this->buildQuery('SELECT', $table, $conditions, $operators, 'LIMIT 1', $logicalOperator);
        $queryParams = $this->buildNamedParams($conditions, $params);
        return $this->executeQuery($query, $queryParams, true);
    }

    // Public method to get multiple rows from a table based on conditions
    public function getRows($table, $conditions = [], $operators = [], $params = [], $orderBy = 'id', $ascOrDesc = 'ASC', $limit = null, $offset = null, $logicalOperator = 'AND') {
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Get rows')) {
            return null;
        }
    
        $extra = "ORDER BY `{$orderBy}` {$ascOrDesc}";
        if ($limit !== null) {
            $extra .= " LIMIT {$limit}";
            if ($offset !== null) {
                $extra .= " OFFSET {$offset}";
            }
        }
    
        $query = $this->buildQuery('SELECT', $table, $conditions, $operators, $extra, $logicalOperator);
        $queryParams = $this->buildNamedParams($conditions, $params);
        return $this->executeQuery($query, $queryParams);
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

    // Public method to update a row in a table based on conditions
    public function updateRow($table, $column, $value, $conditions = [], $operators = [], $params = [], $logicalOperator = 'AND') {
        // Validate inputs and parameter counts
        if (!$this->validateInputs($table, $conditions, $params) || !$this->validateCounts($conditions, $params, 'Update')) {
            return null;
        }
    
        try {
            $this->beginTransaction();  // Start transaction
    
            // Build the WHERE clause dynamically using the buildQuery method
            $whereClause = $this->buildQuery('', '', $conditions, $operators, '', $logicalOperator);
    
            // Prepare the UPDATE statement correctly
            $query = "UPDATE `{$table}` SET `{$column}` = :value {$whereClause}";
            
            // Merge parameters for the SET and WHERE clauses
            $paramsArray = array_merge([':value' => $value], $this->buildNamedParams($conditions, $params));
    
            $this->executeQuery($query, $paramsArray);  // Execute the update query
            $this->commit();  // Commit transaction
        } catch (PDOException $e) {
            // Roll back transaction and handle errors
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
            $query = $this->buildQuery('DELETE', $table, $conditions, $operators, '', $logicalOperator);
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
    
        $query = $this->buildQuery('COUNT', $table, $conditions, $operators, '', $logicalOperator);
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
    
        $extra = "{$aggregateFunction}(`{$column}`) AS aggregate";
        $query = $this->buildQuery('AGGREGATE', $table, $conditions, $operators, $extra, $logicalOperator);
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

    // Private method to build a dynamic SQL query
    private function buildQuery($action, $table, $conditions = [], $operators = [], $extra = '', $logicalOperator = 'AND') {
        // Initialize the query
        $query = "";
    
        // Construct the base query depending on the action
        switch ($action) {
            case 'SELECT':
                $query = "SELECT * FROM `{$table}`";  // Start building the SELECT query
                break;
            case 'COUNT':
                $query = "SELECT COUNT(*) AS count FROM `{$table}`";  // Start building the COUNT query
                break;
            case 'AGGREGATE':
                // For aggregate, the $extra should contain the aggregate function and column
                $query = "SELECT {$extra} FROM `{$table}`";  // Build the aggregate query
                break;
            case 'DELETE':
                $query = "DELETE FROM `{$table}`";  // Start building the DELETE query
                break;
            case 'UPDATE':
                $query = "UPDATE `{$table}`";  // Start building the UPDATE query
                break;
            default:
                return $this->handleError("Invalid action", "The action {$action} is not supported in buildQuery.");
        }
    
        // Add conditions if provided
        if (!empty($conditions)) {
            $conditionStrings = [];
    
            foreach ($conditions as $index => $condition) {
                $conditionStrings[] = "`{$condition}` {$operators[$index]} :{$condition}";  // Match named parameters
            }
    
            // Add WHERE clause for conditions
            $query .= " WHERE " . implode(" {$logicalOperator} ", $conditionStrings);
        }
    
        // Append any additional clauses (like LIMIT, ORDER BY)
        return trim("{$query} {$extra}");  // Return the complete query with any extra clauses, and trim any extra spaces
    }

    // Private method to build named parameters for a prepared statement
    private function buildNamedParams($keys = [], $values = []) {
        $params = [];

        foreach ($keys as $index => $key) {
            $params[":{$key}"] = $values[$index];  // Associate named placeholders with values
        }

        return $params;  // Return the parameters array
    }

    // Private method to log errors to a file
    private function logError($message, $context = []) {
        $logMessage = date('Y-m-d H:i:s') . " - " . $message;  // Timestamp the error message
        if (!empty($context)) {
            $logMessage .= ' | Context: ' . json_encode($context);  // Add context if available
        }
        file_put_contents($this->errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);  // Append error to log file
    }

    // Private method to handle errors and optionally return structured error info
    private function handleError($message, $errorDetail, $context = [], $errorCode = null) {
        $logContext = $context;
        if ($errorCode !== null) {
            $logContext['sql_error_code'] = $errorCode;  // Include SQL error code in context
        }
        $this->logError($message, $logContext);  // Log the error
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
