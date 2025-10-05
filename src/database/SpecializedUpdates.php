<?php

namespace Gemvc\Database;

/**
 * SpecializedUpdates trait provides specialized update operation methods
 * 
 * This trait contains specialized update operations that were previously in the Table class.
 * It provides methods for setting NULL values, timestamps, and activation/deactivation.
 */
trait SpecializedUpdates
{
    /**
     * Sets a column to NULL based on a WHERE condition
     * 
     * @param string $columnNameSetToNull Column to set to NULL
     * @param string $whereColumn WHERE condition column
     * @param mixed $whereValue WHERE condition value
     * @return int|null Number of affected rows on success, null on error
     */
    public function setNullQuery(string $columnNameSetToNull, string $whereColumn, mixed $whereValue): ?int
    {
        // Validate input parameters
        if (empty($columnNameSetToNull)) {
            $this->setTableError("Column name to set NULL cannot be empty");
            return null;
        }
        
        if (empty($whereColumn)) {
            $this->setTableError("Where column cannot be empty");
            return null;
        }
        
        if (!$this->validateValue($whereValue, 'WHERE condition')) {
            return null;
        }
        
        $this->validateProperties([]);

        $query = "UPDATE {$this->_internalTable()} SET {$columnNameSetToNull} = NULL WHERE {$whereColumn} = :whereValue";
        $result = $this->getTablePdoQuery()->updateQuery($query, [':whereValue' => $whereValue]);
        
        if ($result === null) {
            return $this->handleOperationError('Set NULL');
        }
        
        return $result;
    }

    /**
     * Sets a column to current timestamp based on a WHERE condition
     * 
     * @param string $columnNameSetToNowTomeStamp Column to set to NOW()
     * @param string $whereColumn WHERE condition column
     * @param mixed $whereValue WHERE condition value
     * @return int|null Number of affected rows on success, null on error
     */
    public function setTimeNowQuery(string $columnNameSetToNowTomeStamp, string $whereColumn, mixed $whereValue): ?int
    {
        // Validate input parameters
        if (empty($columnNameSetToNowTomeStamp)) {
            $this->setTableError("Column name to set timestamp cannot be empty");
            return null;
        }
        
        if (empty($whereColumn)) {
            $this->setTableError("Where column cannot be empty");
            return null;
        }
        
        if (!$this->validateValue($whereValue, 'WHERE condition')) {
            return null;
        }
        
        $this->validateProperties([]);

        $query = "UPDATE {$this->_internalTable()} SET {$columnNameSetToNowTomeStamp} = NOW() WHERE {$whereColumn} = :whereValue";
        $result = $this->getTablePdoQuery()->updateQuery($query, [':whereValue' => $whereValue]);
        
        if ($result === null) {
            return $this->handleOperationError('Set timestamp');
        }
        
        return $result;
    }

    /**
     * Sets is_active to 1 (activate record)
     * 
     * @param int $id Record ID to activate
     * @return int|null Number of affected rows on success, null on error
     */
    public function activateQuery(int $id): ?int
    {
        if (!$this->validateProperties(['is_active'])) {
            $this->setTableError('is_active column is not present in the table');
            return null;
        }

        if (!$this->validateId($id, 'activate')) {
            return null;
        }
        
        $result = $this->getTablePdoQuery()->updateQuery(
            "UPDATE {$this->_internalTable()} SET is_active = 1 WHERE id = :id", 
            [':id' => $id]
        );
        
        if ($result === null) {
            return $this->handleOperationError('Activate');
        }
        
        return $result;
    }

    /**
     * Sets is_active to 0 (deactivate record)
     * 
     * @param int $id Record ID to deactivate
     * @return int|null Number of affected rows on success, null on error
     */
    public function deactivateQuery(int $id): ?int
    {
        if (!$this->validateProperties(['is_active'])) {
            $this->setTableError('is_active column is not present in the table');
            return null;
        }

        if (!$this->validateId($id, 'deactivate')) {
            return null;
        }
        
        $result = $this->getTablePdoQuery()->updateQuery(
            "UPDATE {$this->_internalTable()} SET is_active = 0 WHERE id = :id", 
            [':id' => $id]
        );
        
        if ($result === null) {
            return $this->handleOperationError('Deactivate');
        }
        
        return $result;
    }
}
