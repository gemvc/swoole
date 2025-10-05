<?php

namespace Gemvc\Database;

/**
 * CrudOperations trait provides CRUD operation methods
 * 
 * This trait contains all CRUD operations that were previously in the Table class.
 * It provides methods for insert, update, delete, and conditional operations.
 */
trait CrudOperations
{
    /**
     * Inserts a single row into the database table
     * 
     * @return static|null The current instance with inserted id on success, null on error
     */
    public function insertSingleQuery(): ?static
    {
        $this->validateProperties([]);

        $query = $this->buildInsertQuery();
        $arrayBind = $this->getInsertBindings();
        
        // Debug logging to capture the actual SQL and parameters
        error_log("TableQuery::insertSingleQuery() - Executing query: " . $query);
        error_log("TableQuery::insertSingleQuery() - With bindings: " . json_encode($arrayBind));
        
        $result = $this->getTablePdoQuery()->insertQuery($query, $arrayBind);
        
        if ($result === null) {
            // Error message already set by PdoQuery, just add context
            $currentError = $this->getTableError();
            
            // Enhanced error logging with SQL details
            $errorInfo = [
                'table' => $this->_internalTable(),
                'query' => $query,
                'bindings' => $arrayBind,
                'error' => $currentError
            ];
            error_log("TableQuery::insertSingleQuery() - Insert failed with full details: " . json_encode($errorInfo));
            
            $this->setTableError("Insert failed in {$this->_internalTable()}: {$currentError}");
            return null;
        }
        
        $this->id = $result;
        return $this;
    }

    /**
     * Updates a record based on its ID property
     * 
     * @return static|null Current instance on success, null on error
     */
    public function updateSingleQuery(): ?static
    {
        if (!$this->validateIdProperty()) {
            return null;
        }
        
        if (!$this->validateId($this->id, 'update')) {
            return null;
        }
        
        [$query, $arrayBind] = $this->buildUpdateQuery('id', $this->id);
        
        $result = $this->getTablePdoQuery()->updateQuery($query, $arrayBind);
        
        if ($result === null) {
            return $this->handleOperationError('Update');
        }
        
        return $this;
    }

    /**
     * Deletes a record by ID and return id for deleted object
     * 
     * @param int $id Record ID to delete
     * @return int|null Deleted ID on success, null on error
     */
    public function deleteByIdQuery(int $id): ?int
    {
        if (!$this->validateIdProperty()) {
            return null;
        }
        
        if (!$this->validateId($id, 'delete')) {
            return null;
        }
              
        $query = "DELETE FROM {$this->_internalTable()} WHERE id = :id";
        $result = $this->getTablePdoQuery()->deleteQuery($query, [':id' => $id]);
        
        if ($result === null) {
            return $this->handleOperationError('Delete');
        }
        return $id;
    }

    /**
     * Marks a record as deleted (soft delete)
     * @return static|null Current instance on success, null on error
     */
    public function safeDeleteQuery(): ?static
    {
        if (!$this->validateIdProperty()) {
            return null;
        }
        
        if (!$this->validateId($this->id, 'safe delete')) {
            return null;
        }
        
        if (!$this->validateProperties(['deleted_at'])) {
            $this->setTableError("For safe delete, deleted_at must exist in the Database table and object");
            return null;
        }
        
        $id = $this->id;
        $query = "UPDATE {$this->_internalTable()} SET deleted_at = NOW() WHERE id = :id";
        
        if (property_exists($this, 'is_active')) {
            $query = "UPDATE {$this->_internalTable()} SET deleted_at = NOW(), is_active = 0 WHERE id = :id";
        }
        
        $result = $this->getTablePdoQuery()->updateQuery($query, [':id' => $id]);
        
        if ($result === null) {
            return $this->handleOperationError('Safe delete');
        }
        
        if (property_exists($this, 'deleted_at')) {
            $this->deleted_at = date('Y-m-d H:i:s');
        }
        
        // Only set is_active if the property exists
        if (property_exists($this, 'is_active')) {
            $this->is_active = 0;
        }
        
        return $this;
    }

    /**
     * Restores a soft-deleted record
     * 
     * @return static|null Current instance on success, null on error
     */
    public function restoreQuery(): ?static
    {
        if (!$this->validateIdProperty()) {
            return null;
        }
        
        if (!$this->validateId($this->id, 'restore')) {
            return null;
        }
        
        if (!$this->validateProperties(['deleted_at'])) {
            $this->setTableError("For restore operation, deleted_at must exist in the Database table and object");
            return null;
        }
        
        $id = $this->id;
        $query = "UPDATE {$this->_internalTable()} SET deleted_at = NULL WHERE id = :id";
               
        $result = $this->getTablePdoQuery()->updateQuery($query, [':id' => $id]);
        
        if ($result === null) {
            return $this->handleOperationError('Restore');
        }
        
        if (property_exists($this, 'deleted_at')) {
            $this->deleted_at = null;
        }       
        return $this;
    }

    /**
     * Removes an object from the database by ID
     * 
     * @return int|null Number of affected rows on success, null on error
     */
    public function deleteSingleQuery(): ?int
    {
        if (!$this->validateIdProperty()) {
            return null;
        }
        
        if (!$this->validateId($this->id, 'delete')) {
            return null;
        }
        
        return $this->removeConditionalQuery('id', $this->id);
    }

    /**
     * Removes records based on conditional WHERE clauses
     * 
     * @param string $whereColumn Primary column for WHERE condition
     * @param mixed $whereValue Value to match in primary column
     * @param string|null $secondWhereColumn Optional second column for WHERE condition
     * @param mixed $secondWhereValue Value to match in second column
     * @return int|null Number of affected rows on success, null on error
     */
    public function removeConditionalQuery(
        string $whereColumn, 
        mixed $whereValue, 
        ?string $secondWhereColumn = null, 
        mixed $secondWhereValue = null
    ): ?int {
        // Validate input parameters
        if (empty($whereColumn)) {
            $this->setTableError("Where column cannot be empty");
            return null;
        }
        
        if (!$this->validateValue($whereValue, 'WHERE condition')) {
            return null;
        }
        
        $this->validateProperties([]);

        $query = "DELETE FROM {$this->_internalTable()} WHERE {$whereColumn} = :{$whereColumn}";
        $arrayBind = [':' . $whereColumn => $whereValue];
        
        if ($secondWhereColumn !== null) {
            if (empty($secondWhereColumn)) {
                $this->setTableError("Second where column cannot be empty");
                return null;
            }
            $query .= " AND {$secondWhereColumn} = :{$secondWhereColumn}";
            $arrayBind[':' . $secondWhereColumn] = $secondWhereValue;
        }
        
        $result = $this->getTablePdoQuery()->deleteQuery($query, $arrayBind);

        if ($result === null) { 
            return $this->handleOperationError('Conditional delete');
        }
       
        return $result;
    }
}
