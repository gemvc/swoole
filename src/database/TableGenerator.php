<?php
namespace Gemvc\Database;

use PDO;
use PDOException;

/**
 * TableGenerator automatically creates database tables from PHP objects using reflection.
 * 
 * This class analyzes object properties and creates appropriate database tables with
 * column types determined by the PHP property types. It provides a simple ORM-like
 * functionality for schema generation.
 */
class TableGenerator {
    private ?PDO $pdo = null;
    private array $columnProperties = [];
    private array $indexedProperties = [];
    private array $uniqueIndexedProperties = [];
    private string $error = '';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getError(): string {
        return $this->error;
    }

    /**
     * Create a table from an object
     * @param object $object The object to create a table from
     * @param string|null $tableName The name of the table to create
     * @return bool True if the table was created successfully, false otherwise
     */
    public function createTableFromObject(object $object, string $tableName = null): bool {
        if (!$tableName) {
            if (!method_exists($object, 'getTable')) {
                $this->error = 'public function getTable() not found in object';
                return false;
            }
            $tableName = $object->getTable();
            if (!$tableName) {
                $this->error = 'function getTable() returned null string. Please define it and give table a name';
                return false;
            }
        }
        $reflection = new \ReflectionClass($object);
        $properties = $reflection->getProperties();
        $columns = [];
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $propertyName = $property->getName();
            if ($this->shouldSkipProperty($property)) continue;
            $propertyType = $this->getPropertyType($property, $object);
            $sqlType = $this->mapTypeToSqlType($propertyType, $propertyName);

            // Determine nullability
            $isNullable = false;
            if ($property->hasType()) {
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $isNullable = $type->allowsNull();
                }
            }
            $nullSql = $isNullable ? 'NULL' : 'NOT NULL';

