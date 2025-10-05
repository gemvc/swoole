<?php

namespace Gemvc\Database;

use Gemvc\Database\PdoQuery;

/**
 * Enhanced Table class with trait-based organization
 * 
 * This is a new implementation that uses traits to organize functionality
 * while maintaining the same API as the original Table class.
 */
class TableQuery
{
    use CrudValidation;
    use CrudOperations;
    use SpecializedUpdates;

    /** @var PdoQuery|null Lazy-loaded database query instance */
    private ?PdoQuery $_pdoQuery = null;
    
    /** @var string|null Stored error message before PdoQuery is instantiated */
    private ?string $_storedError = null;

    /** @var string|null SQL query being built */
    private ?string $_query = null;
    
    /** @var bool Whether a SELECT query has been initiated */
    private bool $_isSelectSet = false;
    
    /** @var bool Whether to apply limits to the query */
    private bool $_no_limit = false;
    
    /** @var bool Whether to skip count queries for performance */
    private bool $_skip_count = false;
    
    /** @var array<string,mixed> Query parameter bindings */
    private array $_binds = [];
    
    /** @var int Number of rows per page */
    private int $_limit;
    
    /** @var int Pagination offset */
    private int $_offset = 0;
    
    /** @var string ORDER BY clause */
    private string $_orderBy = '';
    
    /** @var int Total count of rows from last query */
    private int $_total_count = 0;
    
    /** @var int Number of pages from last query */
    private int $_count_pages = 0;
    
    /** @var array<string> WHERE clauses */
    private array $_arr_where = [];
    
    /** @var array<string> JOIN clauses */
    private array $_joins = [];

    /** @var array<string, string> Type mapping for property casting */
    protected array $_type_map = [];

    /** @var int|null ID property - defined in child classes */
    public ?int $id = null;

    /**
     * Initialize a new TableQuery instance
     * No database connection is created here - lazy loading
     */
    public function __construct()
    {
        $this->_limit = (isset($_ENV['QUERY_LIMIT']) && is_numeric($_ENV['QUERY_LIMIT'])) 
            ? (int)$_ENV['QUERY_LIMIT'] 
            : 10;
    }

    /**
     * Lazy initialization of PdoQuery
     * Database connection is created only when this method is called
     */
    private function getPdoQuery(): PdoQuery
    {
        if ($this->_pdoQuery === null) {
            $this->_pdoQuery = new PdoQuery();
            // Transfer any stored error to the new PdoQuery instance
            if ($this->_storedError !== null) {
                $this->_pdoQuery->setError($this->_storedError);
                $this->_storedError = null;
            }
        }
        return $this->_pdoQuery;
    }

    /**
     * Set error message - optimized to avoid unnecessary connection creation
     */
    public function setError(?string $error): void
    {
        if ($this->_pdoQuery !== null) {
            $this->_pdoQuery->setError($error);
        } else {
            // Store the error until PdoQuery is instantiated
            $this->_storedError = $error;
        }
    }

    /**
     * Get error message
     */
    public function getError(): ?string
    {
        if ($this->_pdoQuery !== null) {
            return $this->_pdoQuery->getError();
        }
        return $this->_storedError;
    }

    /**
     * Check if we have an active connection
     */
    public function isConnected(): bool
    {
        return $this->_pdoQuery !== null && $this->_pdoQuery->isConnected();
    }

    /**
     * Protected method for traits to set errors
     */
    protected function setTableError(?string $error): void
    {
        $this->setError($error);
    }

    /**
     * Protected method for traits to get errors
     */
    protected function getTableError(): ?string
    {
        return $this->getError();
    }

    /**
     * Protected method for traits to access PdoQuery
     */
    protected function getTablePdoQuery(): PdoQuery
    {
        return $this->getPdoQuery();
    }

    /*
     * =============================================
     * QUERY BUILDING METHODS - SELECT
     * =============================================
     */

    /**
     * Starts building a SELECT query
     * 
     * @param string|null $columns Columns to select (defaults to *)
     * @return self For method chaining
     */
    public function select(string $columns = null): self
    {
        if (!$this->_isSelectSet) {
            $this->_query = $columns ? "SELECT $columns " : "SELECT * ";
            $this->_isSelectSet = true;
        } else {
            // If select is called again, append the new columns
            $this->_query .= $columns ? ", $columns" : "";
        }
        return $this;
    }

