<?php

namespace Gemvc\CLI;

use Gemvc\CLI\Command;
use Gemvc\CLI\FileSystemManager;
use Gemvc\CLI\DockerComposeInit;
use Gemvc\CLI\Commands\OptionalToolsInstaller;
use Gemvc\CLI\Commands\CliBoxShow;

/**
 * Abstract Base Class for Webserver Initialization
 * 
 * This abstract class contains all shared functionality for initializing
 * GEMVC projects with different webservers (OpenSwoole, Apache, Nginx).
 * 
 * Uses Template Method Pattern to define the skeleton of initialization
 * while allowing subclasses to override specific steps.
 * 
 * @package Gemvc\CLI
 */
abstract class AbstractInit extends Command
{
    protected string $basePath;
    protected string $packagePath;
    protected string $packageName = 'swoole';
    protected bool $nonInteractive = false;
    protected FileSystemManager $fileSystem;
    
    // Base PSR-4 autoload configuration (common for all webservers)
    protected const BASE_PSR4_AUTOLOAD = [
        'App\\Api\\' => 'app/api/',
        'App\\Controller\\' => 'app/controller/',
        'App\\Model\\' => 'app/model/',
        'App\\Table\\' => 'app/table/'
    ];
    
    // Base required directories (common for all webservers)
    protected const BASE_REQUIRED_DIRECTORIES = [
        'app',
        'app/api',
        'app/controller',
        'app/model',
        'app/table',
        'bin'
    ];
    
    // User template files mapping (common for all webservers)
    protected const USER_FILE_MAPPINGS = [
        'app/api' => ['User.php'],
        'app/controller' => ['UserController.php'],
        'app/model' => ['UserModel.php'],
        'app/table' => ['UserTable.php']
    ];
    
