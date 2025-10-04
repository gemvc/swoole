<?php

namespace Gemvc\CLI;

class CommandCategories
{
    public const CATEGORIES = [
        'Project Management' => [
            'init' => 'Initialize a new GEMVC project with server configuration (Apache/Swoole), optional PHPStan installation, and testing framework choice (PHPUnit/Pest)',
        ],
        'Code Generation' => [
            'create:service' => 'Create a new service with optional components (-c: controller, -m: model, -t: table)',
            'create:controller' => 'Create a new controller for handling business logic',
            'create:model' => 'Create a new model for data processing and business rules',
            'create:table' => 'Create a new table class for database operations',
            'create:crud' => 'Create complete CRUD operations for a resource (service, controller, model, table)',
        ],
        'Database' => [
            'db:init' => 'Initialize database based on configuration',
            'db:migrate' => 'Create or update a specific table (db:migrate TableClassName)',
            'db:list' => 'Show list of all tables in the database',
            'db:drop' => 'Drop a specific table (db:drop TableName)',
        ],
    ];

    public static function getCommandClass(string $command): string
    {
        $commandMappings = [
            'init' => 'InitProject',
            'create:service' => 'CreateService',
            'create:controller' => 'CreateController',
            'create:model' => 'CreateModel',
            'create:table' => 'CreateTable',
            'create:crud' => 'CreateCrud',
            'db:init' => 'DbInit',
            'db:migrate' => 'DbMigrate',
            'db:list' => 'DbList',
            'db:drop' => 'DbDrop',
        ];

        return $commandMappings[$command] ?? '';
    }

    public static function getCategory(string $command): string
    {
        foreach (self::CATEGORIES as $category => $commands) {
            if (isset($commands[$command])) {
                return $category;
            }
        }
        return 'Other';
    }

    public static function getDescription(string $command): string
    {
        foreach (self::CATEGORIES as $commands) {
            if (isset($commands[$command])) {
                return $commands[$command];
            }
        }
        return '';
    }

    /**
     * @return array<string, string|array<string>>
     */
    public static function getExamples(): array
    {
        return [
            'init' => 'vendor/bin/gemvc init',
            'create:service' => [
                'vendor/bin/gemvc create:service User',
                'vendor/bin/gemvc create:service User -cmt'
            ],
            'create:controller' => 'vendor/bin/gemvc create:controller User',
            'create:model' => 'vendor/bin/gemvc create:model User',
            'create:table' => 'vendor/bin/gemvc create:table User',
            'create:crud' => 'vendor/bin/gemvc create:crud User',
            'db:init' => 'vendor/bin/gemvc db:init',
            'db:migrate' => 'vendor/bin/gemvc db:migrate UserTable',
            'db:list' => 'vendor/bin/gemvc db:list',
            'db:drop' => 'vendor/bin/gemvc db:drop users'
        ];
    }
} 