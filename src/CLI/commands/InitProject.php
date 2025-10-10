<?php

namespace Gemvc\CLI\Commands;

use Gemvc\CLI\Command;
use Gemvc\CLI\Commands\InitSwoole;
use Gemvc\CLI\Commands\CliBoxShow;

/**
 * Initialize a new GEMVC project with webserver selection
 * 
 * This is the main initialization orchestrator that allows users to choose
 * between different webserver configurations:
 * - OpenSwoole (High-performance async server)
 * - Apache (Traditional PHP hosting)
 * - Nginx (Modern web server with PHP-FPM)
 * 
 * The orchestrator delegates the actual initialization to the appropriate
 * webserver-specific Init class (InitSwoole, InitApache, or InitNginx).
 * 
 * @package Gemvc\CLI\Commands
 */
class InitProject extends Command
{
    /**
     * Available webserver options
     */
    private const WEBSERVER_OPTIONS = [
        '1' => [
            'name' => 'OpenSwoole',
            'class' => InitSwoole::class,
            'description' => 'High-performance async server with WebSocket support',
            'status' => 'available',
            'icon' => '🚀'
        ],
        '2' => [
            'name' => 'Apache',
            'class' => 'Gemvc\CLI\Commands\InitApache',
            'description' => 'Traditional PHP hosting with mod_php or PHP-FPM',
            'status' => 'coming_soon',
            'icon' => '🔶'
        ],
        '3' => [
            'name' => 'Nginx',
            'class' => 'Gemvc\CLI\Commands\InitNginx',
            'description' => 'Modern web server with PHP-FPM',
            'status' => 'coming_soon',
            'icon' => '🔷'
        ]
    ];
    
    /**
     * Execute the initialization orchestrator
     * 
     * @return bool
     */
    public function execute(): bool
    {
        // Display welcome banner
        $this->displayWelcomeBanner();
        
        // Check for non-interactive mode with webserver flag
        $webserverChoice = $this->getNonInteractiveChoice();
        
        if ($webserverChoice === null) {
            // Interactive mode: show menu and get user choice
            $webserverChoice = $this->displayWebserverMenu();
        }
        
        // Validate choice
        if (!isset(self::WEBSERVER_OPTIONS[$webserverChoice])) {
            $this->error("Invalid webserver choice: {$webserverChoice}");
            return false;
        }
        
        $webserver = self::WEBSERVER_OPTIONS[$webserverChoice];
        
        // Check if webserver is available
        if ($webserver['status'] === 'coming_soon') {
            $this->warning("{$webserver['name']} support is coming soon!");
            $this->info("For now, please use OpenSwoole (option 1)");
            return false;
        }
        
        // Execute the selected webserver initialization
        return $this->executeWebserverInit($webserver);
    }
    
    /**
     * Display welcome banner
     * 
     * @return void
     */
    private function displayWelcomeBanner(): void
    {
        $boxShow = new CliBoxShow();
        
        $lines = [
            "\033[1;96m╔═══════════════════════════════════════════════════════╗\033[0m",
            "\033[1;96m║\033[0m           \033[1;92mWelcome to GEMVC Framework\033[0m              \033[1;96m║\033[0m",
            "\033[1;96m╚═══════════════════════════════════════════════════════╝\033[0m",
            "",
            "\033[1;94mGEMVC\033[0m is a high-performance PHP framework built for",
            "modern web applications with first-class support for",
            "OpenSwoole, Apache, and Nginx.",
            "",
            "\033[1;93m⚡ Features:\033[0m",
            "  • PSR-4 Autoloading",
            "  • Database Migrations & ORM",
            "  • JWT Authentication",
            "  • RESTful API Support",
            "  • OpenAPI Documentation",
            "  • Docker Ready",
            "",
            "\033[1;36mLet's set up your project!\033[0m"
        ];
        
        foreach ($lines as $line) {
            $this->write($line . "\n", 'white');
        }
        
        $this->write("\n");
    }
    
