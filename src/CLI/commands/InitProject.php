<?php

namespace Gemvc\CLI\Commands;

use Gemvc\CLI\Command;
use Gemvc\CLI\FileSystemManager;
use Gemvc\CLI\DockerComposeInit;

/**
 * Initialize a new GEMVC OpenSwoole project
 * 
 * This command automatically sets up a new project using the OpenSwoole template
 * without asking the user to choose between Apache and Swoole templates.
 * 
 * Optimized version with improved code organization, reduced duplication,
 * and better error handling.
 */
class InitProject extends Command
{
    private string $basePath;
    private string $packagePath;
    private bool $nonInteractive = false;
    private ?string $templateName = null;
    private FileSystemManager $fileSystem;
    
    // Configuration arrays for better maintainability
    private const REQUIRED_DIRECTORIES = [
        'app',
        'app/api',
        'app/controller', 
        'app/model',
        'app/table',
        'bin'
    ];
    
    private const USER_FILE_MAPPINGS = [
        'app/api' => ['User.php'],
        'app/controller' => ['UserController.php'],
        'app/model' => ['UserModel.php'],
        'app/table' => ['UserTable.php']
    ];
    
    private const SPECIAL_FILE_MAPPINGS = [
        'appIndex.php' => 'app/api/Index.php'
    ];
    
