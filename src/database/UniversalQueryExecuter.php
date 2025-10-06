<?php

namespace Gemvc\Database;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Universal Query Executer for Multiple Web Server Environments
 * 
 * This class provides a unified interface for database query execution
 * across different web server environments:
 * - Apache PHP-FPM
 * - Nginx PHP-FPM  
 * - OpenSwoole
 * 
 * It automatically detects the environment and uses the appropriate
 * database manager implementation.
 */
class UniversalQueryExecuter
{
    private ?string $error = null;
    private int $affectedRows = 0;
    private string|false $lastInsertedId = false;
    private ?PDOStatement $statement = null;
    private float $startExecutionTime;
    private ?float $endExecutionTime = null;
    private string $query = '';
    /** @var array<string, mixed> */
    private array $bindings = [];
    private bool $inTransaction = false;

    /** @var \PDO|null The database connection */
    private ?\PDO $db = null;

    /** @var DatabaseManagerInterface The database manager */
    private DatabaseManagerInterface $dbManager;

    /**
     * Constructor - automatically detects environment and uses appropriate manager
     */
    public function __construct()
    {
        $this->startExecutionTime = microtime(true);
        $this->dbManager = DatabaseManagerFactory::getManager();
    }

    /**
     * Get a connection from the appropriate manager
     * 
     * @param string $poolName The connection pool name (default: 'default')
     * @return \PDO|null A PDO connection, or null on error
     */
    private function getConnection(string $poolName = 'default'): ?\PDO
    {
        $conn = $this->dbManager->getConnection($poolName);
        
        // Propagate error from DatabaseManager to QueryExecuter
        if ($conn === null) {
            $this->setError('Connection error: ' . ($this->dbManager->getError() ?? 'Unknown error'));
        }
        
        return $conn;
    }

    /**
     * Destructor ensures that resources are cleaned up
     */
    public function __destruct()
    {
        // Force rollback if a transaction is still open when the object is destroyed
        $this->secure(true);
    }

    private function debug(string $message): void
    {
        if (($_ENV['APP_ENV'] ?? '') === 'dev') {
            echo $message . PHP_EOL;
        }
    }