    /**
     * Get webserver choice from non-interactive flags
     * 
     * @return string|null Choice number or null if not in non-interactive mode
     */
    private function getNonInteractiveChoice(): ?string
    {
        // Check for --swoole flag
        if (in_array('--swoole', $this->args)) {
            $this->info("Using OpenSwoole (from --swoole flag)");
            return '1';
        }
        
        // Check for --apache flag
        if (in_array('--apache', $this->args)) {
            $this->info("Using Apache (from --apache flag)");
            return '2';
        }
        
        // Check for --nginx flag
        if (in_array('--nginx', $this->args)) {
            $this->info("Using Nginx (from --nginx flag)");
            return '3';
        }
        
        // Check for --server=<name> flag
        foreach ($this->args as $arg) {
            if (strpos($arg, '--server=') === 0) {
                $serverName = substr($arg, 9);
                switch (strtolower($serverName)) {
                    case 'swoole':
                    case 'openswoole':
                        $this->info("Using OpenSwoole (from --server flag)");
                        return '1';
                    case 'apache':
                        $this->info("Using Apache (from --server flag)");
                        return '2';
                    case 'nginx':
                        $this->info("Using Nginx (from --server flag)");
                        return '3';
                }
            }
        }
        
        return null;
    }
    
    /**
     * Display webserver selection menu and get user choice
     * 
     * @return string User's choice (1, 2, or 3)
     */
    private function displayWebserverMenu(): string
    {
        $this->write("\n\033[1;94m┌─────────────────────────────────────────────────────────────┐\033[0m\n", 'white');
        $this->write("\033[1;94m│\033[0m              \033[1;96mSelect Your Webserver\033[0m                      \033[1;94m│\033[0m\n", 'white');
        $this->write("\033[1;94m└─────────────────────────────────────────────────────────────┘\033[0m\n\n", 'white');
        
        foreach (self::WEBSERVER_OPTIONS as $key => $option) {
            $statusBadge = $option['status'] === 'available' 
                ? "\033[1;32m[AVAILABLE]\033[0m" 
                : "\033[1;33m[COMING SOON]\033[0m";
            
            $this->write("  {$option['icon']} \033[1;36m[{$key}]\033[0m \033[1;97m{$option['name']}\033[0m {$statusBadge}\n", 'white');
            $this->write("      \033[90m{$option['description']}\033[0m\n\n", 'white');
        }
        
        // Get user input
        while (true) {
            $this->write("\033[1;36mEnter your choice (1-3) [1]:\033[0m ", 'white');
            
            $handle = fopen("php://stdin", "r");
            if ($handle === false) {
                $this->error("Failed to open stdin");
                return '1'; // Default to Swoole
            }
            
            $line = fgets($handle);
            fclose($handle);
            
            $choice = $line !== false ? trim($line) : '';
            
            // Default to OpenSwoole if empty
            if ($choice === '') {
                $choice = '1';
            }
            
            // Validate choice
            if (isset(self::WEBSERVER_OPTIONS[$choice])) {
                $selected = self::WEBSERVER_OPTIONS[$choice];
                $this->info("Selected: {$selected['icon']} {$selected['name']}");
                return $choice;
            }
            
            $this->warning("Invalid choice. Please enter 1, 2, or 3.");
        }
    }
    
    /**
     * Execute the selected webserver initialization
     * 
     * @param array<string, mixed> $webserver Webserver configuration
     * @return bool
     */
    private function executeWebserverInit(array $webserver): bool
    {
        $this->write("\n", 'white');
        $this->info("Initializing {$webserver['name']} project...");
        $this->write("\n", 'white');
        
        // Get the class name
        $className = $webserver['class'];
        
        // Check if class exists
        if (!class_exists($className)) {
            $this->error("Initialization class not found: {$className}");
            $this->info("This webserver option may not be fully implemented yet.");
            return false;
        }
        
        // Create instance and execute
        try {
            // Pass through all args and options to the webserver init
            $initCommand = new $className($this->args, $this->options);
            return $initCommand->execute();
        } catch (\Exception $e) {
            $this->error("Failed to initialize project: " . $e->getMessage());
            return false;
        }
    }
}