    /**
     * Template Method - defines the skeleton of initialization process
     * This method is final and cannot be overridden by subclasses
     * 
     * @return bool
     */
    final public function execute(): bool
    {
        $this->initializeProject();
        
        try {
            $this->setupProjectStructure();
            $this->copyCommonProjectFiles();
            $this->copyWebserverSpecificFiles();
            $this->setupPsr4Autoload();
            $this->createEnvFile();
            $this->createGlobalCommand();
            $this->finalizeAutoload();
            // $this->offerDockerServices(); // Commented: docker-compose.yml is now copied from webserver-specific folder
            $this->displayNextSteps();
            $this->offerOptionalTools();
            $this->displaySuccessGraphic();
            
            return true;
        } catch (\Exception $e) {
            $this->error("Project initialization failed: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================================================
    // ABSTRACT METHODS - Must be implemented by each webserver
    // ========================================================================
    
    /**
     * Get the webserver type identifier
     * @return string 'swoole', 'apache', or 'nginx'
     */
    abstract protected function getWebserverType(): string;
    
    /**
     * Get webserver-specific required directories
     * @return array<string> Additional directories beyond BASE_REQUIRED_DIRECTORIES
     */
    abstract protected function getWebserverSpecificDirectories(): array;
    
    /**
     * Copy webserver-specific files
     * (e.g., server handlers for Swoole, .htaccess for Apache, nginx.conf for Nginx)
     * 
     * @return void
     */
    abstract protected function copyWebserverSpecificFiles(): void;
    
    /**
     * Get the startup template path for this webserver
     * @return string Path to startup template directory
     */
    abstract protected function getStartupTemplatePath(): string;
    
    /**
     * Get webserver-specific file mappings (if any)
     * @return array<string, string> Special file mappings
     */
    abstract protected function getWebserverSpecificFileMappings(): array;
    
    /**
     * Get the default port number for this webserver
     * @return int Default port (e.g., 9501 for Swoole, 80 for Apache/Nginx)
     */
    abstract protected function getDefaultPort(): int;
    
    /**
     * Get the command to start the webserver
     * @return string Command to start server (e.g., 'php index.php', 'apache2ctl start')
     */
    abstract protected function getStartCommand(): string;
    
    /**
     * Get additional webserver-specific instruction lines (optional)
     * @return array<string> Additional instruction lines to display
     */
    protected function getAdditionalInstructions(): array
    {
        return []; // Default: no additional instructions
    }
    
    // ========================================================================
    // SHARED METHODS - Common functionality for all webservers
    // ========================================================================
    
    /**
     * Initialize project settings and paths
     * 
     * @return void
     */
    protected function initializeProject(): void
    {
        $this->nonInteractive = in_array('--non-interactive', $this->args) 
            || in_array('-n', $this->args);
        
        if ($this->nonInteractive) {
            $this->info("Running in non-interactive mode - will automatically accept defaults and overwrite files");
        }
        
        $webserverType = ucfirst($this->getWebserverType());
        $this->info("🚀 Initializing GEMVC {$webserverType} project...");
        
        $this->basePath = defined('PROJECT_ROOT') ? PROJECT_ROOT : $this->determineProjectRoot();
        $this->packagePath = $this->determinePackagePath();
        
        // Initialize FileSystemManager with verbose mode disabled
        $this->fileSystem = new FileSystemManager($this->nonInteractive, false);
    }
    
    /**
     * Set package name (called by subclasses)
     * 
     * @param string $packageName
     * @return void
     */
    protected function setPackageName(string $packageName): void
    {
        $this->packageName = $packageName;
    }
    
    /**
     * Setup the basic project structure
     * 
     * @return void
     */
    protected function setupProjectStructure(): void
    {
        $this->info("📁 Setting up project structure...");
        $this->createDirectories();
        $this->copyTemplatesFolder();
        $this->copyReadmeToRoot();
        $this->info("✅ Project structure created");
    }
    
    /**
     * Create all required directories
     * Combines base directories with webserver-specific ones
     * 
     * @return void
     */
    protected function createDirectories(): void
    {
        $baseDirectories = self::BASE_REQUIRED_DIRECTORIES;
        $webserverDirectories = $this->getWebserverSpecificDirectories();
        
        $allDirectories = array_unique(array_merge($baseDirectories, $webserverDirectories));
        
        $directories = array_map(function($dir) {
            return $this->basePath . '/' . $dir;
        }, $allDirectories);
        
        $this->fileSystem->createDirectories($directories);
    }
    
    /**
     * Copy common project files (shared across all webservers)
     * 
     * @return void
     */
    protected function copyCommonProjectFiles(): void
    {
        $this->info("📄 Copying common project files...");
        $startupPath = $this->findStartupPath();
        
        // Copy user files to app directory
        $this->copyUserFiles($startupPath);
        
        $this->info("✅ Common files copied");
    }
    
    /**
     * Find the startup template path
     * 
     * @return string
     * @throws \RuntimeException
     */
    protected function findStartupPath(): string
    {
        $webserverType = strtolower($this->getWebserverType());
        
        // Try webserver-specific startup path first
        $webserverSpecificPaths = [
            $this->packagePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup' . DIRECTORY_SEPARATOR . $webserverType,
            // Also try with package name from property
            dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . $this->packageName . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup' . DIRECTORY_SEPARATOR . $webserverType
        ];
        
        foreach ($webserverSpecificPaths as $path) {
            if (is_dir($path)) {
                $this->info("Found {$webserverType} startup directory: {$path}");
                return $path;
            }
        }
        
        // Fallback to old structure
        $fallbackPaths = [
            $this->packagePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup',
            $this->packagePath . DIRECTORY_SEPARATOR . 'startup',
            dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'startup',
            dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . $this->packageName . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup'
        ];
        
        foreach ($fallbackPaths as $path) {
            if (is_dir($path)) {
                $this->info("Found fallback startup directory: {$path}");
                return $path;
            }
        }
        
        throw new \RuntimeException("Startup directory not found for {$webserverType}. Tried: " . implode(", ", array_merge($webserverSpecificPaths, $fallbackPaths)));
    }
    
    /**
     * Copy common files from common directory
     * 
     * @return void
     */
    protected function copyCommonFiles(): void
    {
        $commonPath = $this->packagePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup' . DIRECTORY_SEPARATOR . 'common';
        
        // Try Composer package path with package name from property
        if (!is_dir($commonPath)) {
            $commonPath = dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . $this->packageName . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup' . DIRECTORY_SEPARATOR . 'common';
        }
        
        if (!is_dir($commonPath)) {
            $this->warning("Common directory not found: {$commonPath}");
            return;
        }
        
        $this->info("Copying common files from: {$commonPath}");
        
        // Copy .env.example
        $envSource = $commonPath . DIRECTORY_SEPARATOR . '.env.example';
        $envDest = $this->basePath . DIRECTORY_SEPARATOR . '.env.example';
        if (file_exists($envSource)) {
            copy($envSource, $envDest);
            $this->info("Copied .env.example");
        }
        
        // Copy phpstan.neon
        $phpstanSource = $commonPath . DIRECTORY_SEPARATOR . 'phpstan.neon';
        $phpstanDest = $this->basePath . DIRECTORY_SEPARATOR . 'phpstan.neon';
        if (file_exists($phpstanSource)) {
            copy($phpstanSource, $phpstanDest);
            $this->info("Copied phpstan.neon");
        }
        
    }
    
    /**
     * Copy user-related files to appropriate directories
     * 
     * @param string $startupPath
     * @return void
     */
    protected function copyUserFiles(string $startupPath): void
    {
        // User files are always in common directory (cross-platform)
        $commonPath = $this->packagePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup' . DIRECTORY_SEPARATOR . 'common';
        
        // Try Composer package path with package name from property
        if (!is_dir($commonPath)) {
            $commonPath = dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . $this->packageName . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup' . DIRECTORY_SEPARATOR . 'common';
        }
        
        $userDir = $commonPath . DIRECTORY_SEPARATOR . 'user';
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
     * Copy README.md to project root
     * 
     * @return void
     */
    protected function copyReadmeToRoot(): void
    {
        $this->fileSystem->copyReadmeToRoot($this->packagePath, $this->basePath);
    }
    
    /**
     * Copy templates folder to project root
     * 
     * @return void
     */
    protected function copyTemplatesFolder(): void
    {
        $this->fileSystem->copyTemplatesFolder($this->packagePath, $this->basePath);
    }
    
    /**
     * Setup PSR-4 autoload configuration in composer.json
     * 
     * @return void
     * @throws \RuntimeException
     */
    protected function setupPsr4Autoload(): void
    {
        $composerJsonPath = $this->basePath . '/composer.json';
        $this->info("⚙️ Configuring PSR-4 autoload...");
        
        // Read existing composer.json
        /** @var array<string, mixed> $composerJson */
        $composerJson = [];
        if (file_exists($composerJsonPath)) {
            $content = file_get_contents($composerJsonPath);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $composerJson = $decoded;
                } else {
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
        // @phpstan-ignore-next-line
        if (!isset($composerJson['autoload']['psr-4'])) {
            // @phpstan-ignore-next-line
            $composerJson['autoload']['psr-4'] = [];
        }
        
        if (!is_array($composerJson['autoload'])) {
            $composerJson['autoload'] = [];
        }
        
        // Add PSR-4 mappings if they don't exist
        foreach (self::BASE_PSR4_AUTOLOAD as $namespace => $path) {
            if (!isset($composerJson['autoload']['psr-4'][$namespace])) {
                $composerJson['autoload']['psr-4'][$namespace] = $path;
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
     * Create environment file from template
     * 
     * @return void
     * @throws \RuntimeException
     */
    protected function createEnvFile(): void
    {
        $this->info("🔧 Creating environment file...");
        $envPath = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        
        // Try webserver-specific example.env first
        $startupPath = $this->findStartupPath();
        $exampleEnvPath = $startupPath . DIRECTORY_SEPARATOR . 'example.env';
        
        // Fallback to main example.env
        if (!file_exists($exampleEnvPath)) {
            $exampleEnvPath = $this->packagePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup' . DIRECTORY_SEPARATOR . 'example.env';
        }
        
        if (!file_exists($exampleEnvPath)) {
            throw new \RuntimeException("Example .env file not found: {$exampleEnvPath}");
        }
        
        $envContent = $this->fileSystem->getFileContent($exampleEnvPath);
        $this->fileSystem->writeFile($envPath, $envContent, '.env file');
        $this->info("✅ Environment file created");
    }
    
    /**
     * Create global command wrapper
     * 
     * @return void
     */
    protected function createGlobalCommand(): void
    {
        $this->info("🔗 Setting up CLI commands...");
        
        $this->createLocalWrapper();
        $this->createWindowsBatch();
        $this->offerGlobalInstallation();
        $this->info("✅ CLI commands ready");
    }
    
    /**
     * Create local wrapper script for Unix/Linux/Mac
     * 
     * @return void
     */
    protected function createLocalWrapper(): void
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
     * 
     * @return void
     */
    protected function createWindowsBatch(): void
    {
        $batPath = $this->basePath . '/bin/gemvc.bat';
        $batContent = <<<EOT
@echo off
php "%~dp0..\vendor\bin\gemvc" %*
EOT;
        
        file_put_contents($batPath, $batContent);
    }
    
    /**
     * Offer global installation based on OS
     * 
     * @return void
     */
    protected function offerGlobalInstallation(): void
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
     * 
     * @return void
     */
    protected function displayWindowsInstructions(): void
    {
        $this->write("\nFor global access on Windows:\n", 'blue');
        $this->write("  1. Add this directory to your PATH: " . realpath($this->basePath . '/bin') . "\n", 'white');
        $this->write("  2. Then you can run 'gemvc' from any location\n\n", 'white');
    }
    
    /**
     * Ask user for global installation
     * 
     * @return void
     */
    protected function askForGlobalInstallation(): void
    {
        echo "Would you like to create a global 'gemvc' command? (y/N): ";
        $handle = fopen("php://stdin", "r");
        if ($handle === false) {
            $this->info("Skipping global installation (stdin error)");
            return;
        }
        $line = fgets($handle);
        fclose($handle);
        
        if ($line === false || strtolower(trim($line)) !== 'y') {
            $this->displayAlternativeUsage();
            return;
        }
        
        $this->attemptGlobalInstallation();
    }
    
    /**
     * Display alternative usage instructions
     * 
     * @return void
     */
    protected function displayAlternativeUsage(): void
    {
        $this->info("Skipped global command setup");
        $this->write("\nYou can still use the command with:\n", 'blue');
        $this->write("  php vendor/bin/gemvc [command]\n", 'white');
        $this->write("  OR\n", 'white');
        $this->write("  php bin/gemvc [command]\n\n", 'white');
    }
    
    /**
     * Attempt to create global symlink
     * 
     * @return void
     */
    protected function attemptGlobalInstallation(): void
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
                    if ($realPath === false) {
                        $this->warning("Could not resolve real path for: {$wrapperPath}");
                        continue;
                    }
                    if (symlink($realPath, $globalBinPath)) {
                        $this->success("Created global command: {$globalBinPath}", false);
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
     * 
     * @param string $wrapperPath
     * @return void
     */
    protected function displayManualInstallationInstructions(string $wrapperPath): void
    {
        $this->warning("Could not create global command. You may need root privileges.");
        $this->write("\nManual setup: \n", 'blue');
        $this->write("  1. Run: sudo ln -s " . realpath($wrapperPath) . " /usr/local/bin/gemvc\n", 'white');
        $this->write("  2. Make it executable: sudo chmod +x /usr/local/bin/gemvc\n\n", 'white');
    }
    
    /**
     * Finalize autoload by running composer dump-autoload
     * 
     * @return void
     */
    protected function finalizeAutoload(): void
    {
        $this->info("🔄 Finalizing autoload...");
        
        $currentDir = getcwd();
        if ($currentDir === false) {
            $this->warning("Could not get current directory, skipping composer dump-autoload");
            return;
        }
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
     * Offer Docker services installation
     * 
     * @return void
     */
    protected function offerDockerServices(): void
    {
        $dockerInit = new DockerComposeInit($this->basePath, $this->nonInteractive);
        $dockerInit->offerDockerServices();
    }
    
    /**
     * Display next steps to user
     * Combines common steps with webserver-specific instructions
     * 
     * @return void
     */
    protected function displayNextSteps(): void
    {
        $boxShow = new CliBoxShow();
        
        $webserverType = ucfirst($this->getWebserverType());
        
        $lines = [
            "\033[1;92m✅ {$webserverType} Project Ready!\033[0m",
            " \033[1;36m$ \033[1;95mphp bin/gemvc\033[0m",
            "   \033[90m# PSR-4 autoload configured and ready to use\033[0m",
            "",
            "\033[1;94mOptional - Development Environment:\033[0m",
            " \033[1;36m$ \033[1;95mcomposer update\033[0m",
            "   \033[90m# Only if you want to install additional dev dependencies\033[0m"
        ];
        
        $boxShow->displayBox("Next Steps", $lines);
        
        // Display webserver-specific instructions
        $this->displayWebserverSpecificInstructions();
    }
    
    /**
     * Display webserver-specific instructions (Template Method)
     * This method can be overridden by subclasses for completely custom instructions,
     * or subclasses can use getStartCommand() and getAdditionalInstructions() for simpler cases
     * 
     * @return void
     */
    protected function displayWebserverSpecificInstructions(): void
    {
        $boxShow = new CliBoxShow();
        $webserverType = $this->getWebserverType();
        $port = $this->getDefaultPort();
        $startCommand = $this->getStartCommand();
        
        $lines = [
            "\033[1;94m🚀 {$webserverType} Specific Instructions:\033[0m",
            "",
            "\033[1;36mStart {$webserverType} Server:\033[0m",
            " \033[1;36m$ \033[1;95m{$startCommand}\033[0m",
            "   \033[90m# Starts {$webserverType} server on http://localhost:{$port}\033[0m",
            "",
            "\033[1;36mWith Docker:\033[0m",
            " \033[1;36m$ \033[1;95mdocker-compose up -d --build\033[0m",
            "   \033[90m# Builds and runs {$webserverType} in container\033[0m",
        ];
        
        // Add webserver-specific additional instructions
        $additionalInstructions = $this->getAdditionalInstructions();
        if (!empty($additionalInstructions)) {
            $lines[] = "";
            $lines = array_merge($lines, $additionalInstructions);
        }
        
        // Add common instructions
        $lines = array_merge($lines, [
            "",
            "\033[1;94m📚 Server Configuration:\033[0m",
            " • Server runs on port \033[1;36m{$port}\033[0m by default",
            " • Configure in \033[1;36m.env\033[0m file",
            "",
            "\033[1;94m🔧 Useful Commands:\033[0m",
            " • Check server status: \033[1;95mcurl http://localhost:{$port}\033[0m",
            " • Stop server: \033[1;95mCtrl+C\033[0m"
        ]);
        
        $boxShow->displayBox("{$webserverType} Instructions", $lines);
    }
    
    /**
     * Offer optional tools installation (PHPStan, PHPUnit, Pest)
     * 
     * @return void
     */
    protected function offerOptionalTools(): void
    {
        $toolsInstaller = new OptionalToolsInstaller($this->basePath, $this->packagePath, $this->nonInteractive);
        $toolsInstaller->offerOptionalTools();
    }
    
    /**
     * Display success graphic
     * 
     * @return void
     */
    protected function displaySuccessGraphic(): void
    {
        $webserverType = strtoupper($this->getWebserverType());
        $port = $this->getDefaultPort();
        
        $this->write("\n", 'white');
        $this->write("    ╔══════════════════════════════════════════════════════════════╗\n", 'green');
        $this->write("    ║                    🎯 SUCCESS! 🎯                            ║\n", 'green');
        $this->write("    ║         GEMVC {$webserverType} Project Ready!                ║\n", 'green');
        $this->write("    ║                                                              ║\n", 'green');
        $this->write("    ║         Run: \033[1;36mdocker-compose up -d --build\033[0m\033[32m              ║\n", 'green');
        $this->write("    ║         Server: \033[1;36mhttp://localhost:{$port}\033[0m\033[32m                   ║\n", 'green');
        $this->write("    ╚══════════════════════════════════════════════════════════════╝\n", 'green');
        $this->write("\n", 'white');
    }
    
    /**
     * Determine project root directory
     * 
     * @return string
     */
    protected function determineProjectRoot(): string
    {
        $vendorDir = dirname(dirname(dirname(dirname(__DIR__))));
        
        if (basename($vendorDir) === 'vendor') {
            return dirname($vendorDir);
        }
        
        return getcwd() ?: '.';
    }
    
    
    /**
     * Determine package path
     * 
     * @return string
     */
    protected function determinePackagePath(): string
    {
        // First, try to detect if we're running from a Composer package installation
        $composerPath = dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . $this->packageName;
        
        if (is_dir($composerPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup')) {
            $this->info("Using Composer package path: {$composerPath}");
            return $composerPath;
        }
        
        // Fallback to development paths
        $devPaths = [
            dirname(dirname(dirname(__DIR__))),
            dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . 'library',
            dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . 'framework',
            dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'gemvc' . DIRECTORY_SEPARATOR . $this->packageName
        ];
        
        foreach ($devPaths as $path) {
            if (file_exists($path . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'startup')) {
                $this->info("Using development package path: {$path}");
                return $path;
            }
        }
        
        $currentDir = dirname(dirname(dirname(__FILE__)));
        $this->warning("Using fallback package path: {$currentDir}");
        return $currentDir;
    }
    
    /**
     * Get the destination path for a file, handling special mappings
     * 
     * @param string $fileName
     * @return string
     */
    protected function getDestinationPath(string $fileName): string
    {
        $specialMappings = $this->getWebserverSpecificFileMappings();
        
        if (isset($specialMappings[$fileName])) {
            $destPath = $this->basePath . '/' . $specialMappings[$fileName];
            
            // Ensure the target directory exists
            $targetDir = dirname($destPath);
            $this->fileSystem->ensureDirectoryExists($targetDir);
            
            return $destPath;
        }
        
        return $this->basePath . '/' . $fileName;
    }
    
    /**
     * Copy template files from startup directory
     * 
     * @param string $templateDir
     * @return void
     * @throws \RuntimeException
     */
    protected function copyTemplateFiles(string $templateDir): void
    {
        if (!is_dir($templateDir)) {
            throw new \RuntimeException("Template directory not found: {$templateDir}");
        }
        
        $webserverType = ucfirst($this->getWebserverType());
        $this->info("Using {$webserverType} startup template");
        
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
     * Copy a directory if it exists
     * Helper method to avoid code duplication when copying webserver-specific directories
     * 
     * @param string $sourcePath Source directory path
     * @param string $targetPath Target directory path
     * @param string $dirName Friendly name for logging (e.g., 'Server handlers', 'Public files')
     * @return void
     */
    protected function copyDirectoryIfExists(
        string $sourcePath,
        string $targetPath,
        string $dirName = 'Directory'
    ): void
    {
        if (!is_dir($sourcePath)) {
            $this->warning("{$dirName} directory not found: {$sourcePath}");
            return;
        }
        
        // Ensure target directory exists
        $this->fileSystem->createDirectoryIfNotExists($targetPath);
        
        // Copy all contents
        $this->fileSystem->copyDirectoryContents($sourcePath, $targetPath);
        
        $this->info("Copied {$dirName} to: {$targetPath}");
    }
}

