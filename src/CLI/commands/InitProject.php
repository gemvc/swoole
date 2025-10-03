<?php

namespace Gemvc\CLI\Commands;

use Gemvc\CLI\Command;

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
    
    
    public function execute()
    {
        $this->initializeProject();
        
        try {
            $this->setupProjectStructure();
            $this->copyProjectFiles();
            $this->createEnvFile();
            $this->createGlobalCommand();
            $this->displayNextSteps();
            $this->offerOptionalTools();
            
            $this->success("GEMVC OpenSwoole project initialized successfully!", true);
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
        
        $this->info("Initializing GEMVC OpenSwoole project...");
        
        $this->basePath = defined('PROJECT_ROOT') ? PROJECT_ROOT : $this->determineProjectRoot();
        $this->packagePath = $this->determinePackagePath();
        $this->templateName = 'openswoole';
    }
    
    /**
     * Setup the basic project structure
     */
    private function setupProjectStructure(): void
    {
        $this->createDirectories();
        $this->copyTemplatesFolder();
        $this->copyReadmeToRoot();
    }
    
    /**
     * Copy all project files from startup template
     */
    private function copyProjectFiles(): void
    {
        $startupPath = $this->findStartupPath();
        $this->copyTemplateFiles($startupPath);
        $this->copyUserFiles($startupPath);
    }
    
    /**
     * Create directories with improved error handling
     */
    private function createDirectories(): void
    {
        foreach (self::REQUIRED_DIRECTORIES as $directory) {
            $fullPath = $this->basePath . '/' . $directory;
            $this->createDirectoryIfNotExists($fullPath);
        }
    }
    
    /**
     * Create a single directory with proper error handling
     */
    private function createDirectoryIfNotExists(string $path): void
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
     * Copy README.md to project root
     */
    private function copyReadmeToRoot(): void
    {
        $sourceReadme = $this->packagePath . '/src/startup/README.md';
        $targetReadme = $this->basePath . '/README.md';
        
        if (!file_exists($sourceReadme)) {
            $this->warning("Source README.md not found: {$sourceReadme} - skipping README copy");
            return;
        }
        
        $this->copyFileWithConfirmation($sourceReadme, $targetReadme, "README.md");
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
            $this->copyFileWithConfirmation($sourcePath, $destPath, $file);
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
            if (!is_dir($targetDir)) {
                $this->createDirectoryIfNotExists($targetDir);
            }
            
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
            $this->createDirectoryIfNotExists($targetDir);
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
                
                $this->copyFileWithConfirmation($sourceFile, $targetFile, $file);
            }
        }
    }
    
    /**
     * Copy templates folder to project root
     */
    private function copyTemplatesFolder(): void
    {
        $sourceTemplatesPath = $this->packagePath . '/src/CLI/templates';
        $targetTemplatesPath = $this->basePath . '/templates';
        
        if (!is_dir($sourceTemplatesPath)) {
            $this->warning("Templates directory not found: {$sourceTemplatesPath}");
            return;
        }
        
        $this->createDirectoryIfNotExists($targetTemplatesPath);
        $this->copyDirectoryContents($sourceTemplatesPath, $targetTemplatesPath);
    }
    
    /**
     * Recursively copy directory contents
     */
    private function copyDirectoryContents(string $sourceDir, string $targetDir): void
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
     * Copy a file with user confirmation if needed
     */
    private function copyFileWithConfirmation(string $sourcePath, string $targetPath, string $fileName): void
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
     * Ask user for file overwrite confirmation
     */
    private function confirmFileOverwrite(string $filePath): bool
    {
        echo "File already exists: {$filePath}" . PHP_EOL;
        echo "Do you want to overwrite it? (y/N): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        return strtolower(trim($line)) === 'y';
    }
    
    /**
     * Create environment file
     */
    private function createEnvFile(): void
    {
        $envPath = $this->basePath . '/.env';
        $exampleEnvPath = $this->packagePath . '/src/startup/example.env';
        
        if (!file_exists($exampleEnvPath)) {
            throw new \RuntimeException("Example .env file not found: {$exampleEnvPath}");
        }
        
        if (file_exists($envPath) && !$this->nonInteractive) {
            if (!$this->confirmFileOverwrite($envPath)) {
                $this->info("Skipped .env creation");
                return;
            }
        } elseif (file_exists($envPath) && $this->nonInteractive) {
            $this->info("File already exists (non-interactive mode): {$envPath} - will be overwritten");
        }
        
        $envContent = file_get_contents($exampleEnvPath);
        if ($envContent === false) {
            throw new \RuntimeException("Failed to read example .env file: {$exampleEnvPath}");
        }
        
        if (!file_put_contents($envPath, $envContent)) {
            throw new \RuntimeException("Failed to create .env file: {$envPath}");
        }
        
        $this->info("Created .env file: {$envPath}");
    }
    
    /**
     * Create global command wrapper
     */
    private function createGlobalCommand(): void
    {
        $this->info("Setting up global command...");
        
        $this->createLocalWrapper();
        $this->createWindowsBatch();
        $this->offerGlobalInstallation();
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
        $this->info("Created local command wrapper: {$wrapperPath}");
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
        
        if (file_put_contents($batPath, $batContent)) {
            $this->info("Created Windows batch file: {$batPath}");
        }
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
                    if (!$this->confirmFileOverwrite($globalBinPath)) {
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
        $this->write("\033[1;33m╭─ Next Steps ───────────────────────────────────────────────╮\033[0m\n", 'yellow');
        
        // Ready to use
        $this->write("\033[1;33m│\033[0m \033[1;92m✅ Project Ready!\033[0m                                          \033[1;33m│\033[0m\n", 'white');
        $this->write("\033[1;33m│\033[0m  \033[1;36m$ \033[1;95mphp bin/gemvc\033[0m                                       \033[1;33m│\033[0m\n", 'white');
        $this->write("\033[1;33m│\033[0m    \033[90m# Your project is ready to use immediately\033[0m                \033[1;33m│\033[0m\n", 'white');
        
        // Separator
        $this->write("\033[1;33m│\033[0m                                                             \033[1;33m│\033[0m\n", 'white');
        
        // Optional: Development Environment
        $this->write("\033[1;33m│\033[0m \033[1;94mOptional - Development Environment:\033[0m                      \033[1;33m│\033[0m\n", 'white');
        $this->write("\033[1;33m│\033[0m  \033[1;36m$ \033[1;95mcomposer update\033[0m                                        \033[1;33m│\033[0m\n", 'white');
        $this->write("\033[1;33m│\033[0m    \033[90m# Only if you want to install additional dev dependencies\033[0m  \033[1;33m│\033[0m\n", 'white');
        
        $this->write("\033[1;33m╰───────────────────────────────────────────────────────╯\033[0m\n\n", 'yellow');
    }
    
    /**
     * Offer optional tools installation
     */
    private function offerOptionalTools(): void
    {
        if ($this->nonInteractive) {
            $this->info("Skipped optional tools installation (non-interactive mode)");
            return;
        }
        
        $this->offerPhpstanInstallation();
        $this->offerTestingFrameworkInstallation();
    }
    
    /**
     * Offer PHPStan installation
     */
    private function offerPhpstanInstallation(): void
    {
        $this->displayToolInstallationPrompt(
            "PHPStan Installation",
            "Would you like to install PHPStan for static analysis?",
            "PHPStan will help catch bugs and improve code quality",
            "This will install phpstan/phpstan as a dev dependency"
        );
        
        echo "\n\033[1;36mInstall PHPStan? (y/N):\033[0m ";
        $handle = fopen("php://stdin", "r");
        $choice = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($choice) === 'y') {
            $this->installPhpstan();
        } else {
            $this->info("PHPStan installation skipped");
        }
    }
    
    /**
     * Offer testing framework installation
     */
    private function offerTestingFrameworkInstallation(): void
    {
        $this->displayToolInstallationPrompt(
            "Testing Framework Installation",
            "Would you like to install a testing framework?",
            "Choose between PHPUnit (traditional) or Pest (modern & expressive)"
        );
        
        echo "\n\033[1;36mChoose testing framework:\033[0m\n";
        echo "  [\033[32m1\033[0m] \033[1mPHPUnit\033[0m - Traditional PHP testing framework\n";
        echo "  [\033[32m2\033[0m] \033[1mPest\033[0m - Modern, expressive testing framework\n";
        echo "  [\033[32m3\033[0m] \033[1mSkip\033[0m - No testing framework\n";
        echo "\n\033[1;36mEnter choice (1-3):\033[0m ";
        
        $handle = fopen("php://stdin", "r");
        $choice = trim(fgets($handle));
        fclose($handle);
        
        switch ($choice) {
            case '1':
                $this->installPhpunit();
                break;
            case '2':
                $this->installPest();
                break;
            case '3':
                $this->info("Testing framework installation skipped");
                break;
            default:
                $this->warning("Invalid choice. Testing framework installation skipped.");
                break;
        }
    }
    
    /**
     * Display tool installation prompt
     */
    private function displayToolInstallationPrompt(string $title, string $question, string $description, string $additionalInfo = ''): void
    {
        $this->write("\n\033[1;33m╭─ {$title} ───────────────────────────────────────╮\033[0m\n", 'yellow');
        $this->write("\033[1;33m│\033[0m \033[1;94m{$question}\033[0m        \033[1;33m│\033[0m\n", 'white');
        $this->write("\033[1;33m│\033[0m \033[1;36m{$description}\033[0m      \033[1;33m│\033[0m\n", 'white');
        if ($additionalInfo) {
            $this->write("\033[1;33m│\033[0m \033[1;36m{$additionalInfo}\033[0m    \033[1;33m│\033[0m\n", 'white');
        }
        $this->write("\033[1;33m╰───────────────────────────────────────────────────────────────╯\033[0m\n", 'yellow');
    }
    
    /**
     * Install PHPStan
     */
    private function installPhpstan(): void
    {
        try {
            $this->info("Installing PHPStan...");
            $this->runComposerCommand('require --dev phpstan/phpstan');
            $this->copyPhpstanConfig();
            $this->info("PHPStan installed successfully!");
        } catch (\Exception $e) {
            $this->warning("PHPStan installation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Install PHPUnit
     */
    private function installPhpunit(): void
    {
        try {
            $this->info("Installing PHPUnit...");
            $this->runComposerCommand('require --dev phpunit/phpunit');
            $this->createPhpunitConfig();
            $this->info("PHPUnit installed successfully!");
        } catch (\Exception $e) {
            $this->warning("PHPUnit installation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Install Pest
     */
    private function installPest(): void
    {
        try {
            $this->info("Installing Pest...");
            $this->runComposerCommand('require --dev pestphp/pest');
            $this->initializePest();
            $this->info("Pest installed successfully!");
        } catch (\Exception $e) {
            $this->warning("Pest installation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Run composer command
     */
    private function runComposerCommand(string $command): void
    {
        $composerJsonPath = $this->basePath . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            throw new \RuntimeException("composer.json not found. Please run 'composer init' first.");
        }
        
        $this->info("Running: composer {$command}");
        $output = [];
        $returnCode = 0;
        
        $currentDir = getcwd();
        chdir($this->basePath);
        
        exec("composer {$command} 2>&1", $output, $returnCode);
        
        chdir($currentDir);
        
        if ($returnCode !== 0) {
            $this->warning("Failed to run composer command. Error output:");
            foreach ($output as $line) {
                $this->write("  {$line}\n", 'red');
            }
            throw new \RuntimeException("Composer command failed");
        }
    }
    
    /**
     * Copy PHPStan configuration
     */
    private function copyPhpstanConfig(): void
    {
        $sourceConfig = $this->packagePath . '/src/startup/phpstan.neon';
        $targetConfig = $this->basePath . '/phpstan.neon';
        
        if (!file_exists($sourceConfig)) {
            $this->warning("PHPStan configuration template not found: {$sourceConfig}");
            return;
        }
        
        if (file_exists($targetConfig) && !$this->confirmFileOverwrite($targetConfig)) {
            $this->info("Skipped PHPStan configuration copy");
            return;
        }
        
        if (!copy($sourceConfig, $targetConfig)) {
            throw new \RuntimeException("Failed to copy PHPStan configuration file");
        }
        
        $this->info("PHPStan configuration copied: {$targetConfig}");
    }
    
    /**
     * Create PHPUnit configuration
     */
    private function createPhpunitConfig(): void
    {
        $targetConfig = $this->basePath . '/phpunit.xml';
        
        if (file_exists($targetConfig) && !$this->confirmFileOverwrite($targetConfig)) {
            $this->info("Skipped PHPUnit configuration creation");
            return;
        }
        
        $phpunitConfig = '<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         processIsolation="false"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="GEMVC Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">app</directory>
        </include>
    </coverage>
</phpunit>';
        
        if (!file_put_contents($targetConfig, $phpunitConfig)) {
            throw new \RuntimeException("Failed to create PHPUnit configuration file");
        }
        
        $this->info("PHPUnit configuration created: {$targetConfig}");
        
        // Create tests directory
        $testsDir = $this->basePath . '/tests';
        $this->createDirectoryIfNotExists($testsDir);
    }
    
    /**
     * Initialize Pest
     */
    private function initializePest(): void
    {
        $this->info("Initializing Pest...");
        
        $currentDir = getcwd();
        chdir($this->basePath);
        
        $output = [];
        $returnCode = 0;
        exec('./vendor/bin/pest --init 2>&1', $output, $returnCode);
        
        chdir($currentDir);
        
        if ($returnCode !== 0) {
            $this->warning("Failed to initialize Pest. Error output:");
            foreach ($output as $line) {
                $this->write("  {$line}\n", 'red');
            }
            throw new \RuntimeException("Pest initialization failed");
        }
        
        $this->info("Pest initialized successfully!");
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