    public function getQuery(): ?string
    {
        return $this->query ?: null;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setError(?string $error, array $context = []): void
    {
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
     * Prepares a new SQL query for execution
     *
     * @param string $query The SQL query string
     */
    public function query(string $query): void
    {
        $this->setError(null);

        if (empty($query)) {
            $this->setError('Query cannot be empty');
            return;
        }

        if (strlen($query) > 1000000) { // 1MB limit
            $this->setError('Query exceeds maximum length');
            return;
        }

        if ($this->statement) {
            $this->statement->closeCursor();
            $this->statement = null;
        }
        $this->bindings = [];
        $this->query = $query;

        try {
            if (!$this->db) {
                $this->db = $this->getConnection();
                if ($this->db === null) {
                    // Error already set by getConnection()
                    return;
                }
            }
            $this->statement = $this->db->prepare($query);
        } catch (Throwable $e) {
            $this->setError('Error preparing statement: ' . $e->getMessage());
            $this->releaseConnection(true); // Release potentially broken connection
        }
    }

    /**
     * Binds a value to a corresponding named or question mark placeholder in the SQL statement
     *
     * @param string $param Parameter identifier
     * @param mixed $value The value to bind to the parameter
     */
    public function bind(string $param, mixed $value): void
    {
        $this->setError(null);

        if (!$this->statement) {
            $this->setError('Cannot bind parameters: No statement prepared');
            return;
        }

        $type = match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };

        try {
            $this->statement->bindValue($param, $value, $type);
            $this->bindings[$param] = $value;
        } catch (\PDOException $e) {
            $this->setError('Error binding parameter: ' . $e->getMessage());
        }
    }

    /**
     * Executes the prepared statement
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function execute(): bool
    {
        $this->setError(null);
        $this->affectedRows = 0;
        $this->lastInsertedId = false;

        if (!$this->statement) {
            $this->setError('No statement prepared to execute');
            $this->endExecutionTime = microtime(true);
            return false;
        }

        try {
            $this->statement->execute();
            $this->affectedRows = $this->statement->rowCount();
            if (stripos(trim($this->query), 'INSERT') === 0 && $this->db !== null) {
                $this->lastInsertedId = $this->db->lastInsertId();
            }
            $this->endExecutionTime = microtime(true);
            
            // Auto-release connection for non-SELECT queries if not in transaction
            // SELECT queries need to keep connection open for fetching
            if (!$this->inTransaction && stripos(trim($this->query), 'SELECT') !== 0) {
                $this->statement->closeCursor();
                $this->releaseConnection();
            }
            
            return true;
        } catch (\PDOException $e) {
            $context = [
                'query' => $this->query,
                'bindings' => $this->bindings,
                'execution_time' => $this->getExecutionTime(),
                'in_transaction' => $this->inTransaction,
                'error_code' => $e->getCode()
            ];
            
            $errorDetails = json_encode(['message' => $e->getMessage(), 'code' => $e->getCode(), 'query' => $this->query, 'bindings' => $this->bindings]);
            error_log("UniversalQueryExecuter::execute() - PDO Exception: " . $errorDetails);
            
            $this->setError($e->getMessage(), $context);
            $this->endExecutionTime = microtime(true);
            // Release potentially broken connection
            $this->releaseConnection(true);
            return false;
        }
    }

    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }

    public function getLastInsertedId(): false|string
    {
        return $this->lastInsertedId;
    }

    public function getExecutionTime(): float
    {
        if ($this->endExecutionTime === null) {
            return 0;
        }
        return round(($this->endExecutionTime - $this->startExecutionTime) * 1000, 2);
    }

    /**
     * @return array<object>|false
     */
    public function fetchAllObjects(): array|false
    {
        if (!$this->statement) { $this->setError('No statement executed.'); return false; }
        try {
            $result = $this->statement->fetchAll(PDO::FETCH_OBJ);
            $this->statement->closeCursor();
            
            // Auto-release connection if not in transaction
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            
            return $result;
        } catch (\PDOException $e) {
            $this->setError('Error fetching objects: ' . $e->getMessage());
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            return false;
        }
    }

    /**
     * @return array<array<string, mixed>>|false
     */
    public function fetchAll(): array|false
    {
        if (!$this->statement) { $this->setError('No statement executed.'); return false; }
        try {
            $result = $this->statement->fetchAll(PDO::FETCH_ASSOC);
            $this->statement->closeCursor();
            
            // Auto-release connection if not in transaction
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            
            return $result;
        } catch (\PDOException $e) {
            $this->setError('Error fetching results: ' . $e->getMessage());
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            return false;
        }
    }

    public function fetchColumn(): mixed
    {
        if (!$this->statement) { $this->setError('No statement executed.'); return false; }
        try {
            $result = $this->statement->fetchColumn();
            $this->statement->closeCursor();
            
            // Auto-release connection if not in transaction
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            
            return $result;
        } catch (\PDOException $e) {
            $this->setError('Error fetching column: ' . $e->getMessage());
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            return false;
        }
    }

    /**
     * Fetches a single row as an associative array
     *
     * @return array<string, mixed>|false Returns the row as an associative array, or false on failure
     */
    public function fetchOne(): array|false
    {
        if (!$this->statement) { $this->setError('No statement executed.'); return false; }
        try {
            $result = $this->statement->fetch(PDO::FETCH_ASSOC);
            $this->statement->closeCursor();
            
            // Auto-release connection if not in transaction
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            
            if ($result === false) {
                return false;
            }
            /** @var array<string, mixed> $result */
            return $result;
        } catch (\PDOException $e) {
            $this->setError('Error fetching row: ' . $e->getMessage());
            if (!$this->inTransaction) {
                $this->releaseConnection();
            }
            return false;
        }
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) { $this->setError('Already in transaction'); return false; }
        if ($this->db) { $this->setError('Cannot start transaction, a connection is already active'); return false; }

        try {
            $this->db = $this->getConnection();
            if ($this->db === null) {
                // Error already set by getConnection()
                return false;
            }
            $this->db->beginTransaction();
            $this->inTransaction = true;
            return true;
        } catch (Throwable $e) {
            $this->setError('Error starting transaction: ' . $e->getMessage());
            $this->releaseConnection(true);
            return false;
        }
    }

    public function commit(): bool
    {
        if (!$this->inTransaction || !$this->db) { $this->setError('No active transaction to commit'); return false; }
        try {
            $this->db->commit();
            $this->inTransaction = false;
            return true;
        } catch (Throwable $e) {
            $this->setError('Error committing transaction: ' . $e->getMessage());
            return false;
        } finally {
            $this->releaseConnection();
        }
    }

    public function rollback(): bool
    {
        if (!$this->inTransaction || !$this->db) { $this->setError('No active transaction to rollback'); return false; }
        try {
            $this->db->rollBack();
            $this->inTransaction = false;
            return true;
        } catch (Throwable $e) {
            $this->setError('Error rolling back transaction: ' . $e->getMessage());
            return false;
        } finally {
            $this->releaseConnection();
        }
    }

    /**
     * Securely clean up database resources
     */
    public function secure(bool $forceRollback = false): void
    {
        if ($this->inTransaction && $this->db && $forceRollback) {
            try {
                $this->db->rollBack();
                $this->debug("Transaction rolled back in secure()");
            } catch (Throwable $e) {
                error_log('Error during forced rollback in secure(): ' . $e->getMessage());
            }
        }
        $this->releaseConnection();
    }

    /**
     * Release the current database connection back to the pool
     */
    private function releaseConnection(bool $isBroken = false): void
    {
        if ($this->statement) {
            $this->statement->closeCursor();
            $this->statement = null;
        }
        if ($this->db) {
            try {
                $this->dbManager->releaseConnection($this->db);
                if ($isBroken) {
                    $this->debug("Broken connection released back to pool.");
                }
            } catch (Throwable $e) {
                error_log('Error releasing connection: ' . $e->getMessage());
            }
        }
        $this->db = null;
        $this->inTransaction = false;
    }

    /**
     * Get information about the current database manager
     * 
     * @return array<string, mixed> Manager information
     */
    public function getManagerInfo(): array
    {
        return DatabaseManagerFactory::getManagerInfo();
    }
}
