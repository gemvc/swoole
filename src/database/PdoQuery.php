<?php

namespace Gemvc\Database;
use Gemvc\Database\QueryExecuter;
use Gemvc\Database\DatabaseManager;

/**
 * PdoQuery uses QueryExecuter as a component with lazy loading to provide high-level methods for common database operations
 * All methods follow the unified return pattern: result|null where null indicates error and result indicates success
 */
class PdoQuery
{
    /** @var QueryExecuter|null Lazy-loaded query executor */
    private ?QueryExecuter $executer = null;
    
    /** @var bool Whether we have an active database connection */
    private bool $isConnected = false;

    /** @var string|null Stores the last error message */
    private ?string $error = null;

    /**
     * Clean constructor - no parameters needed!
     * Everything is handled automatically through the singleton DatabaseManager.
     */
    public function __construct()
    {
        // No configuration needed - QueryExecuter handles everything
    }

    /**
     * Lazy initialization of QueryExecuter.
     * Connection is automatically acquired from the pool when needed.
     */
    private function getExecuter(): QueryExecuter
    {
        if ($this->executer === null) {
            $this->executer = new QueryExecuter();
            $this->isConnected = true;
        }
        
        // Propagate errors from QueryExecuter to PdoQuery
        if ($this->executer->getError() !== null) {
            $this->setError($this->executer->getError());
        }
        
        return $this->executer;
    }

    /**
     * Execute an INSERT query and return the last inserted ID
     * 
     * @param string $query The SQL INSERT query
     * @param array<string, mixed> $params Key-value pairs for parameter binding
     * @return int|null The last inserted ID (or 1 for tables without auto-increment) on success, null on failure
     */
    public function insertQuery(string $query, array $params = []): int|null
    {
        $success = false;
        try {
            if ($this->executeQuery($query, $params)) {
                // Check if there were any errors during execution
                if ($this->getExecuter()->getError() !== null) {
                    return null;
                }
                
                // Try to get the last inserted ID
                $lastId = $this->getExecuter()->getLastInsertedId();
                
                // If lastId is a valid ID (not 0 or false), return it as integer
                if ($lastId && is_numeric($lastId) && (int)$lastId > 0) {
                    $success = true;
                    return (int)$lastId;
                }
                
                // If no auto-increment ID but query was successful, 
                // check affected rows to confirm insert success
                $affectedRows = $this->getExecuter()->getAffectedRows();
                if ($affectedRows > 0) {
                    // Insert was successful but table has no auto-increment ID
                    // Return 1 to indicate success
                    $success = true;
                    return 1;
                }
                
                // No rows were affected, something went wrong
                $this->setError('Insert query executed but no rows were affected');
                return null;
            }
            return null;
        } catch (\PDOException $e) {
            $this->handleInsertError($e);
            return null;
        } finally {
            // Ensure connection is returned to Hyperf pool
            // secure(false) releases connection without forcing rollback
            if ($this->executer !== null) {
                $this->getExecuter()->secure(!$success);
            }
        }
    }

    /**
     * Handle insert operation errors with special handling for duplicate key constraints
     * 
     * @param \PDOException $e The exception that was thrown
     */
    private function handleInsertError(\PDOException $e): void
    {
        $sqlState = $e->getCode();
        $errorInfo = $e->errorInfo ?? [];
        
        // Log the full PDO exception details
        error_log("PdoQuery::handleInsertError() - PDO Exception: " . json_encode([
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'errorInfo' => $errorInfo,
            'trace' => $e->getTraceAsString()
        ]));
        
        // Check for duplicate key/unique constraint violations
        // MySQL: SQLSTATE 23000, Error code 1062
        // PostgreSQL: SQLSTATE 23505 (unique_violation)
        // SQLite: SQLSTATE 23000, Error code 19 or 1555
        if (
            $sqlState === '23000' || 
            $sqlState === '23505' || 
            (isset($errorInfo[1]) && ($errorInfo[1] === 1062 || $errorInfo[1] === 19 || $errorInfo[1] === 1555)) ||
            stripos($e->getMessage(), 'duplicate') !== false ||
            stripos($e->getMessage(), 'unique') !== false ||
            stripos($e->getMessage(), 'already exists') !== false
        ) {
            $this->setError('This record cannot be created because a record with the same unique information already exists. Please use different values.');
        } else {
            // Use the general error handler for other types of errors
            $this->handleQueryError('Insert', $e);
        }
    }