    // PSR-4 autoload configuration for the framework
    private const PSR4_AUTOLOAD = [
        'App\\Api\\' => 'app/api/',
        'App\\Controller\\' => 'app/controller/',
        'App\\Model\\' => 'app/model/',
        'App\\Table\\' => 'app/table/'
    ];
    
    
    public function execute()
    {
        $this->initializeProject();
        
        try {
            $this->setupProjectStructure();
            $this->copyProjectFiles();
            $this->setupPsr4Autoload();
            $this->createEnvFile();
            $this->createGlobalCommand();
            $this->finalizeAutoload();
            $this->offerDockerServices();
            $this->displayNextSteps();
            $this->offerOptionalTools();
            
            $this->displaySuccessGraphic();
        } catch (\Exception $e) {
            $this->error("Project initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize project settings and paths
     */
    private function initializeProject(): void
    {
        $this->nonInteractive = in_array('--non-interactive', $this->args) || in_array('-n', $this->args);
        
        if ($this->nonInteractive) {
            $this->info("Running in non-interactive mode - will automatically accept defaults and overwrite files");
        }
        
        $this->info("🚀 Initializing GEMVC OpenSwoole project...");
        
        $this->basePath = defined('PROJECT_ROOT') ? PROJECT_ROOT : $this->determineProjectRoot();
        $this->packagePath = $this->determinePackagePath();
        $this->templateName = 'openswoole';
        
        // Initialize FileSystemManager with verbose mode disabled
        $this->fileSystem = new FileSystemManager($this->nonInteractive, false);
    }
    
    /**
     * Setup the basic project structure
     */
    private function setupProjectStructure(): void
    {
        $this->info("📁 Setting up project structure...");
        $this->createDirectories();
        $this->copyTemplatesFolder();
        $this->copyReadmeToRoot();
        $this->info("✅ Project structure created");
    }
    
    /**
     * Copy all project files from startup template
     */
    private function copyProjectFiles(): void
    {
        $this->info("📄 Copying project files...");
        $startupPath = $this->findStartupPath();
        $this->copyTemplateFiles($startupPath);
        $this->copyUserFiles($startupPath);
        $this->info("✅ Project files copied");
    }
    
    /**
     * Create directories with improved error handling
     */
    private function createDirectories(): void
    {
        $directories = array_map(function($dir) {
            return $this->basePath . '/' . $dir;
        }, self::REQUIRED_DIRECTORIES);
        
        $this->fileSystem->createDirectories($directories);
    }
    
    /**
     * Copy README.md to project root
     */
    private function copyReadmeToRoot(): void
    {
        $this->fileSystem->copyReadmeToRoot($this->packagePath, $this->basePath);
    }
    
    /**
     * Find the startup template path
     */
    private function findStartupPath(): string
    {
        $potentialPaths = [
            $this->packagePath . '/src/startup',
            $this->packagePath . '/startup',
            dirname(dirname(dirname(__DIR__))) . '/startup'
        ];
        
        foreach ($potentialPaths as $path) {
            if (is_dir($path)) {
                $this->info("Found startup directory: {$path}");
                return $path;
            }
        }
        
        throw new \RuntimeException("Startup directory not found. Tried: " . implode(", ", $potentialPaths));
    }
    
    /**
     * Copy template files from startup directory
     */
    private function copyTemplateFiles(string $templateDir): void
    {
        if (!is_dir($templateDir)) {
            throw new \RuntimeException("Template directory not found: {$templateDir}");
        }
        
        $this->info("Using OpenSwoole startup template");
        
        $files = array_diff(scandir($templateDir), ['.', '..']);
        
        foreach ($files as $file) {
            $sourcePath = $templateDir . '/' . $file;
            
            // Skip directories
            if (is_dir($sourcePath)) {
                continue;
            }
            
            $destPath = $this->getDestinationPath($file);
            $this->fileSystem->copyFileWithConfirmation($sourcePath, $destPath, $file);
        }
    }
    
    /**
     * Setup PSR-4 autoload configuration in composer.json
     */
    private function setupPsr4Autoload(): void
    {
        $composerJsonPath = $this->basePath . '/composer.json';
        $this->info("⚙️ Configuring PSR-4 autoload...");
        
        // Read existing composer.json
        $composerJson = [];
        if (file_exists($composerJsonPath)) {
            $content = file_get_contents($composerJsonPath);
            if ($content !== false) {
                $composerJson = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->warning("Failed to parse existing composer.json, will create new one");
                    $composerJson = [];
                }
            }
        }
        
        // Ensure autoload section exists
        if (!isset($composerJson['autoload'])) {
            $composerJson['autoload'] = [];
        }
        
        // Ensure PSR-4 section exists
        if (!isset($composerJson['autoload']['psr-4'])) {
            $composerJson['autoload']['psr-4'] = [];
        }
        
        // Add PSR-4 mappings if they don't exist
        $addedMappings = false;
        foreach (self::PSR4_AUTOLOAD as $namespace => $path) {
            if (!isset($composerJson['autoload']['psr-4'][$namespace])) {
                $composerJson['autoload']['psr-4'][$namespace] = $path;
                $addedMappings = true;
            }
        }
        
        // Write the updated composer.json
        $updatedJson = json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!file_put_contents($composerJsonPath, $updatedJson)) {
            throw new \RuntimeException("Failed to update composer.json with PSR-4 autoload");
        }
        
        $this->info("✅ PSR-4 autoload configured");
    }
    
    /**
     * Finalize autoload by running composer dump-autoload
     */
    private function finalizeAutoload(): void
    {
        $this->info("🔄 Finalizing autoload...");
        
        $currentDir = getcwd();
        chdir($this->basePath);
        
        $output = [];
        $returnCode = 0;
        exec('composer dump-autoload 2>&1', $output, $returnCode);
        
        chdir($currentDir);
        
        if ($returnCode !== 0) {
            $this->warning("Failed to run composer dump-autoload. You may need to run it manually:");
            $this->write("  composer dump-autoload\n", 'yellow');
            foreach ($output as $line) {
                $this->write("  {$line}\n", 'red');
            }
        } else {
            $this->info("✅ Autoload finalized");
        }
    }
    
    /**
     * Get the destination path for a file, handling special mappings
     */
    private function getDestinationPath(string $fileName): string
    {
        if (isset(self::SPECIAL_FILE_MAPPINGS[$fileName])) {
            $destPath = $this->basePath . '/' . self::SPECIAL_FILE_MAPPINGS[$fileName];
            
            // Ensure the target directory exists
            $targetDir = dirname($destPath);
            $this->fileSystem->ensureDirectoryExists($targetDir);
            
            return $destPath;
        }
        
        return $this->basePath . '/' . $fileName;
    }
    
    /**
     * Copy user-related files to appropriate directories
     */
    private function copyUserFiles(string $startupPath): void
    {
        $userDir = $startupPath . '/user';
        if (!is_dir($userDir)) {
            $this->warning("User template directory not found: {$userDir}");
            return;
        }
        
        // Create target directories
        foreach (array_keys(self::USER_FILE_MAPPINGS) as $dir) {
            $targetDir = $this->basePath . '/' . $dir;
            $this->fileSystem->createDirectoryIfNotExists($targetDir);
        }
        
        // Copy files
        foreach (self::USER_FILE_MAPPINGS as $targetDir => $files) {
            foreach ($files as $file) {
                $sourceFile = $userDir . '/' . $file;
                $targetFile = $this->basePath . '/' . $targetDir . '/' . $file;
                
                if (!file_exists($sourceFile)) {
                    $this->warning("Source file not found: {$sourceFile}");
                    continue;
                }
                
                $this->fileSystem->copyFileWithConfirmation($sourceFile, $targetFile, $file);
            }
        }
    }
    
    /**
     * Copy templates folder to project root
     */
    private function copyTemplatesFolder(): void
    {
        $this->fileSystem->copyTemplatesFolder($this->packagePath, $this->basePath);
    }
    
    
    /**
     * Create environment file
     */
    private function createEnvFile(): void
    {
        $this->info("🔧 Creating environment file...");
        $envPath = $this->basePath . '/.env';
        $exampleEnvPath = $this->packagePath . '/src/startup/example.env';
        
        if (!file_exists($exampleEnvPath)) {
            throw new \RuntimeException("Example .env file not found: {$exampleEnvPath}");
        }
        
        $envContent = $this->fileSystem->getFileContent($exampleEnvPath);
        $this->fileSystem->writeFile($envPath, $envContent, '.env file');
        $this->info("✅ Environment file created");
    }
    
    /**
     * Create global command wrapper
     */
    private function createGlobalCommand(): void
    {
        $this->info("🔗 Setting up CLI commands...");
        
        $this->createLocalWrapper();
        $this->createWindowsBatch();
        $this->offerGlobalInstallation();
        $this->info("✅ CLI commands ready");
    }
    
    /**
     * Create local wrapper script
     */
    private function createLocalWrapper(): void
    {
        $wrapperPath = $this->basePath . '/bin/gemvc';
        $wrapperContent = <<<EOT
#!/usr/bin/env php
<?php
// Forward to the vendor binary
require __DIR__ . '/../vendor/bin/gemvc';
EOT;
        
        if (!file_put_contents($wrapperPath, $wrapperContent)) {
            $this->warning("Failed to create local wrapper script: {$wrapperPath}");
            return;
        }
        
        chmod($wrapperPath, 0755);
    }
    
    /**
     * Create Windows batch file
     */
    private function createWindowsBatch(): void
    {
        $batPath = $this->basePath . '/bin/gemvc.bat';
        $batContent = <<<EOT
@echo off
php "%~dp0..\vendor\bin\gemvc" %*
EOT;
        
        file_put_contents($batPath, $batContent);
    }
    
    /**
     * Offer global installation
     */
    private function offerGlobalInstallation(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->displayWindowsInstructions();
            return;
        }
        
        if (!$this->nonInteractive) {
            $this->askForGlobalInstallation();
        } else {
            $this->info("Skipped global command setup (non-interactive mode)");
        }
    }
    
