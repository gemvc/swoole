<?php

namespace Gemvc\CLI\Commands;

use Gemvc\CLI\Command;
use Gemvc\CLI\Commands\DbConnect;
use Gemvc\Helper\ProjectHelper;

class DbDescribe extends Command
{
    protected string $description = "Describe a specific database table structure in detail. Shows columns, indexes, foreign keys, and table statistics.";

    public function execute()
    {
        try {
            // Check if table name is provided
            if (empty($this->args[0])) {
                $this->error("Table name is required. Usage: gemvc db:describe TableName");
                return;
            }

            $tableName = $this->args[0];
            
            // Load environment variables
            ProjectHelper::loadEnv();
            
            // Get database name from environment
            $dbName = $_ENV['DB_NAME'] ?? null;
            if (!$dbName) {
                throw new \Exception("Database name not found in configuration (DB_NAME)");
            }
            
            // Get database connection
            $pdo = DbConnect::connect();
            if (!$pdo) {
                return;
            }

            // Check if table exists
            $stmt = $pdo->prepare("SHOW TABLES FROM `{$dbName}` LIKE ?");
            $stmt->execute([$tableName]);
            if ($stmt->rowCount() === 0) {
                $this->error("Table '{$tableName}' not found in database '{$dbName}'");
                return;
            }

            $this->displayTableHeader($tableName);

            // 1. Show table structure (columns)
            $this->showTableStructure($pdo, $tableName);

            // 2. Show indexes
            $this->showIndexes($pdo, $tableName);

            // 3. Show foreign keys
            $this->showForeignKeys($pdo, $tableName, $dbName);

            // 4. Show table statistics
            $this->showTableStatistics($pdo, $tableName, $dbName);

            // 5. Show table options (engine, charset, etc.)
            $this->showTableOptions($pdo, $tableName, $dbName);

            $this->write("\n");
            
        } catch (\Exception $e) {
            $this->error("Failed to describe table: " . $e->getMessage());
        }
    }

    private function showTableStructure(\PDO $pdo, string $tableName): void
    {
        $this->displaySectionHeader("📋 COLUMNS");

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($columns)) {
            $totalWidth = 78;
            $message = "No columns found";
            $messageWidth = $this->getDisplayWidth($message);
            $padding = $totalWidth - $messageWidth - 2;
            $this->write("│ " . $message . str_repeat(" ", $padding) . " │\n", 'red');
            $this->write("└" . str_repeat("─", $totalWidth) . "┘\n", 'cyan');
            return;
        }

        // Prepare data for table formatting
        $tableData = [];
        $headers = ['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'];
        
        foreach ($columns as $column) {
            $keyType = match($column['Key']) {
                'PRI' => '🔑 PRI',
                'UNI' => '🔒 UNI',
                'MUL' => '📚 MUL',
                default => $column['Key'] ?: '-'
            };
            
            $null = $column['Null'] === 'YES' ? '✓' : '✗';
            $default = $column['Default'] !== null ? $column['Default'] : '-';
            $extra = $column['Extra'] ?: '-';
            
            $tableData[] = [
                $column['Field'],
                $column['Type'],
                $null,
                $keyType,
                $default,
                $extra
            ];
        }