    /**
     * Execute an UPDATE query and return the number of affected rows
     * 
     * @param string $query The SQL UPDATE query
     * @param array<string, mixed> $params Key-value pairs for parameter binding
     * @return int|null Number of affected rows (0 if no changes, null on error)
     */
    public function updateQuery(string $query, array $params = []): int|null
    {
        $success = false;
        try {
            if ($this->executeQuery($query, $params)) {
                $affectedRows = $this->getExecuter()->getAffectedRows();
                // Note: 0 affected rows is valid (no changes needed), not an error
                $success = true;
                return $affectedRows;
            }
            return null;
        } catch (\PDOException $e) {
            $this->handleUpdateError($e);
            return null;
        } finally {
            // Ensure connection is returned to Hyperf pool
            if ($this->executer !== null) {
                $this->getExecuter()->secure(!$success);
            }
        }
    }

    /**
     * Handle update operation errors with special handling for duplicate key constraints
     * 
     * @param \PDOException $e The exception that was thrown
     */
    private function handleUpdateError(\PDOException $e): void
    {
        $sqlState = $e->getCode();
        $errorInfo = $e->errorInfo ?? [];
        
        // Check for duplicate key/unique constraint violations
        // MySQL: SQLSTATE 23000, Error code 1062
        // PostgreSQL: SQLSTATE 23505 (unique_violation)
        // SQLite: SQLSTATE 23000, Error code 19 or 1555
        if (
            $sqlState === '23000' || 
            $sqlState === '23505' || 
            (isset($errorInfo[1]) && ($errorInfo[1] === 1062 || $errorInfo[1] === 19 || $errorInfo[1] === 1555)) ||
            stripos($e->getMessage(), 'duplicate') !== false ||
            stripos($e->getMessage(), 'unique') !== false ||
            stripos($e->getMessage(), 'already exists') !== false
        ) {
            $this->setError('This record cannot be updated because another record with the same unique information already exists. Please use different values.');
        } else {
            // Use the general error handler for other types of errors
            $this->handleQueryError('Update', $e);
        }
    }

    /**
     * Execute a DELETE query and return the number of affected rows
     * 
     * @param string $query The SQL DELETE query
     * @param array<string, mixed> $params Key-value pairs for parameter binding
     * @return int|null Number of affected rows (0 if no records found, null on error)
     */
    public function deleteQuery(string $query, array $params = []): int|null
    {
        $success = false;
        try {
            if ($this->executeQuery($query, $params)) {
                $affectedRows = $this->getExecuter()->getAffectedRows();
                // Note: 0 affected rows is valid (record not found), not an error
                $success = true;
                return $affectedRows;
            }
            return null;
        } catch (\PDOException $e) {
            $this->handleDeleteError($e);
            return null;
        } finally {
            // Ensure connection is returned to Hyperf pool
            if ($this->executer !== null) {
                $this->getExecuter()->secure(!$success);
            }
        }
    }

    /**
     * Handle delete operation errors with special handling for foreign key constraints
     * 
     * @param \PDOException $e The exception that was thrown
     */
    private function handleDeleteError(\PDOException $e): void
    {
        $sqlState = $e->getCode();
        $errorInfo = $e->errorInfo ?? [];
        
        // Check for foreign key constraint violations
        // MySQL: SQLSTATE 23000, Error code 1451
        // PostgreSQL: SQLSTATE 23503  
        // SQLite: SQLSTATE 23000, Error code 787
        if (
            $sqlState === '23000' || 
            $sqlState === '23503' || 
            (isset($errorInfo[1]) && ($errorInfo[1] === 1451 || $errorInfo[1] === 787)) ||
            stripos($e->getMessage(), 'foreign key constraint') !== false ||
            stripos($e->getMessage(), 'cannot delete') !== false
        ) {
            $this->setError('This record cannot be deleted because it has related data in other tables. Please remove the related records first.');
        } else {
            // Use the general error handler for other types of errors
            $this->handleQueryError('Delete', $e);
        }
    }