    /**
     * Adds a JOIN clause to the query
     * 
     * @param string $table Table to join
     * @param string $condition Join condition (ON clause)
     * @param string $type Join type (INNER, LEFT, RIGHT, etc.)
     * @return self For method chaining
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        $this->_joins[] = strtoupper($type) . " JOIN $table ON $condition";
        return $this;
    }

    /**
     * Sets the results limit for pagination
     * 
     * @param int $limit Maximum number of rows to return
     * @return self For method chaining
     */
    public function limit(int $limit): self
    {
        $this->_limit = $limit;
        return $this;
    }

    /**
     * Disables pagination limits
     * 
     * @return self For method chaining
     */
    public function noLimit(): self
    {
        $this->_no_limit = true;
        return $this;
    }

    /**
     * Alias for noLimit() - returns all results
     * 
     * @return self For method chaining
     */
    public function all(): self
    {
        $this->_no_limit = true;
        return $this;
    }

    /**
     * Adds an ORDER BY clause to the query
     * 
     * @param string|null $columnName Column to sort by (defaults to 'id')
     * @param bool|null $ascending Whether to sort in ascending order (true) or descending (false/null)
     * @return self For method chaining
     */
    public function orderBy(string $columnName = null, bool $ascending = null): self
    {
        $columnName = $columnName ?: 'id';
        $ascending = $ascending ? ' ASC ' : ' DESC ';
        $this->_orderBy .= " ORDER BY {$columnName}{$ascending}";
        return $this;
    }

    /*
     * =============================================
     * WHERE CLAUSE METHODS
     * =============================================
     */