    /**
     * Display Windows PATH instructions
     */
    private function displayWindowsInstructions(): void
    {
        $this->write("\nFor global access on Windows:\n", 'blue');
        $this->write("  1. Add this directory to your PATH: " . realpath($this->basePath . '/bin') . "\n", 'white');
        $this->write("  2. Then you can run 'gemvc' from any location\n\n", 'white');
    }
    
    /**
     * Ask user for global installation
     */
    private function askForGlobalInstallation(): void
    {
        echo "Would you like to create a global 'gemvc' command? (y/N): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        if (strtolower(trim($line)) !== 'y') {
            $this->displayAlternativeUsage();
            return;
        }
        
        $this->attemptGlobalInstallation();
    }
    
    /**
     * Display alternative usage instructions
     */
    private function displayAlternativeUsage(): void
    {
        $this->info("Skipped global command setup");
        $this->write("\nYou can still use the command with:\n", 'blue');
        $this->write("  php vendor/bin/gemvc [command]\n", 'white');
        $this->write("  OR\n", 'white');
        $this->write("  php bin/gemvc [command]\n\n", 'white');
    }
    
    /**
     * Attempt to create global symlink
     */
    private function attemptGlobalInstallation(): void
    {
        $wrapperPath = $this->basePath . '/bin/gemvc';
        $globalPaths = ['/usr/local/bin', '/usr/bin', getenv('HOME') . '/.local/bin'];
        
        foreach ($globalPaths as $globalPath) {
            if (is_dir($globalPath) && is_writable($globalPath)) {
                $globalBinPath = $globalPath . '/gemvc';
                
                if (file_exists($globalBinPath)) {
                    if (!$this->fileSystem->confirmFileOverwrite($globalBinPath)) {
                        continue;
                    }
                    @unlink($globalBinPath);
                }
                
                try {
                    $realPath = realpath($wrapperPath);
                    if (symlink($realPath, $globalBinPath)) {
                        $this->success("Created global command: {$globalBinPath}");
                        return;
                    }
                } catch (\Exception $e) {
                    // Continue to next path
                }
            }
        }
        
        $this->displayManualInstallationInstructions($wrapperPath);
    }
    
    /**
     * Display manual installation instructions
     */
    private function displayManualInstallationInstructions(string $wrapperPath): void
    {
        $this->warning("Could not create global command. You may need root privileges.");
        $this->write("\nManual setup: \n", 'blue');
        $this->write("  1. Run: sudo ln -s " . realpath($wrapperPath) . " /usr/local/bin/gemvc\n", 'white');
        $this->write("  2. Make it executable: sudo chmod +x /usr/local/bin/gemvc\n\n", 'white');
    }
    
    /**
     * Display next steps to user
     */
    private function displayNextSteps(): void
    {
        $boxShow = new CliBoxShow();
        
        $lines = [
            "\033[1;92m✅ Project Ready!\033[0m",
            " \033[1;36m$ \033[1;95mphp bin/gemvc\033[0m",
            "   \033[90m# PSR-4 autoload configured and ready to use\033[0m",
            "",
            "\033[1;94mOptional - Development Environment:\033[0m",
            " \033[1;36m$ \033[1;95mcomposer update\033[0m",
            "   \033[90m# Only if you want to install additional dev dependencies\033[0m"
        ];
        
        $boxShow->displayBox("Next Steps", $lines);
    }
    
    /**
     * Offer Docker services installation
     */
    private function offerDockerServices(): void
    {
        $dockerInit = new DockerComposeInit($this->basePath, $this->nonInteractive);
        $dockerInit->offerDockerServices();
    }
    
    /**
     * Offer optional tools installation
     */
    private function offerOptionalTools(): void
    {
        $toolsInstaller = new OptionalToolsInstaller($this->basePath, $this->packagePath, $this->nonInteractive);
        $toolsInstaller->offerOptionalTools();
    }
    
    /**
     * Display beautiful success graphic
     */
    private function displaySuccessGraphic(): void
    {
        $this->write("\n", 'white');
        $this->write("    ╔══════════════════════════════════════════════════════════════╗\n", 'green');
        $this->write("    ║                    🎯 SUCCESS! 🎯                           ║\n", 'green');
        $this->write("    ║           GEMVC OpenSwoole Project Ready!                    ║\n", 'green');
        $this->write("    ║             run:docker compose up --build                    ║\n", 'green');
        $this->write("    ╚══════════════════════════════════════════════════════════════╝\n", 'green');
        $this->write("\n", 'white');
    }
    
    
    
    /**
     * Determine project root directory
     */
    private function determineProjectRoot(): string
    {
        $vendorDir = dirname(dirname(dirname(dirname(__DIR__))));
        
        if (basename($vendorDir) === 'vendor') {
            return dirname($vendorDir);
        }
        
        return getcwd() ?: '.';
    }
    
    /**
     * Determine package path
     */
    private function determinePackagePath(): string
    {
        $paths = [
            dirname(dirname(dirname(__DIR__))),
            dirname(dirname(dirname(dirname(__DIR__)))) . '/gemvc/library',
            dirname(dirname(dirname(dirname(__DIR__)))) . '/gemvc/framework'
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $this->info("Using package path: {$path}");
                return $path;
            }
        }
        
        $currentDir = dirname(dirname(dirname(__FILE__)));
        $this->warning("Using fallback package path: {$currentDir}");
        return dirname($currentDir);
    }
}