    /**
     * Execute a SELECT query and return results as objects
     * 
     * @param string $query The SQL SELECT query
     * @param array<string, mixed> $params Key-value pairs for parameter binding
     * @return array|null Array of objects (empty array if no results), null on error
     */
    public function selectQueryObjects(string $query, array $params = []): array|null
    {
        try {
            if ($this->executeQuery($query, $params)) {
                $result = $this->getExecuter()->fetchAllObjects();
                if ($result === false) {
                    $this->setError('Failed to fetch results from the query');
                    return null;
                }
                // Empty array is valid - means no results found
                return $result;
            }
            return null;
        } catch (\PDOException $e) {
            $this->handleQueryError('Select objects', $e);
            return null;
        } finally {
            // Ensure connection is returned to Hyperf pool for SELECT queries
            // secure(false) releases without rollback
            if ($this->executer !== null) {
                $this->getExecuter()->secure(false);
            }
        }
    }

    /**
     * Execute a SELECT query and return results as associative arrays
     * 
     * @param string $query The SQL SELECT query
     * @param array<string, mixed> $params Key-value pairs for parameter binding
     * @return array|null Array of rows (empty array if no results), null on error
     */
    public function selectQuery(string $query, array $params = []): array|null
    {
        try {
            if ($this->executeQuery($query, $params)) {
                $result = $this->getExecuter()->fetchAll();
                if ($result === false) {
                    $this->setError('Failed to fetch results from the query');
                    return null;
                }
                // Empty array is valid - means no results found
                return $result;
            }
            return null;
        } catch (\PDOException $e) {
            $this->handleQueryError('Select', $e);
            return null;
        } finally {
            // Ensure connection is returned to Hyperf pool for SELECT queries
            // secure(false) releases without rollback
            if ($this->executer !== null) {
                $this->getExecuter()->secure(false);
            }
        }
    }

    /**
     * Execute a COUNT query and return the result as an integer
     * 
     * @param string $query The SQL SELECT COUNT query
     * @param array<string, mixed> $params Key-value pairs for parameter binding
     * @return int|null The count result (0 if no records), null on error
     */
    public function selectCountQuery(string $query, array $params = []): int|null
    {
        try {
            if ($this->executeQuery($query, $params)) {
                $result = $this->getExecuter()->fetchColumn();
                if ($result === false) {
                    $this->setError('Failed to fetch count result');
                    return null;
                }
                
                if (is_numeric($result)) {
                    return (int)$result;
                } else {
                    $this->setError('Count query did not return a numeric value');
                    return null;
                }
            }
            return null;
        } catch (\PDOException $e) {
            $this->handleQueryError('Count', $e);
            return null;
        } finally {
            // Ensure connection is returned to Hyperf pool for COUNT queries
            // secure(false) releases without rollback
            if ($this->executer !== null) {
                $this->getExecuter()->secure(false);
            }
        }
    }

    /**
     * Execute a query with parameter binding
     * 
     * @param string $query The SQL query to execute
     * @param array<string, mixed> $params Key-value pairs for parameter binding
     * @return bool True on success, false on failure
     */
    private function executeQuery(string $query, array $params): bool
    {
        try {
            // Connection is created only when this method is called
            $executer = $this->getExecuter();
            
            $executer->query($query);
            
            // Propagate error from QueryExecuter
            if ($executer->getError() !== null) {
                $this->setError($executer->getError());
                return false;
            }
            
            foreach ($params as $key => $value) {
                $executer->bind($key, $value);
                
                // Check for binding errors
                if ($executer->getError() !== null) {
                    $this->setError($executer->getError());
                    return false;
                }
            }
            
            $result = $executer->execute();
            
            // Propagate execution errors
            if (!$result && $executer->getError() !== null) {
                $this->setError($executer->getError());
            }
            
            return $result;
        } catch (\PDOException $e) {
            $this->handleQueryError('Query execution', $e);
            return false;
        }
    }

