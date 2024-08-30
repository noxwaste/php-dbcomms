# dbcomms PHP Class

**Version:** 1.6

**Author:** rlford ([https://github.com/rlford](https://github.com/rlford))

## Overview

The `dbcomms` class is a PHP library designed to simplify database operations using PDO (PHP Data Objects). It provides an easy-to-use interface for performing common CRUD (Create, Read, Update, Delete) operations and managing database connections. The class ensures secure and efficient interactions with a MySQL database, minimizing the risk of SQL injection through the use of prepared statements.

## Features

- Connect to and disconnect from a MySQL database using PDO.
- Perform secure SQL queries for common CRUD operations:
  - **Insert** rows into a table.
  - **Fetch** single or multiple rows from a table.
  - **Update** rows in a table based on specific conditions.
  - **Delete** rows from a table based on specific conditions.
  - **Count** the number of rows that match specific conditions.
  - **Aggregate** functions (e.g., SUM, AVG, MIN, MAX) for columns in a table.
- **Transaction management** for safe, multiple-operation executions:
  - Begin, commit, and rollback transactions.
- **Error handling and logging** for all database operations.
- Centralized query execution and parameter management.
- **Support for pagination** through `LIMIT` and `OFFSET`.

## Requirements

- PHP 7.0 or higher.
- PDO extension enabled.
- MySQL database.

## Installation

To use the `dbcomms` class, simply download the `dbcomms.php` file and include it in your project:

```php
require_once 'path/to/dbcomms.php';
```

## Usage

### 1. Connect to the Database

Create an instance of the dbcomms class by providing your database connection details. You can also pass an array of PDO options to configure the connection further.

**Basic Example:**

```php
$db = new dbcomms('dbhost', 'dbname', 'dbuser', 'dbpass');
```

**Example with PDO Options:**

```php
$options = [
    PDO::ATTR_PERSISTENT => true, // Use persistent connections
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch associative arrays by default
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4", // Set character encoding
];

$db = new dbcomms('dbhost', 'dbname', 'dbuser', 'dbpass', $options);
```

**Useful PDO Options**

Here are some common and useful PDO options you might want to consider:

`PDO::ATTR_PERSISTENT`: Enables persistent connections to the database, which can improve performance by reducing the overhead of establishing new connections repeatedly. Use this with caution, as it may not be suitable for all applications.

```php
PDO::ATTR_PERSISTENT => true
```

`PDO::ATTR_ERRMODE`: Controls the error reporting mode. The most common setting is PDO::ERRMODE_EXCEPTION, which throws exceptions on errors, making it easier to handle and debug them.

```php
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
```

`PDO::ATTR_DEFAULT_FETCH_MODE`: Sets the default fetch mode for result sets. PDO::FETCH_ASSOC is often used to return results as associative arrays.

```php
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
```

`PDO::MYSQL_ATTR_INIT_COMMAND`: Specifies a command to execute when connecting to the MySQL server, such as setting the character set. This can ensure that the connection uses UTF-8 encoding, which is recommended for supporting a wide range of characters.

```php
PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
```

`PDO::ATTR_TIMEOUT`: Sets a timeout period (in seconds) for database connection attempts. This is useful to prevent hanging indefinitely if the database server is unreachable.

```php
PDO::ATTR_TIMEOUT => 30
```

`PDO::MYSQL_ATTR_SSL_CA`, `PDO::MYSQL_ATTR_SSL_CERT`, `PDO::MYSQL_ATTR_SSL_KEY`: These options are used for SSL encryption in MySQL connections. They specify the paths to the certificate authority file, client certificate file, and client key file, respectively, to establish a secure connection to the database.

```php
PDO::MYSQL_ATTR_SSL_CA => '/path/to/ca-cert.pem',
PDO::MYSQL_ATTR_SSL_CERT => '/path/to/client-cert.pem',
PDO::MYSQL_ATTR_SSL_KEY => '/path/to/client-key.pem'
```

### 2. Fetch a Single Row

Retrieve a single row from a table based on specific conditions.

```php
$user = $db->getRow('users', ['username'], ['='], ['john_doe']);
print_r($user);
```

### 3. Fetch Multiple Rows

Retrieve multiple rows from a table based on specific conditions, sorted, and paginated.

```php
$users = $db->getRows('users', ['status'], ['='], ['active'], 'created_at', 'DESC', 10, 0); // Fetch 10 rows, starting from offset 0
print_r($users);
```

### 4. Update a Row

Update a row in the specified table based on specific conditions. This operation uses a transaction to maintain consistency.

```php
$db->updateRow('users', 'email', 'john_new@example.com', ['username'], ['='], ['john_doe']);
```

### 5. Delete a Row

Delete a row from the specified table based on specific conditions. This operation is also wrapped in a transaction.

```php
$db->deleteRow('users', ['username'], ['='], ['john_doe']);
```

### 6. Count Rows

Count the number of rows in a table that match specific conditions.

```php
$count = $db->countRows('users', ['status'], ['='], ['active']);
echo "Active users: " . $count;
```

### 7. Get Aggregate Data

Fetch aggregate data (e.g., SUM, AVG) from a specific column in a table based on certain conditions.

```php
$totalSalary = $db->getAggregate('employees', 'SUM', 'salary', ['department'], ['='], ['IT']);
echo "Total Salary in IT Department: " . $totalSalary;
```

### 8. Insert a Row

Insert a new row into the specified table. This operation is wrapped in a transaction to ensure data integrity.

```php
$db->insertRow('users', ['username','email','password'], ['john_doe','john@example.com','hashed_password']);
```

### 9. Disconnect from the Database

Ensure to disconnect from the database when operations are complete.

```php
$db->disconnect();
```

## `getDb()` Method

### How It Works

- **Check for a Valid PDO Instance**: The method checks if the internal database connection (`$datab`) is an instance of the PDO class.
- **Return the PDO Object**: If the connection is valid, `getDb()` returns the PDO instance, which can then be used to execute any SQL queries or commands.
- **Return `null` if Not Connected**: If the connection is not established or fails, the method returns `null`, indicating that no database operations can be performed.

### Use Cases

The `getDb()` method is particularly useful in scenarios where predefined methods of the `dbcomms` class do not cover all the needs of the application. For example, if a developer needs to execute a complex SQL query with custom joins, aggregate functions, or perform advanced transaction management, they can use the PDO instance directly.

### Example Usage

Below is an example of how to use the `getDb()` method to perform a custom SQL query:

```php
<?php
require_once("path/to/dbcomms.php");

// Initialize the dbcomms class with your database credentials
$dbcomms = new dbcomms('localhost', 'database_name', 'username', 'password');

// Retrieve the PDO instance using the getDb() method
$pdo = $dbcomms->getDb();

if ($pdo) {
    // Prepare a custom SQL query using the PDO instance
    $sql = "SELECT name, email FROM users WHERE status = :status ORDER BY created_at DESC";

    // Prepare the statement
    $stmt = $pdo->prepare($sql);

    // Execute the statement with bound parameters
    $stmt->execute(['status' => 'active']);

    // Fetch the results
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Display the results
    echo "<pre>";
    print_r($results);
    echo "</pre>";
} else {
    echo "Unable to connect to the database.";
}
?>
```

## Error Handling and Logging

The dbcomms class includes built-in error handling and logging. Errors are logged to a file `dbcomms.log` with details about the error message, context, and timestamp. This helps in debugging and maintaining a history of issues encountered during database operations.

## Transaction Management

The class supports transaction management to ensure safe execution of multiple database operations. You can manually begin, commit, or roll back transactions using the methods provided:

- `beginTransaction()`
- `commit()`
- `rollBack()`

Transactions are automatically managed for operations like `insertRow`, `updateRow`, and `deleteRow` to maintain data integrity.

## Dynamic Query Building

The class provides methods to dynamically build SQL queries (`buildQuery`) and parameters (`buildNamedParams`). This allows for flexible and secure query construction based on user input.

## Security Features

- **Prepared Statements:** Protect against SQL injection by using prepared statements for all database operations.
- **Parameterized Queries:** Ensure safe execution of SQL queries by using parameterized inputs.

## Performance Considerations

- **Persistent Connections:** Optionally enable persistent database connections to improve performance by reducing connection overhead.
- **Efficient Query Execution:** Centralized query execution ensures consistent and optimized performance across all operations.

## Compatibility

The class is compatible with PHP 7.0 or higher and requires the PDO extension with MySQL support. Ensure your environment meets these requirements to use the class effectively.

## Common Pitfalls or Errors

- **Mismatch Between Columns and Parameters:** Ensure the number of columns matches the number of parameters in operations like `insertRow` and `updateRow`.
- **Correct Query Syntax:** Verify that the conditions and operators provided in methods match the expected formats.

## Extensibility

The class is designed to be extensible. You can add new methods or modify existing ones to suit your application's specific needs. Follow the structure and conventions used in the class to maintain consistency.

## License

This project is licensed under the GNU General Public License v3.0. See the LICENSE file for more details.

## Contributing

Feel free to submit issues or pull requests. Contributions are welcome!