        $this->displayTable($headers, $tableData);
    }

    private function showIndexes(\PDO $pdo, string $tableName): void
    {
        $this->displaySectionHeader("🔍 INDEXES");

        $stmt = $pdo->query("SHOW INDEX FROM `{$tableName}`");
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($indexes)) {
            $totalWidth = 78;
            $message = "No indexes found";
            $messageWidth = $this->getDisplayWidth($message);
            $padding = $totalWidth - $messageWidth - 2;
            $this->write("│ " . $message . str_repeat(" ", $padding) . " │\n", 'yellow');
            $this->write("└" . str_repeat("─", $totalWidth) . "┘\n", 'cyan');
            return;
        }

        $groupedIndexes = [];
        foreach ($indexes as $index) {
            $groupedIndexes[$index['Key_name']][] = $index;
        }

        $tableData = [];
        $headers = ['Index Name', 'Type', 'Unique', 'Columns'];

        foreach ($groupedIndexes as $indexName => $indexColumns) {
            $firstColumn = $indexColumns[0];
            $unique = $firstColumn['Non_unique'] == 0 ? '🔒 Yes' : '❌ No';
            $type = $firstColumn['Index_type'];
            
            $columns = array_map(function($col) {
                return $col['Column_name'] . ($col['Sub_part'] ? "({$col['Sub_part']})" : '');
            }, $indexColumns);
            
            $indexIcon = match($indexName) {
                'PRIMARY' => '🔑',
                default => match(true) {
                    $firstColumn['Non_unique'] == 0 => '🔒',
                    default => '📋'
                }
            };
            
            $tableData[] = [
                $indexIcon . ' ' . $indexName,
                $type,
                $unique,
                implode(', ', $columns)
            ];
        }

        $this->displayTable($headers, $tableData);
    }

    private function showForeignKeys(\PDO $pdo, string $tableName, string $dbName): void
    {
        $this->displaySectionHeader("🔗 FOREIGN KEYS");

        // First get basic foreign key information
        $query = "
            SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = ? 
            AND TABLE_NAME = ? 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$dbName, $tableName]);
        $foreignKeys = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($foreignKeys)) {
            $totalWidth = 78;
            $message = "No foreign keys found";
            $messageWidth = $this->getDisplayWidth($message);
            $padding = $totalWidth - $messageWidth - 2;
            $this->write("│ " . $message . str_repeat(" ", $padding) . " │\n", 'yellow');
            $this->write("└" . str_repeat("─", $totalWidth) . "┘\n", 'cyan');
            return;
        }

        // Try to get referential constraints for DELETE_RULE and UPDATE_RULE
        $constraintQuery = "
            SELECT 
                CONSTRAINT_NAME,
                DELETE_RULE,
                UPDATE_RULE
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS 
            WHERE CONSTRAINT_SCHEMA = ? 
            AND TABLE_NAME = ?
        ";

        $constraintStmt = $pdo->prepare($constraintQuery);
        $constraintStmt->execute([$dbName, $tableName]);
        $constraints = $constraintStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Create a lookup array for constraints
        $constraintRules = [];
        foreach ($constraints as $constraint) {
            $constraintRules[$constraint['CONSTRAINT_NAME']] = [
                'DELETE_RULE' => $constraint['DELETE_RULE'],
                'UPDATE_RULE' => $constraint['UPDATE_RULE']
            ];
        }

        $tableData = [];
        $headers = ['Constraint', 'Column', 'References', 'On Delete', 'On Update'];

        foreach ($foreignKeys as $fk) {
            $deleteRule = 'N/A';
            $updateRule = 'N/A';
            
            // Add referential actions if available
            if (isset($constraintRules[$fk['CONSTRAINT_NAME']])) {
                $rules = $constraintRules[$fk['CONSTRAINT_NAME']];
                $deleteRule = $rules['DELETE_RULE'];
                $updateRule = $rules['UPDATE_RULE'];
            }
            
            $tableData[] = [
                '🔗 ' . $fk['CONSTRAINT_NAME'],
                $fk['COLUMN_NAME'],
                $fk['REFERENCED_TABLE_NAME'] . '.' . $fk['REFERENCED_COLUMN_NAME'],
                $deleteRule,
                $updateRule
            ];
        }

        $this->displayTable($headers, $tableData);
    }

    private function showTableStatistics(\PDO $pdo, string $tableName, string $dbName): void
    {
        $this->displaySectionHeader("📊 STATISTICS");

        $query = "
            SELECT 
                TABLE_ROWS as row_count,
                DATA_LENGTH as data_size,
                INDEX_LENGTH as index_size,
                (DATA_LENGTH + INDEX_LENGTH) as total_size,
                AUTO_INCREMENT as next_auto_increment
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$dbName, $tableName]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($stats) {
            $tableData = [
                ['📋 Total Rows', number_format($stats['row_count'] ?? 0)],
                ['💾 Data Size', $this->formatBytes($stats['data_size'] ?? 0)],
                ['🔍 Index Size', $this->formatBytes($stats['index_size'] ?? 0)],
                ['📦 Total Size', $this->formatBytes($stats['total_size'] ?? 0)],
            ];
            
            if ($stats['next_auto_increment']) {
                $tableData[] = ['🔢 Next Auto Increment', number_format($stats['next_auto_increment'])];
            }

            $this->displayTable(['Metric', 'Value'], $tableData);
        } else {
            $totalWidth = 78;
            $message = "No statistics available";
            $messageWidth = $this->getDisplayWidth($message);
            $padding = $totalWidth - $messageWidth - 2;
            $this->write("│ " . $message . str_repeat(" ", $padding) . " │\n", 'yellow');
            $this->write("└" . str_repeat("─", $totalWidth) . "┘\n", 'cyan');
        }
    }

    private function showTableOptions(\PDO $pdo, string $tableName, string $dbName): void
    {
        $this->displaySectionHeader("⚙️ TABLE OPTIONS");

        $query = "
            SELECT 
                ENGINE,
                TABLE_COLLATION,
                CREATE_TIME,
                UPDATE_TIME,
                TABLE_COMMENT
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$dbName, $tableName]);
        $options = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($options) {
            $tableData = [
                ['🚀 Engine', $options['ENGINE'] ?? 'Unknown'],
                ['🔤 Collation', $options['TABLE_COLLATION'] ?? 'Unknown'],
            ];
            
            if ($options['CREATE_TIME']) {
                $tableData[] = ['📅 Created', $options['CREATE_TIME']];
            }
            
            if ($options['UPDATE_TIME']) {
                $tableData[] = ['🔄 Last Updated', $options['UPDATE_TIME']];
            }
            
            if ($options['TABLE_COMMENT']) {
                $tableData[] = ['💬 Comment', $options['TABLE_COMMENT']];
            }

            $this->displayTable(['Option', 'Value'], $tableData);
        } else {
            $totalWidth = 78;
            $message = "No table options available";
            $messageWidth = $this->getDisplayWidth($message);
            $padding = $totalWidth - $messageWidth - 2;
            $this->write("│ " . $message . str_repeat(" ", $padding) . " │\n", 'yellow');
            $this->write("└" . str_repeat("─", $totalWidth) . "┘\n", 'cyan');
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function displayTableHeader(string $tableName): void
    {
        $totalWidth = 78; // Fixed total width for all borders
        $this->write("\n", 'white');
        $this->write("╔" . str_repeat("═", $totalWidth) . "╗\n", 'cyan');
        
        $text = "🗄️  TABLE: " . strtoupper($tableName);
        $textWidth = $this->getDisplayWidth($text);
        $padding = $totalWidth - $textWidth;
        $leftPad = intval($padding / 2);
        $rightPad = $padding - $leftPad;
        
        $this->write("║" . str_repeat(" ", $leftPad) . $text . str_repeat(" ", $rightPad) . "║\n", 'yellow');
        $this->write("╚" . str_repeat("═", $totalWidth) . "╝\n", 'cyan');
    }

    private function displaySectionHeader(string $title): void
    {
        $totalWidth = 78; // Fixed total width for all borders
        $this->write("\n", 'white');
        $this->write("┌" . str_repeat("─", $totalWidth) . "┐\n", 'cyan');
        
        $titleWidth = $this->getDisplayWidth($title);
        $padding = $totalWidth - $titleWidth - 2; // -2 for the space after │ and before │
        
        $this->write("│ " . $title . str_repeat(" ", $padding) . " │\n", 'green');
        $this->write("├" . str_repeat("─", $totalWidth) . "┤\n", 'cyan');
    }

    private function displayTable(array $headers, array $data): void
    {
        if (empty($data)) {
            $totalWidth = 78;
            $message = "No data available";
            $messageWidth = $this->getDisplayWidth($message);
            $padding = $totalWidth - $messageWidth - 2; // -2 for spaces
            $this->write("│ " . $message . str_repeat(" ", $padding) . " │\n", 'yellow');
            $this->write("└" . str_repeat("─", $totalWidth) . "┘\n", 'cyan');
            return;
        }

        // Calculate column widths more accurately
        $columnWidths = [];
        $numColumns = count($headers);
        $totalOuterWidth = 78; // Fixed total width
        $totalBorderWidth = 1 + ($numColumns * 3) + 1; // │ + (numColumns * " │ ") + │
        $availableWidth = $totalOuterWidth - $totalBorderWidth;
        
        // Initialize with header widths (accounting for unicode/emoji properly)
        foreach ($headers as $i => $header) {
            $columnWidths[$i] = $this->getDisplayWidth($header);
        }
        
        // Check data widths
        foreach ($data as $row) {
            foreach ($row as $i => $cell) {
                $cellWidth = $this->getDisplayWidth($cell);
                if ($cellWidth > $columnWidths[$i]) {
                    $columnWidths[$i] = $cellWidth;
                }
            }
        }
        
        // Adjust widths if they exceed available space
        $totalUsed = array_sum($columnWidths);
        if ($totalUsed > $availableWidth) {
            // Distribute available width proportionally
            $factor = $availableWidth / $totalUsed;
            foreach ($columnWidths as $i => $width) {
                $columnWidths[$i] = max(6, floor($width * $factor)); // Minimum 6 chars
            }
        }

        // Display headers
        $this->write("│", 'cyan');
        foreach ($headers as $i => $header) {
            $this->write(" " . $this->padString($header, $columnWidths[$i]), 'yellow');
            $this->write(" │", 'cyan');
        }
        $this->write("\n");

        // Header separator
        $this->write("├", 'cyan');
        foreach ($columnWidths as $i => $width) {
            $this->write(str_repeat("─", $width + 2), 'cyan');
            if ($i < count($columnWidths) - 1) {
                $this->write("┼", 'cyan');
            }
        }
        $this->write("┤\n", 'cyan');

        // Display data rows
        foreach ($data as $row) {
            $this->write("│", 'cyan');
            foreach ($row as $i => $cell) {
                $truncated = $this->getDisplayWidth($cell) > $columnWidths[$i] 
                    ? $this->truncateString($cell, $columnWidths[$i] - 3) . '...'
                    : $cell;
                $this->write(" " . $this->padString($truncated, $columnWidths[$i]), 'white');
                $this->write(" │", 'cyan');
            }
            $this->write("\n");
        }

        // Bottom border
        $totalWidth = 78;
        $this->write("└" . str_repeat("─", $totalWidth) . "┘\n", 'cyan');
    }

    private function getDisplayWidth(string $text): int
    {
        // Remove ANSI escape sequences and handle unicode/emoji properly
        $clean = preg_replace('/\x1b\[[0-9;]*m/', '', $text);
        return mb_strwidth($clean);
    }

    private function padString(string $text, int $width): string
    {
        $displayWidth = $this->getDisplayWidth($text);
        $padding = $width - $displayWidth;
        return $text . str_repeat(' ', max(0, $padding));
    }

    private function truncateString(string $text, int $maxWidth): string
    {
        if ($this->getDisplayWidth($text) <= $maxWidth) {
            return $text;
        }
        
        $truncated = '';
        $currentWidth = 0;
        
        for ($i = 0; $i < mb_strlen($text); $i++) {
            $char = mb_substr($text, $i, 1);
            $charWidth = mb_strwidth($char);
            
            if ($currentWidth + $charWidth > $maxWidth) {
                break;
            }
            
            $truncated .= $char;
            $currentWidth += $charWidth;
        }
        
        return $truncated;
    }
} 