    /**
     * Handle query errors consistently with retry logic for transient errors
     * 
     * @param string $operation The operation that failed
     * @param \PDOException $e The exception that was thrown
     */
    private function handleQueryError(string $operation, \PDOException $e): void
    {
        $context = [
            'operation' => $operation,
            'error_code' => $e->getCode(),
            'sql_state' => $e->errorInfo[0] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Check for transient errors that might be retryable
        $isTransient = $this->isTransientError($e);
        if ($isTransient) {
            $context['retryable'] = true;
            $context['suggestion'] = 'This error might be temporary. Consider retrying the operation.';
        }
        
        $errorMessage = sprintf(
            '%s operation failed: %s (Code: %s)',
            $operation,
            $e->getMessage(),
            $e->getCode()
        );
        
        $this->setError($errorMessage, $context);
        error_log($errorMessage . "\nStack trace: " . $e->getTraceAsString());
    }

    /**
     * Check if an error is transient and potentially retryable
     * 
     * @param \PDOException $e The exception to check
     * @return bool True if the error is transient
     */
    private function isTransientError(\PDOException $e): bool
    {
        $transientCodes = [
            '08000', // Connection exception
            '08003', // Connection does not exist
            '08006', // Connection failure
            '08001', // SQL client unable to establish SQL connection
            '08004', // SQL server rejected establishment of SQL connection
            '08007', // Transaction resolution unknown
            '40001', // Serialization failure
            '40P01', // Deadlock detected
        ];
        
        $errorCode = $e->getCode();
        $sqlState = $e->errorInfo[0] ?? '';
        
        return in_array($sqlState, $transientCodes) || 
               in_array($errorCode, $transientCodes) ||
               stripos($e->getMessage(), 'timeout') !== false ||
               stripos($e->getMessage(), 'connection') !== false ||
               stripos($e->getMessage(), 'deadlock') !== false;
    }

    /**
     * Set error message
     * 
     * @param string|null $error Error message
     */
    public function setError(?string $error, array $context = []): void
    {
        if ($this->executer !== null) {
            $this->executer->setError($error, $context);
        }
        
        // Also set local error for immediate access
        if ($error === null) {
            $this->error = null;
            return;
        }
        
        // Add context information to error message
        if (!empty($context)) {
            $contextStr = ' [Context: ' . json_encode($context) . ']';
            $this->error = $error . $contextStr;
        } else {
            $this->error = $error;
        }
    }

    /**
     * Get error message
     * 
     * @return string|null Error message or null if no error
     */
    public function getError(): ?string
    {
        if ($this->executer !== null) {
            return $this->executer->getError();
        }
        return null;
    }

    /**
     * Check if we have an active connection
     * 
     * @return bool True if connected, false otherwise
     */
    public function isConnected(): bool
    {
        return $this->isConnected && $this->executer !== null;
    }

    /**
     * Force connection cleanup
     */
    public function disconnect(): void
    {
        if ($this->executer !== null) {
            $this->executer->secure();
            $this->executer = null;
            $this->isConnected = false;
        }
    }

    /**
     * Begin a database transaction
     * Connection is created only when this method is called
     * 
     * @return bool True on success, false on failure
     */
    public function beginTransaction(): bool
    {
        return $this->getExecuter()->beginTransaction();
    }

    /**
     * Commit the current transaction
     * 
     * @return bool True on success, false on failure
     */
    public function commit(): bool
    {
        if ($this->executer === null) {
            $this->setError('No active transaction to commit');
            return false;
        }
        return $this->executer->commit();
    }

    /**
     * Rollback the current transaction
     * 
     * @return bool True on success, false on failure
     */
    public function rollback(): bool
    {
        if ($this->executer === null) {
            $this->setError('No active transaction to rollback');
            return false;
        }
        return $this->executer->rollback();
    }

    /**
     * Clear the error message.
     */
    public function clearError(): void
    {
        $this->error = null;
    }

    /**
     * Clean up resources
     */
    public function __destruct()
    {
        if ($this->executer !== null) {
            $this->executer->secure();
            $this->executer = null;
            $this->isConnected = false;
        }
    }
}
