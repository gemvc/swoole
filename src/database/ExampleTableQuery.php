<?php

namespace Gemvc\Database;

/**
 * Example usage of the new TableQuery class
 * 
 * This demonstrates how to use TableQuery with the same API as Table,
 * but with better organization through traits.
 */
class ExampleTableQuery extends TableQuery
{
    public function getTable(): string
    {
        return 'users';
    }
}

// Example usage:
/*
$user = new ExampleUserTable();

// CRUD Operations (from CrudOperations trait)
$user->name = 'John Doe';
$user->email = 'john@example.com';
$result = $user->insertSingleQuery();

if ($result !== null) {
    echo "User inserted with ID: " . $user->id;
} else {
    echo "Error: " . $user->getError();
}

// Query Building (same as original Table)
$users = $user->select()
    ->where('is_active', 1)
    ->whereLike('name', 'John')
    ->orderBy('created_at', false)
    ->limit(10)
    ->run();

// Specialized Updates (from SpecializedUpdates trait)
$affected = $user->activateQuery(123);
$affected = $user->setNullQuery('deleted_at', 'id', 456);

// Validation (from CrudValidation trait)
$isValid = $user->validateId(123, 'update');
*/
