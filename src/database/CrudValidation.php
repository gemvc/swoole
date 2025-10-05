<?php

namespace Gemvc\Database;

/**
 * CrudValidation trait provides validation methods for CRUD operations
 * 
 * This trait contains all validation logic that was previously in the Table class.
 * It provides protected methods for validating IDs, properties, and values.
 */
trait CrudValidation
{
    /**
     * Validates that the 'id' property exists and is not null
     * 
     * @return bool True if ID property exists and is not null, false otherwise
     */
    private function validateIdProperty(): bool
    {
        if ($this->id === null) {
            $this->setTableError("Property 'id' is not set in object");
            return false;
        }
        return true;
    }

    /**
     * Validates that a value is not null or empty
     * 
     * @param mixed $value Value to validate
     * @param string $context Context for error message
     * @return bool True if valid, false otherwise
     */
    private function validateValue(mixed $value, string $context = 'WHERE condition'): bool
    {
        if ($value === null || $value === '') {
            $this->setTableError("Value cannot be null or empty for {$context}");
            return false;
        }
        return true;
    }

    /**
     * Handles operation failure with context
     * 
     * @param string $operation Operation name (e.g., "Update", "Delete")
     * @return null Always returns null
     */
    private function handleOperationError(string $operation): null
    {
        $currentError = $this->getTableError();
        $this->setTableError("{$operation} failed in {$this->_internalTable()}: {$currentError}");
        return null;
    }

    /**
     * Validate ID parameter
     * 
     * @param int|null $id ID to validate
     * @param string $operation Operation name for error message
     * @return bool True if ID is valid
     */
    protected function validateId(?int $id, string $operation = 'operation'): bool
    {
        if ($id === null || $id < 1) {
            $this->setTableError("ID must be a positive integer for {$operation} in {$this->_internalTable()}");
            return false;
        }
        return true;
    }

    /**
     * Validate essential properties and show error if not valid
     * 
     * @param array<string> $properties Properties to validate
     * @return bool True if all properties exist
     */
    protected function validateProperties(array $properties): bool 
    {
        foreach ($properties as $property) {
            if (!property_exists($this, $property)) {
                $this->setTableError("Property '{$property}' is not set in table");
                return false;
            }
        }
        
        return true;
    }
}
