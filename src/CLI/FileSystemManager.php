<?php

namespace Gemvc\CLI;

use Gemvc\CLI\Command;

/**
 * File System Manager
 * 
 * Centralized file and directory operations for CLI commands.
 * Follows Single Responsibility Principle - only handles file system operations.
 * Highly reusable across all CLI commands to eliminate code duplication.
 */
class FileSystemManager extends Command
{
    private bool $nonInteractive = false;
    
    public function __construct(bool $nonInteractive = false)
    {
        $this->nonInteractive = $nonInteractive;
    }
    
    /**
     * Required by Command abstract class
     */
    public function execute()
    {
        throw new \RuntimeException("FileSystemManager should not be executed directly. Use its methods instead.");
    }
    
    /**
     * Create a single directory with proper error handling
     */
    public function createDirectoryIfNotExists(string $path): void
    {
        if (is_dir($path)) {
            $this->info("Directory already exists: {$path}");
            return;
        }
        
        if (!@mkdir($path, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$path}");
        }
        
        $this->info("Created directory: {$path}");
    }
    
    /**
     * Create multiple directories
     */
    public function createDirectories(array $paths): void
    {
        foreach ($paths as $path) {
            $this->createDirectoryIfNotExists($path);
        }
    }
    
    /**
     * Copy a file with user confirmation if needed
     */
    public function copyFileWithConfirmation(string $sourcePath, string $targetPath, string $fileName): void
    {
        if (file_exists($targetPath) && !$this->nonInteractive) {
            if (!$this->confirmFileOverwrite($targetPath)) {
                $this->info("Skipped: {$fileName}");
                return;
            }
        } elseif (file_exists($targetPath) && $this->nonInteractive) {
            $this->info("File already exists (non-interactive mode): {$targetPath} - will be overwritten");
        }
        
        if (!copy($sourcePath, $targetPath)) {
            throw new \RuntimeException("Failed to copy file: {$sourcePath} to {$targetPath}");
        }
        
        $this->info("Copied: {$fileName}");
    }
    
    /**
     * Recursively copy directory contents
     */
    public function copyDirectoryContents(string $sourceDir, string $targetDir): void
    {
        $files = array_diff(scandir($sourceDir), ['.', '..']);
        
        foreach ($files as $file) {
            $sourcePath = $sourceDir . '/' . $file;
            $targetPath = $targetDir . '/' . $file;
            
            if (is_dir($sourcePath)) {
                $this->createDirectoryIfNotExists($targetPath);
                $this->copyDirectoryContents($sourcePath, $targetPath);
            } else {
                $this->copyFileWithConfirmation($sourcePath, $targetPath, $file);
            }
        }
    }
    
    /**
     * Write file content to disk with confirmation
     */
    public function writeFile(string $path, string $content, string $fileType): void
    {
        if (!$this->confirmFileOverwrite($path)) {
            $this->info("Skipped {$fileType}: " . basename($path));
            return;
        }

        if (!file_put_contents($path, $content)) {
            throw new \RuntimeException("Failed to create {$fileType} file: {$path}");
        }
        
        $this->info("Created {$fileType}: " . basename($path));
    }
    
    /**
     * Ask user for file overwrite confirmation
     */
    public function confirmFileOverwrite(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return true;
        }
        
        echo "File already exists: {$filePath}" . PHP_EOL;
        echo "Do you want to overwrite it? (y/N): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        return strtolower(trim($line)) === 'y';
    }
    
    /**
     * Ensure directory exists, create if it doesn't
     */
    public function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            $this->createDirectoryIfNotExists($path);
        }
    }
    
    /**
     * Check if file exists
     */
    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }
    
    /**
     * Check if directory exists
     */
    public function directoryExists(string $path): bool
    {
        return is_dir($path);
    }
    
    /**
     * Copy README.md to project root
     */
    public function copyReadmeToRoot(string $packagePath, string $basePath): void
    {
        $sourceReadme = $packagePath . '/src/startup/README.md';
        $targetReadme = $basePath . '/README.md';
        
        if (!file_exists($sourceReadme)) {
            $this->warning("Source README.md not found: {$sourceReadme} - skipping README copy");
            return;
        }
        
        $this->copyFileWithConfirmation($sourceReadme, $targetReadme, "README.md");
    }
    
    /**
     * Copy templates folder to project root
     */
    public function copyTemplatesFolder(string $packagePath, string $basePath): void
    {
        $sourceTemplatesPath = $packagePath . '/src/CLI/templates';
        $targetTemplatesPath = $basePath . '/templates';
        
        if (!is_dir($sourceTemplatesPath)) {
            $this->warning("Templates directory not found: {$sourceTemplatesPath}");
            return;
        }
        
        $this->createDirectoryIfNotExists($targetTemplatesPath);
        $this->copyDirectoryContents($sourceTemplatesPath, $targetTemplatesPath);
    }
    
    /**
     * Get file content safely
     */
    public function getFileContent(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }
        
        return $content;
    }
    
    /**
     * Set non-interactive mode
     */
    public function setNonInteractive(bool $nonInteractive): void
    {
        $this->nonInteractive = $nonInteractive;
    }
    
    /**
     * Get non-interactive mode
     */
    public function isNonInteractive(): bool
    {
        return $this->nonInteractive;
    }
}