            if (isset($this->columnProperties[$propertyName])) {
                $sqlType .= ' ' . $this->columnProperties[$propertyName];
            }
            $columns[] = "`$propertyName` $sqlType $nullSql";
        }
        if (empty($columns)) {
            $this->error = 'No valid properties found in object to create table columns';
            return false;
        }
        $columnsSql = implode(", ", $columns);
        $query = "CREATE TABLE IF NOT EXISTS `$tableName` ($columnsSql);";
        try {
            $this->pdo->exec($query);
            return true;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Mark a column in existing table as unique
     * 
     * @param string $tableName The name of the table
     * @param string $columnName The name of the column to make unique
     * @param bool $dropExistingIndex Whether to drop any existing index on this column first
     * @return bool True if successful, false otherwise
     */
    public function makeColumnUnique(string $tableName, string $columnName, bool $dropExistingIndex = true): bool {
        
        if (!$this->isValidTableName($tableName)) {
            $this->error = "Invalid table name format: $tableName";
            return false;
        }
        
        // Generate the index name
        $indexName = "uidx_{$tableName}_{$columnName}";
        
        try {
            // Start transaction
            $this->pdo->beginTransaction();
            $this->pdo->exec("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
            $result = $this->pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
            $result = $result->fetchAll();
            if (empty($result)) {
                $this->error = "Column '$columnName' does not exist in table '$tableName'";
                $this->pdo->rollBack();
                return false;
            }
            
            // If requested, drop any existing indexes on this column
            if ($dropExistingIndex) {
                // Find any existing indexes on this column
                $this->pdo->exec("SHOW INDEXES FROM `$tableName` WHERE Column_name = '$columnName'");
                $indexes = $this->pdo->query("SHOW INDEXES FROM `$tableName` WHERE Column_name = '$columnName'");
                $indexes = $indexes->fetchAll();
                
                foreach ($indexes as $index) {
                    $existingIndexName = $index['Key_name'];
                    // Don't drop primary key
                    if ($existingIndexName !== 'PRIMARY') {
                        $this->pdo->exec("DROP INDEX `$existingIndexName` ON `$tableName`");
                    }
                }
            }
            
            // Create the unique index
            $this->pdo->exec("CREATE UNIQUE INDEX `$indexName` ON `$tableName` (`$columnName`)");
            $this->pdo->commit();
            
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->error = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Set column properties like NOT NULL, DEFAULT value, CHECK constraints, etc.
     * 
     * @param string $propertyName The name of the property
     * @param string $columnProperties SQL properties to add to the column definition
     * @return $this For method chaining
     */
    public function setColumnProperties(string $propertyName, string $columnProperties): self {
        $this->columnProperties[$propertyName] = $columnProperties;
        return $this;
    }
    
    /**
     * Set a column as NOT NULL
     * 
     * @param string $propertyName The name of the property
     * @return $this For method chaining
     */
    public function setNotNull(string $propertyName): self {
        $properties = $this->columnProperties[$propertyName] ?? '';
        if (strpos($properties, 'NOT NULL') === false) {
            $this->columnProperties[$propertyName] = trim($properties . ' NOT NULL');
        }
        return $this;
    }
    
    /**
     * Set a default value for a column
     * 
     * @param string $propertyName The name of the property
     * @param mixed $defaultValue The default value
     * @return $this For method chaining
     */
    public function setDefault(string $propertyName, mixed $defaultValue): self {
        $properties = $this->columnProperties[$propertyName] ?? '';
        
        // Handle different types of default values
        if (is_string($defaultValue)) {
            $defaultSql = "DEFAULT '" . $this->escapeString($defaultValue) . "'";
        } elseif (is_bool($defaultValue)) {
            $defaultSql = "DEFAULT " . ($defaultValue ? '1' : '0');
        } elseif (is_null($defaultValue)) {
            $defaultSql = "DEFAULT NULL";
        } else {
            $defaultSql = "DEFAULT " . $defaultValue;
        }
        
        // Remove any existing DEFAULT clause
        if (preg_match('/DEFAULT\s+[^,]+/', $properties)) {
            $properties = preg_replace('/DEFAULT\s+[^,]+/', $defaultSql, $properties);
        } else {
            $properties = trim($properties . ' ' . $defaultSql);
        }
        
        $this->columnProperties[$propertyName] = $properties;
        return $this;
    }
    
    /**
     * Add a CHECK constraint to a column
     * 
     * @param string $propertyName The name of the property
     * @param string $checkExpression The SQL expression for the check constraint
     * @return $this For method chaining
     */
    public function addCheck(string $propertyName, string $checkExpression): self {
        $properties = $this->columnProperties[$propertyName] ?? '';
        $checkSql = "CHECK ($checkExpression)";
        
        $this->columnProperties[$propertyName] = trim($properties . ' ' . $checkSql);
        return $this;
    }
    
    /**
     * Escape a string for SQL
     * 
     * @param string $value The string to escape
     * @return string The escaped string
     */
    private function escapeString(string $value): string {
        return str_replace("'", "''", $value);
    }
    
    /**
     * Mark a property to be indexed in the database
     * 
     * @param string $propertyName The name of the property to index
     * @param bool $unique Whether the index should be unique
     * @return $this For method chaining
     */
    public function addIndex(string $propertyName, bool $unique = false): self {
        if ($unique) {
            $this->uniqueIndexedProperties[] = $propertyName;
        } else {
            $this->indexedProperties[] = $propertyName;
        }
        return $this;
    }
    
    /**
     * Remove indexing from a property
     * 
     * @param string $propertyName The name of the property to remove indexing from
     * @return $this For method chaining
     */
    public function removeIndex(string $propertyName): self {
        $this->indexedProperties = array_filter($this->indexedProperties, function($prop) use ($propertyName) {
            return $prop !== $propertyName;
        });
        
        $this->uniqueIndexedProperties = array_filter($this->uniqueIndexedProperties, function($prop) use ($propertyName) {
            return $prop !== $propertyName;
        });
        
        return $this;
    }
    
    /**
     * Generate SQL statements for creating indexes
     * 
     * @param string $tableName The name of the table
     * @param array $validPropertyNames List of valid property names in the table
     * @return array Array of SQL statements for creating indexes
     */
    private function generateIndexesSql(string $tableName, array $validPropertyNames): array {
        $indexSqlStatements = [];
        
        // Process regular indexes
        foreach ($this->indexedProperties as $property) {
            if (in_array($property, $validPropertyNames)) {
                $indexName = "idx_{$tableName}_{$property}";
                $indexSqlStatements[] = "CREATE INDEX `$indexName` ON `$tableName` (`$property`);";
            }
        }
        
        // Process unique indexes
        foreach ($this->uniqueIndexedProperties as $property) {
            if (in_array($property, $validPropertyNames)) {
                $indexName = "uidx_{$tableName}_{$property}";
                $indexSqlStatements[] = "CREATE UNIQUE INDEX `$indexName` ON `$tableName` (`$property`);";
            }
        }
        
        return $indexSqlStatements;
    }

    /**
     * Remove a column from an existing table
     * 
     * @param string $tableName The name of the table
     * @param string $columnName The name of the column to remove
     * @return bool True if successful, false otherwise
     */
    public function removeColumn(string $tableName, string $columnName): bool { 
        if (!$this->isValidTableName($tableName)) {
            $this->error = "Invalid table name format: $tableName";
            return false;
        }
        
        try {
            // Start transaction
            $this->pdo->beginTransaction();
            $this->pdo->exec("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
            $result = $this->pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
            $result = $result->fetchAll();
            if (empty($result)) {
                $this->error = "Column '$columnName' does not exist in table '$tableName'";
                $this->pdo->rollBack();
                return false;
            }
            
            // Check if the column is part of any indexes and drop them first
            $this->pdo->exec("SHOW INDEXES FROM `$tableName` WHERE Column_name = '$columnName'");
            $indexes = $this->pdo->query("SHOW INDEXES FROM `$tableName` WHERE Column_name = '$columnName'");
            $indexes = $indexes->fetchAll();
            $processedIndexes = [];
            
            foreach ($indexes as $index) {
                $indexName = $index['Key_name'];
                
                // Skip already processed indexes and PRIMARY keys (needs special handling)
                if (in_array($indexName, $processedIndexes) || $indexName === 'PRIMARY') {
                    continue;
                }
                
                // If it's a PRIMARY KEY, we need to drop it differently
                if ($indexName === 'PRIMARY') {
                    $this->pdo->exec("ALTER TABLE `$tableName` DROP PRIMARY KEY");
                } else {
                    $this->pdo->exec("DROP INDEX `$indexName` ON `$tableName`");
                }
                
                $processedIndexes[] = $indexName;
            }
            
            // Remove the column
            $this->pdo->exec("ALTER TABLE `$tableName` DROP COLUMN `$columnName`");
            $this->pdo->commit();
            
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * Update an existing table based on changes in object properties
     * 
     * This method compares the object's current properties with the existing table structure and:
     * - Adds columns for new properties
     * - Updates columns for properties with changed types
     * - Removes columns for properties that no longer exist (if removeExtraColumns is true)
     * 
     * @param object $object The object with updated properties
     * @param string|null $tableName The name of the table to update or null to use object's getTable() method
     * @param bool $removeExtraColumns Whether to remove columns that don't exist in the object
     * @param bool $enforceNotNull Whether to enforce NOT NULL constraints
     * @param mixed $defaultValue The default value to use if enforcing NOT NULL
     * @return bool True if the update was successful, false otherwise
     */
    public function updateTable(
        object $object,
        string $tableName = null,
        bool $removeExtraColumns = false,
        bool $enforceNotNull = false,
        $defaultValue = null
    ): bool {
        if (!$this->pdo) {
            $this->error = 'No PDO connection.';
            return false;
        }

        if (!$tableName) {
            if (!method_exists($object, 'getTable')) {
                $this->error = 'public function getTable() not found in object';
                return false;
            }
            $tableName = $object->getTable();
            if (!$tableName) {
                $this->error = 'function getTable() returned null string. Please define it and give table a name';
                return false;
            }
        }

        try {
            // Ensure we're not in a transaction
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Test connection before starting transaction
            $this->pdo->query('SELECT 1');
            
            // Start transaction
            $this->pdo->beginTransaction();

            // Get existing columns
            $stmt = $this->pdo->query("DESCRIBE `$tableName`");
            $existingColumns = $stmt->fetchAll();
            if (empty($existingColumns)) {
                $this->error = "DESCRIBE failed or table has no columns.";
                $this->pdo->rollBack();
                return false;
            }

            $columnMap = [];
            foreach ($existingColumns as $column) {
                $columnMap[$column['Field']] = $column;
            }

            $reflection = new \ReflectionClass($object);
            $properties = $reflection->getProperties();
            $objectPropertyNames = [];
            $columnsToAdd = [];
            $columnsToModify = [];
            $columnsToRemove = [];

            // Get all property names from the object
            foreach ($properties as $property) {
                $property->setAccessible(true);
                $propertyName = $property->getName();
                if ($this->shouldSkipProperty($property)) continue;
                
                $objectPropertyNames[] = $propertyName;
                $propertyType = $this->getPropertyType($property, $object);
                $sqlType = $this->mapTypeToSqlType($propertyType, $propertyName);
                
                // Determine nullability
                $isNullable = false;
                if ($property->hasType()) {
                    $type = $property->getType();
                    if ($type instanceof \ReflectionNamedType) {
                        $isNullable = $type->allowsNull();
                    }
                }
                $nullSql = $isNullable ? 'NULL' : 'NOT NULL';

                if (isset($this->columnProperties[$propertyName])) {
                    $sqlType .= ' ' . $this->columnProperties[$propertyName];
                }

                if (!isset($columnMap[$propertyName])) {
                    $columnsToAdd[] = [
                        'name' => $propertyName,
                        'definition' => $sqlType
                    ];
                } else {
                    $existingType = strtolower($columnMap[$propertyName]['Type']);
                    $newType = strtolower(preg_replace('/\s+.*$/', '', $sqlType));
                    if ($propertyName === 'id') continue;
                    
                    // Compare types more accurately
                    $existingType = $this->normalizeType($existingType);
                    $newType = $this->normalizeType($newType);
                    
                    $existingNull = strtolower($columnMap[$propertyName]['Null']) === 'yes';
                    $newNull = $isNullable; // from Reflection

                    if ($existingType !== $newType || $existingNull !== $newNull) {
                        // If changing from NULL to NOT NULL
                        if ($existingNull && !$newNull && $enforceNotNull) {
                            // Check for NULLs in the column
                            $nullCount = $this->pdo->query("SELECT COUNT(*) FROM `$tableName` WHERE `$propertyName` IS NULL")->fetchColumn();
                            if ($nullCount > 0) {
                                if ($defaultValue !== null) {
                                    // Update NULLs to default value
                                    $defaultSql = is_string($defaultValue) ? $this->pdo->quote($defaultValue) : $defaultValue;
                                    $this->pdo->exec("UPDATE `$tableName` SET `$propertyName` = $defaultSql WHERE `$propertyName` IS NULL");
                                } else {
                                    $this->error = "Cannot set `$propertyName` to NOT NULL: $nullCount NULL values exist. Use --default to set a value.";
                                    return false;
                                }
                            }
                            // Now safe to alter column
                            $columnsToModify[] = [
                                'name' => $propertyName,
                                'definition' => "$sqlType NOT NULL"
                            ];
                        } elseif ($existingType !== $newType || $existingNull !== $newNull) {
                            $columnsToModify[] = [
                                'name' => $propertyName,
                                'definition' => "$sqlType " . ($newNull ? 'NULL' : 'NOT NULL')
                            ];
                        }
                    }
                }
            }

            // Find columns to remove if removeExtraColumns is true
            if ($removeExtraColumns) {
                foreach ($columnMap as $columnName => $column) {
                    // Skip 'id' column and columns that exist in the object
                    if ($columnName === 'id' || in_array($columnName, $objectPropertyNames)) {
                        continue;
                    }
                    $columnsToRemove[] = $columnName;
                }
            }

            // If no changes needed, commit and return
            if (empty($columnsToAdd) && empty($columnsToModify) && empty($columnsToRemove)) {
                $this->pdo->commit();
                return true;
            }

            // Execute all changes
            foreach ($columnsToAdd as $column) {
                $this->pdo->exec("ALTER TABLE `$tableName` ADD COLUMN `{$column['name']}` {$column['definition']}");
            }

            foreach ($columnsToModify as $column) {
                $this->pdo->exec("ALTER TABLE `$tableName` MODIFY COLUMN `{$column['name']}` {$column['definition']}");
            }

            foreach ($columnsToRemove as $columnName) {
                $this->pdo->exec("ALTER TABLE `$tableName` DROP COLUMN `$columnName`");
            }

            // Commit the transaction
            $this->pdo->commit();
            return true;

        } catch (PDOException $e) {
            // Rollback on error
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * Normalize SQL type for comparison
     * 
     * @param string $type The SQL type to normalize
     * @return string The normalized type
     */
    private function normalizeType(string $type): string {
        // Remove length specifications
        $type = preg_replace('/\(\d+\)/', '', $type);
        
        // Normalize common type variations
        $type = strtolower($type);
        $type = str_replace(['unsigned', 'signed'], '', $type);
        $type = trim($type);
        
        // Map common variations to standard types
        $typeMap = [
            'tinyint' => 'int',
            'smallint' => 'int',
            'mediumint' => 'int',
            'bigint' => 'int',
            'float' => 'double',
            'real' => 'double',
            'varchar' => 'string',
            'char' => 'string',
            'text' => 'string',
            'mediumtext' => 'string',
            'longtext' => 'string',
            'tinytext' => 'string',
            'datetime' => 'datetime',
            'timestamp' => 'datetime',
            'date' => 'datetime',
            'time' => 'datetime',
            'year' => 'int',
            'bit' => 'int',
            'bool' => 'int',
            'boolean' => 'int',
            'json' => 'string',
            'enum' => 'string',
            'set' => 'string'
        ];
        
        return $typeMap[$type] ?? $type;
    }

    /*-------------------------------------------private methods-------------------------------------------*/

    /**
     * Determines if a property should be skipped during table creation
     * 
     * @param \ReflectionProperty $property The property to check
     * @return bool True if the property should be skipped
     */
    private function shouldSkipProperty(\ReflectionProperty $property): bool {
        // Skip static properties
        if ($property->isStatic()) {
            return true;
        }
        
        // Skip properties that start with an underscore (convention for non-persisted properties)
        if (str_starts_with($property->getName(), '_')) {
            return true;
        }
        
        // Skip constants (class constants should not be database columns)
        if ($property->isReadOnly() && $property->isPublic()) {
            // In PHP 8.1+ we can use isReadOnly() to detect constants/final properties
            return true;
        }
        
        // Could add more conditions here, like checking for specific annotations
        // or property name patterns that indicate non-database fields

        return false;
    }

    /**
     * Get the type of a property
     * 
     * @param \ReflectionProperty $property The property to get the type of
     * @param object $object The object instance (for value type detection)
     * @return string The property type
     */
    private function getPropertyType(\ReflectionProperty $property, object $object): string {
        // Try to get type from PHP 7.4+ property type declaration
        if ($property->hasType()) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType) {
                return $type->getName();
            }
        }
        
        // Fallback to runtime type detection if property is accessible and initialized
        try {
            if ($property->isInitialized($object)) {
                $value = $property->getValue($object);
                $type = gettype($value);
                
                if ($type === 'object' && $value instanceof \DateTime) {
                    return 'DateTime';
                }
                
                return $type;
            }
        } catch (\Error | \Exception $e) {
            // Silently handle errors from accessing uninitialized properties
        }
        
        // Default to text if type can't be determined
        return 'unknown';
    }

    /**
     * Map PHP type to SQL column type
     * 
     * @param string $phpType The PHP type
     * @param string $propertyName The property name (for special handling)
     * @return string The SQL column type
     */
    private function mapTypeToSqlType(string $phpType, string $propertyName): string {
        $type = match(strtolower($phpType)) {
            'int', 'integer' => 'INT(11)',
            'float', 'double' => 'DOUBLE',
            'bool', 'boolean' => 'TINYINT(1)',
            'string' => 'VARCHAR(255)',
            'datetime' => 'DATETIME',
            'array' => 'JSON',
            'null', 'unknown' => 'TEXT',
            default => 'TEXT'
        };

        // Special handling for common field names
        if (strtolower($propertyName) === 'id') {
            $type .= ' AUTO_INCREMENT PRIMARY KEY';
        } elseif (str_ends_with(strtolower($propertyName), '_id')) {
            // Foreign key column - make it INT
            $type = 'INT(11)';
        } elseif (str_ends_with(strtolower($propertyName), 'email')) {
            // Email columns - make them VARCHAR(320) which is the max length of an email
            $type = 'VARCHAR(320)';
        }

        return $type;
    }

    /**
     * Validate if the table name is in a valid format
     * 
     * @param string $tableName The table name to validate
     * @return bool True if the table name is valid
     */
    private function isValidTableName(string $tableName): bool {
        // Table names should contain only alphanumeric characters, underscores, and should not start with a number
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName);
    }

    /**
     * Create a unique constraint across multiple columns
     * 
     * @param string $tableName The name of the table
     * @param array $columnNames Array of column names to include in the unique constraint
     * @param string|null $indexName Optional custom name for the index (defaults to auto-generated name)
     * @param bool $dropExistingIndexes Whether to drop any existing indexes on these columns
     * @return bool True if successful, false otherwise
     */
    public function makeColumnsUniqueTogether(string $tableName, array $columnNames, ?string $indexName = null, bool $dropExistingIndexes = true): bool {
        
        if (empty($columnNames)) {
            $this->error = "No column names provided for combined unique index";
            return false;
        }
        
        if (!$this->isValidTableName($tableName)) {
            $this->error = "Invalid table name format: $tableName";
            return false;
        }
        
        // Generate default index name if not provided
        if ($indexName === null) {
            // Create a name based on table and columns, with length limitation
            $columnsStr = implode('_', array_map(function($col) {
                return substr($col, 0, 5); // Take first 5 chars of each column name
            }, $columnNames));
            
            $indexName = "uidx_{$tableName}_{$columnsStr}";
            
            // Ensure index name isn't too long (MySQL has a 64 char limit)
            if (strlen($indexName) > 64) {
                $indexName = substr($indexName, 0, 60) . '_idx';
            }
        }
        
        try {
            // Start transaction
            $this->pdo->beginTransaction();
            foreach ($columnNames as $columnName) {
                $this->pdo->exec("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                $result = $this->pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                $result = $result->fetchAll();
                if (empty($result)) {
                    $this->error = "Column '$columnName' does not exist in table '$tableName'";
                    $this->pdo->rollBack();
                    return false;
                }
            }
            
            // If requested, drop any existing indexes on these columns
            if ($dropExistingIndexes) {
                $indexesToDrop = [];
                
                // For each column, find indexes that include it
                foreach ($columnNames as $columnName) {
                    $this->pdo->exec("SHOW INDEXES FROM `$tableName` WHERE Column_name = '$columnName'");
                    $indexes = $this->pdo->query("SHOW INDEXES FROM `$tableName` WHERE Column_name = '$columnName'");
                    $indexes = $indexes->fetchAll();
                    
                    foreach ($indexes as $index) {
                        $existingIndexName = $index['Key_name'];
                        // Don't drop primary key
                        if ($existingIndexName !== 'PRIMARY') {
                            $indexesToDrop[$existingIndexName] = true; // Use associative array to avoid duplicates
                        }
                    }
                }
                
                // Drop the identified indexes
                foreach (array_keys($indexesToDrop) as $indexToDrop) {
                    $this->pdo->exec("DROP INDEX `$indexToDrop` ON `$tableName`");
                }
            }
            
            // Create the combined columns list for the SQL
            $columnsListSql = implode('`, `', $columnNames);
            
            // Create the unique index on combined columns
            $this->pdo->exec("CREATE UNIQUE INDEX `$indexName` ON `$tableName` (`$columnsListSql`)");
            $this->pdo->commit();
            
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->error = $e->getMessage();
            return false;
        }
    }
} 