    /**
     * Adds a basic WHERE equality condition
     * 
     * @param string $column Column name
     * @param mixed $value Value to match
     * @return self For method chaining
     */
    public function where(string $column, mixed $value): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE clause");
            return $this;
        }
        
        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$column} = :{$column} " 
            : " WHERE {$column} = :{$column} ";
            
        $this->_binds[':' . $column] = $value;
        return $this;
    }

    /**
     * Adds a LIKE condition with wildcard after the value
     * 
     * @param string $column Column name
     * @param string $value Value to match (% will be appended)
     * @return self For method chaining
     */
    public function whereLike(string $column, string $value): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE LIKE clause");
            return $this;
        }
        
        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$column} LIKE :{$column} " 
            : " WHERE {$column} LIKE :{$column} ";
            
        $this->_binds[':' . $column] = $value . '%';
        return $this;
    }

    /**
     * Adds a LIKE condition with wildcard before the value
     * 
     * @param string $column Column name
     * @param string $value Value to match (% will be prepended)
     * @return self For method chaining
     */
    public function whereLikeLast(string $column, string $value): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE LIKE clause");
            return $this;
        }
        
        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$column} LIKE :{$column} " 
            : " WHERE {$column} LIKE :{$column} ";
            
        $this->_binds[':' . $column] = '%' . $value;
        return $this;
    }

    /**
     * Adds a BETWEEN condition
     * 
     * @param string $columnName Column name
     * @param int|string|float $lowerBand Lower bound value
     * @param int|string|float $higherBand Upper bound value
     * @return self For method chaining
     */
    public function whereBetween(
        string $columnName, 
        int|string|float $lowerBand, 
        int|string|float $higherBand
    ): self {
        if (empty($columnName)) {
            $this->setError("Column name cannot be empty in WHERE BETWEEN clause");
            return $this;
        }
        
        $colLower = ':' . $columnName . 'lowerBand';
        $colHigher = ':' . $columnName . 'higherBand';

        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$columnName} BETWEEN {$colLower} AND {$colHigher} " 
            : " WHERE {$columnName} BETWEEN {$colLower} AND {$colHigher} ";
            
        $this->_binds[$colLower] = $lowerBand;
        $this->_binds[$colHigher] = $higherBand;
        return $this;
    }

    /**
     * Adds a WHERE IS NULL condition
     * 
     * @param string $column Column name
     * @return self For method chaining
     */
    public function whereNull(string $column): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE IS NULL clause");
            return $this;
        }
        
        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$column} IS NULL " 
            : " WHERE {$column} IS NULL ";
            
        return $this;
    }

    /**
     * Adds a WHERE IS NOT NULL condition
     * 
     * @param string $column Column name
     * @return self For method chaining
     */
    public function whereNotNull(string $column): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE IS NOT NULL clause");
            return $this;
        }
        
        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$column} IS NOT NULL " 
            : " WHERE {$column} IS NOT NULL ";
            
        return $this;
    }

    /**
     * Adds a WHERE condition using OR operator (if not the first condition)
     * 
     * Note: If this is the first condition in the query, it behaves like a regular WHERE
     * since there's no previous condition to join with OR.
     * 
     * @param string $column Column name
     * @param mixed $value Value to match
     * @return self For method chaining
     */
    public function whereOr(string $column, mixed $value): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE OR clause");
            return $this;
        }
        
        if (count($this->_arr_where) == 0) {
            // If this is the first condition, use WHERE instead of OR
            return $this->where($column, $value);
        }
        
        $paramName = $column . '_or_' . count($this->_arr_where);
        $this->_arr_where[] = " OR {$column} = :{$paramName} ";
        $this->_binds[':' . $paramName] = $value;
        return $this;
    }

    /**
     * Adds a WHERE condition for greater than comparison
     * 
     * @param string $column Column name
     * @param int|float $value Value to compare against
     * @return self For method chaining
     */
    public function whereBiggerThan(string $column, int|float $value): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE > clause");
            return $this;
        }
        
        $paramName = $column . '_gt_' . count($this->_arr_where);
        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$column} > :{$paramName} " 
            : " WHERE {$column} > :{$paramName} ";
            
        $this->_binds[':' . $paramName] = $value;
        return $this;
    }

    /**
     * Adds a WHERE condition for less than comparison
     * 
     * @param string $column Column name
     * @param int|float $value Value to compare against
     * @return self For method chaining
     */
    public function whereLessThan(string $column, int|float $value): self
    {
        if (empty($column)) {
            $this->setError("Column name cannot be empty in WHERE < clause");
            return $this;
        }
        
        $paramName = $column . '_lt_' . count($this->_arr_where);
        $this->_arr_where[] = count($this->_arr_where) 
            ? " AND {$column} < :{$paramName} " 
            : " WHERE {$column} < :{$paramName} ";
            
        $this->_binds[':' . $paramName] = $value;
        return $this;
    }

    /**
     * Alias for whereOr() for backward compatibility
     * 
     * @deprecated Use whereOr() instead for clearer semantics
     * @param string $column Column name
     * @param mixed $value Value to match
     * @return self For method chaining
     */
    public function orWhere(string $column, mixed $value): self
    {
        return $this->whereOr($column, $value);
    }

    /*
     * =============================================
     * FETCH OPERATIONS
     * =============================================
     */

    /**
     * Selects a single row by ID
     * 
     * @param int $id Record ID to select
     * @return static|null Found instance or null if not found
     */
    public function selectById(int $id): ?static
    {
        if (!$this->validateId($id, 'select')) {
            return null;
        }
        
        $result = $this->select()->where('id', $id)->limit(1)->run();
        
        if ($result === null) {
            $currentError = $this->getError();
            $this->setError(get_class($this) . ": Select by ID failed: {$currentError}");
            return null;
        }
        
        if (count($result) === 0) {
            $this->setError('Record not found');
            return null;
        }
        
        /** @var static */
        return $result[0];
    }

    /**
     * Executes a SELECT query and returns results
     * 
     * @return array<static>|null Array of model instances on success, null on error
     */
    public function run(): ?array
    {
        $objectName = get_class($this);
        
        if (!$this->_query) {
            $this->setError('Before any chain function you shall first use select()');
            return null;
        }

        // Don't check for existing errors here - let the query execute and handle its own errors
        $this->buildCompleteSelectQuery();
        $queryResult = $this->executeSelectQuery();
        
        if ($queryResult === null) {
            // Error already set by executeSelectQuery
            return null;
        }
        
        if (!count($queryResult)) {
            return [];
        }
        
        return $this->hydrateResults($queryResult);
    }

    /*
     * =============================================
     * PAGINATION METHODS
     * =============================================
     */

    /**
     * Sets the current page for pagination
     * 
     * @param int $page Page number (1-based)
     * @return void
     */
    public function setPage(int $page): void
    {
        $page = $page < 1 ? 0 : $page - 1;
        $this->_offset = $page * $this->_limit;
    }

    /**
     * Gets the current page number
     * 
     * @return int Current page (1-based)
     */
    public function getCurrentPage(): int
    {
        return $this->_offset + 1;
    }

    /**
     * Gets the number of pages from the last query
     * 
     * @return int Page count
     */
    public function getCount(): int
    {
        return $this->_count_pages;
    }

    /**
     * Gets the total number of records from the last query
     * 
     * @return int Total count
     */
    public function getTotalCounts(): int
    {
        return $this->_total_count;
    }

    /**
     * Gets the current limit per page
     * 
     * @return int Current limit
     */
    public function getLimit(): int
    {
        return $this->_limit;
    }

    /*
     * =============================================
     * HELPER METHODS
     * =============================================
     */

    /**
     * Gets the current query string
     * 
     * @return string|null Current query
     */
    public function getQuery(): string|null
    {
        return $this->_query;
    }

    /**
     * Gets the current parameter bindings
     * 
     * @return array<mixed> Current bindings
     */
    public function getBind(): array
    {
        return $this->_binds;
    }

    /**
     * Gets the current SELECT query string
     * 
     * @return string|null Current SELECT query
     */
    public function getSelectQueryString(): string|null
    {
        return $this->_query;
    }

    /**
     * Hydrates model properties from database row
     * 
     * @param array<mixed> $row Database row
     * @return void
     */
    protected function fetchRow(array $row): void
    {
        foreach ($row as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $this->castValue($key, $value);
            }
        }
    }
    
    /**
     * Cast database value to appropriate PHP type
     * 
     * @param string $property Property name
     * @param mixed $value Database value
     * @return mixed Properly typed value
     */
    protected function castValue(string $property, mixed $value): mixed
    {
        if (!isset($this->_type_map[$property])) {
            return $value;
        }
        
        $type = $this->_type_map[$property];
        switch ($type) {
            case 'int':
                return is_numeric($value) ? (int)$value : 0;
            case 'float':
                return is_numeric($value) ? (float)$value : 0.0;
            case 'bool':
                return (bool)$value;
            case 'datetime':
                return new \DateTime(is_string($value) ? $value : 'now');
            default:
                return $value;
        }
    }

    /**
     * Force connection cleanup
     */
    public function disconnect(): void
    {
        if ($this->_pdoQuery !== null) {
            $this->_pdoQuery->disconnect();
            $this->_pdoQuery = null;
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
        return $this->getPdoQuery()->beginTransaction();
    }

    /**
     * Commit the current transaction
     * 
     * @return bool True on success, false on failure
     */
    public function commit(): bool
    {
        if ($this->_pdoQuery === null) {
            $this->setError('No active transaction to commit');
            return false;
        }
        return $this->_pdoQuery->commit();
    }

    /**
     * Rollback the current transaction
     * 
     * @return bool True on success, false on failure
     */
    public function rollback(): bool
    {
        if ($this->_pdoQuery === null) {
            $this->setError('No active transaction to rollback');
            return false;
        }
        return $this->_pdoQuery->rollback();
    }

    /**
     * Clean up resources
     */
    public function __destruct()
    {
        if ($this->_pdoQuery !== null) {
            $this->_pdoQuery->disconnect();
            $this->_pdoQuery = null;
        }
    }
    
    /*
     * =============================================
     * PRIVATE HELPER METHODS
     * =============================================
     */
    
    /**
     * Builds bindings for INSERT operation
     * 
     * @return array<string,mixed> Bindings for insert query
     */
    private function getInsertBindings(): array
    {
        $arrayBind = [];
        
        // @phpstan-ignore-next-line
        foreach ($this as $key => $value) {
            if ($key[0] === '_') {
                continue;
            }
            $arrayBind[':' . $key] = $value;
        }
        
        return $arrayBind;
    }
    
    /**
     * Builds an INSERT query
     * 
     * @return string Complete INSERT query
     */
    private function buildInsertQuery(): string
    {
        $columns = '';
        $params = '';
        
        // @phpstan-ignore-next-line
        foreach ($this as $key => $value) {
            if ($key[0] === '_') {
                continue;
            }
            $columns .= $key . ',';
            $params .= ':' . $key . ',';
        }

        $columns = rtrim($columns, ',');
        $params = rtrim($params, ',');

        return "INSERT INTO {$this->_internalTable()} ({$columns}) VALUES ({$params})";
    }
    
    /**
     * Builds an UPDATE query with bindings
     * 
     * @param string $idWhereKey Column for WHERE clause
     * @param mixed $idWhereValue Value for WHERE clause
     * @return array{0: string, 1: array<string,mixed>} Query and bindings
     */
    private function buildUpdateQuery(string $idWhereKey, mixed $idWhereValue): array
    {
        $query = "UPDATE {$this->_internalTable()} SET ";
        $arrayBind = [];          
        
        // @phpstan-ignore-next-line
        foreach ($this as $key => $value) {
            if ($key[0] === '_' || $key === $idWhereKey) {
                continue;
            }
            
            $query .= " {$key} = :{$key},";
            $arrayBind[":{$key}"] = $value;
        }

        $query = rtrim($query, ',');
        $query .= " WHERE {$idWhereKey} = :{$idWhereKey} ";
        $arrayBind[":{$idWhereKey}"] = $idWhereValue;
        
        return [$query, $arrayBind];
    }
    
    /**
     * Executes the SELECT query
     * 
     * @return array<mixed>|null Query results on success, null on error
     */
    private function executeSelectQuery(): ?array
    {
        $query = $this->_query;
        $binds = $this->_binds;
        
        if (!$query) {
            $this->setError("Query string is empty or invalid");
            return null;
        }
        
        $queryResult = $this->getPdoQuery()->selectQuery($query, $binds);
        
        if ($queryResult === null) {
            $currentError = $this->getError();
            $this->setError("SELECT query failed for " . get_class($this) . ": {$currentError}");
            return null;
        }
        
        return $queryResult;
    }
    
    /**
     * Hydrates model instances from query results
     * 
     * Also calculates total count and page count if skipCount is not used
     * 
     * @param array<mixed> $queryResult Query results
     * @return array<static> Hydrated model instances
     */
    private function hydrateResults(array $queryResult): array
    {
        $object_result = [];
        
        // Calculate total count and page count
        $this->_total_count = count($queryResult);
        $this->_count_pages = $this->_limit > 0 ? (int) ceil($this->_total_count / $this->_limit) : 1;
        
        foreach ($queryResult as $item) {
            $instance = new $this();
            if (is_array($item)) {
                $instance->fetchRow($item);
            }
            $object_result[] = $instance;
        }
        
        return $object_result;
    }

    /**
     * Builds the complete SELECT query
     */
    private function buildCompleteSelectQuery(): void
    {
        $joinClause = implode(' ', $this->_joins);
        $whereClause = $this->whereMaker();

        if ($this->_skip_count) {
            $this->_query = $this->_query . 
                "FROM {$this->_internalTable()} $joinClause $whereClause ";
        } else {
            // Avoid duplicate parameter binding by building simple query without subquery
            // The count will be calculated separately if needed
            $this->_query = $this->_query . 
                "FROM {$this->_internalTable()} $joinClause $whereClause ";
        }

        if (!$this->_no_limit) {
            $this->_query .= $this->_orderBy . " LIMIT {$this->_limit} OFFSET {$this->_offset} ";
        } else {
            $this->_query .= $this->_orderBy;
        }

        $this->_query = trim($this->_query);
        $this->_query = preg_replace('/\s+/', ' ', $this->_query);
    }

    /**
     * Builds a complete WHERE clause from stored conditions
     * 
     * @return string WHERE clause
     */
    private function whereMaker(): string
    {
        if (!count($this->_arr_where)) {
            return ' WHERE 1 ';
        }
        
        $query = ' ';
        
        foreach ($this->_arr_where as $value) {
            $query .= ' ' . $value . ' ';
        }
        
        return trim($query);
    }

    private function _internalTable(): string
    {
        if (!method_exists($this, 'getTable')) {
            throw new \Exception('Method getTable():string must be implemented in child Table class');
        }
        $table_name = $this->getTable();
        if (!is_string($table_name)) {
            throw new \Exception('Method getTable():string must return a string');
        }
        return $table_name;
    }
}
