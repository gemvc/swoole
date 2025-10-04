<?php

namespace Gemvc\CLI\Commands;

use Gemvc\CLI\Commands\BaseCrudGenerator;

class CreateTable extends BaseCrudGenerator
{
    protected string $serviceName;
    protected string $basePath;

    /**
     * Format service name to proper case
     * 
     * @param string $name
     * @return string
     */
    protected function formatServiceName(string $name): string
    {
        return ucfirst(strtolower($name));
    }

    public function execute(): void
    {
        if (empty($this->args[0]) || !is_string($this->args[0])) {
            $this->error("Table name is required. Usage: gemvc create:table TableName");
        }

        // @phpstan-ignore-next-line
        $this->serviceName = $this->formatServiceName($this->args[0]);
        $this->basePath = defined('PROJECT_ROOT') ? PROJECT_ROOT : $this->determineProjectRoot();

        try {
            // Create necessary directories
            $this->createDirectories($this->getRequiredDirectories());

            // Create table file
            $this->createTable();

            $this->success("Table {$this->serviceName} created successfully!");
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }


    protected function createTable(): void
    {
        $template = $this->getTemplate('table');
        $content = $this->replaceTemplateVariables($template, [
            'serviceName' => $this->serviceName,
            'tableName' => strtolower($this->serviceName) . 's'
        ]);

        $path = $this->basePath . "/app/table/{$this->serviceName}Table.php";
        $this->writeFile($path, $content, "Table");
    }

    protected function determineProjectRoot(): string
    {
        // Start with composer's vendor directory (where this file is located)
        $vendorDir = dirname(dirname(dirname(dirname(__DIR__))));
        
        // If we're in the vendor directory, the project root is one level up
        if (basename($vendorDir) === 'vendor') {
            return dirname($vendorDir);
        }
        
        // Fallback to current directory if we can't determine project root
        return getcwd() ?: '.';
    }
} 