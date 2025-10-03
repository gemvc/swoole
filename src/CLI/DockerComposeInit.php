<?php

namespace Gemvc\CLI;

use Gemvc\CLI\Command;

/**
 * Docker Compose Initialization Manager
 * 
 * Handles creation and management of docker-compose.yml files with optional services.
 * Allows users to choose which services to include in their development environment.
 */
class DockerComposeInit extends Command
{
    private string $basePath;
    private bool $nonInteractive = false;
    private array $selectedServices = [];
    
    // Available services configuration
    private const AVAILABLE_SERVICES = [
        'redis' => [
            'name' => 'Redis',
            'description' => 'Redis cache and session storage',
            'default' => true,
            'image' => 'redis:latest',
            'ports' => ['6379:6379'],
            'volumes' => ['redis-data:/data'],
            'environment' => []
        ],
        'phpmyadmin' => [
            'name' => 'phpMyAdmin',
            'description' => 'Web-based MySQL administration tool',
            'default' => true,
            'image' => 'phpmyadmin/phpmyadmin',
            'ports' => ['8080:80'],
            'environment' => [
                'PMA_HOST' => 'db',
                'PMA_PORT' => '3306',
                'MYSQL_ROOT_PASSWORD' => 'rootpassword'
            ],
            'depends_on' => ['db']
        ],
        'db' => [
            'name' => 'MySQL Database',
            'description' => 'MySQL 8.0 database server',
            'default' => true,
            'image' => 'mysql:8.0',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql-data:/var/lib/mysql'],
            'environment' => [
                'MYSQL_ROOT_PASSWORD' => 'rootpassword'
            ],
            'command' => [
                '--character-set-server=utf8mb4',
                '--collation-server=utf8mb4_unicode_ci',
                '--default-authentication-plugin=mysql_native_password'
            ]
        ]
    ];
    
    public function __construct(string $basePath, bool $nonInteractive = false)
    {
        $this->basePath = $basePath;
        $this->nonInteractive = $nonInteractive;
    }
    
    /**
     * Required by Command abstract class
     */
    public function execute()
    {
        throw new \RuntimeException("DockerComposeInit should not be executed directly. Use offerDockerServices() method instead.");
    }
    
    /**
     * Offer Docker services installation
     */
    public function offerDockerServices(): void
    {
        if ($this->nonInteractive) {
            $this->info("Skipped Docker services installation (non-interactive mode)");
            return;
        }
        
        $this->displayDockerServicesPrompt();
        $this->getUserServiceSelection();
        $this->createDockerComposeFile();
    }
    
    /**
     * Display Docker services installation prompt
     */
    private function displayDockerServicesPrompt(): void
    {
        $boxShow = new \Gemvc\CLI\Commands\CliBoxShow();
        
        $lines = [
            "Would you like to set up Docker services for development?",
            "This will create a docker-compose.yml with optional services:",
            "",
            "\033[1;94mAvailable Services:\033[0m"
        ];
        
        foreach (self::AVAILABLE_SERVICES as $key => $service) {
            $default = $service['default'] ? ' \033[32m(default)\033[0m' : '';
            $lines[] = "  \033[1;36m{$service['name']}\033[0m - {$service['description']}{$default}";
        }
        
        $lines[] = "";
        $lines[] = "\033[1;94mThis will create:\033[0m";
        $lines[] = "  • \033[1;36mdocker-compose.yml\033[0m - Docker services configuration";
        $lines[] = "  • \033[1;36mDockerfile\033[0m - OpenSwoole container configuration";
        $lines[] = "  • \033[1;36mDevelopment environment\033[0m - Ready to use with docker-compose up";
        
        $boxShow->displayBox("Docker Services Setup", $lines);
    }
    
    /**
     * Get user service selection
     */
    private function getUserServiceSelection(): void
    {
        echo "\n\033[1;36mSet up Docker services? (y/N):\033[0m ";
        $handle = fopen("php://stdin", "r");
        $choice = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($choice) !== 'y') {
            $this->info("Docker services setup skipped");
            return;
        }
        
        $this->selectServices();
    }
    
    /**
     * Let user select which services to include
     */
    private function selectServices(): void
    {
        $this->info("Select services to include (press Enter for defaults):");
        
        foreach (self::AVAILABLE_SERVICES as $key => $service) {
            $default = $service['default'] ? ' [Y/n]' : ' [y/N]';
            echo "  \033[1;36m{$service['name']}\033[0m - {$service['description']}{$default}: ";
            
            $handle = fopen("php://stdin", "r");
            $choice = trim(fgets($handle));
            fclose($handle);
            
            $include = $service['default'] ? 
                (empty($choice) || strtolower($choice) === 'y') :
                (strtolower($choice) === 'y');
                
            if ($include) {
                $this->selectedServices[] = $key;
            }
        }
        
        if (empty($this->selectedServices)) {
            $this->info("No services selected. Docker services setup skipped.");
            return;
        }
        
        $this->info("Selected services: " . implode(', ', array_map(function($key) {
            return self::AVAILABLE_SERVICES[$key]['name'];
        }, $this->selectedServices)));
    }
    
    /**
     * Create docker-compose.yml file
     */
    private function createDockerComposeFile(): void
    {
        if (empty($this->selectedServices)) {
            return;
        }
        
        $composeContent = $this->generateDockerComposeContent();
        $composePath = $this->basePath . '/docker-compose.yml';
        
        if (file_put_contents($composePath, $composeContent)) {
            $this->info("Created docker-compose.yml with selected services");
            $this->displayDockerInstructions();
        } else {
            $this->warning("Failed to create docker-compose.yml file");
        }
    }
    
    /**
     * Generate Docker Compose content
     */
    private function generateDockerComposeContent(): string
    {
        $content = "version: '3'\n\nservices:\n";
        
        // Add OpenSwoole service
        $content .= $this->generateOpenSwooleService();
        
        // Add selected services
        foreach ($this->selectedServices as $serviceKey) {
            $content .= $this->generateServiceContent($serviceKey);
        }
        
        // Add volumes
        $content .= $this->generateVolumesContent();
        
        // Add networks
        $content .= $this->generateNetworksContent();
        
        return $content;
    }
    
    /**
     * Generate OpenSwoole service configuration
     */
    private function generateOpenSwooleService(): string
    {
        $dependsOn = [];
        if (in_array('db', $this->selectedServices)) {
            $dependsOn[] = 'db';
        }
        if (in_array('redis', $this->selectedServices)) {
            $dependsOn[] = 'redis';
        }
        
        $dependsOnStr = empty($dependsOn) ? '' : "\n    depends_on:\n" . 
            implode("\n", array_map(function($dep) {
                return "      - {$dep}";
            }, $dependsOn));
        
        $environment = [];
        if (in_array('redis', $this->selectedServices)) {
            $environment = array_merge($environment, [
                'REDIS_HOST' => '"redis"',
                'REDIS_PORT' => '"6379"',
                'REDIS_PASSWORD' => '"rootpassword"',
                'REDIS_DATABASE' => '"0"',
                'REDIS_PREFIX' => '"gemvc:"',
                'REDIS_PERSISTENT' => '"true"',
                'REDIS_TIMEOUT' => '"0.0"',
                'REDIS_READ_TIMEOUT' => '"0.0"'
            ]);
        }
        
        $envStr = empty($environment) ? '' : "\n    environment:\n" . 
            implode("\n", array_map(function($key, $value) {
                return "      {$key}: {$value}";
            }, array_keys($environment), $environment));
        
        return <<<EOT
  openswoole:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "9501:9501"
    volumes:
      - ./:/var/www/html:delegated
    restart: unless-stopped
    networks:
      - backend-network{$dependsOnStr}{$envStr}

EOT;
    }
    
    /**
     * Generate service content based on service key
     */
    private function generateServiceContent(string $serviceKey): string
    {
        $service = self::AVAILABLE_SERVICES[$serviceKey];
        $content = "\n  {$serviceKey}:\n";
        $content .= "    image: {$service['image']}\n";
        
        if (isset($service['ports'])) {
            $content .= "    ports:\n";
            foreach ($service['ports'] as $port) {
                $content .= "      - \"{$port}\"\n";
            }
        }
        
        if (isset($service['volumes'])) {
            $content .= "    volumes:\n";
            foreach ($service['volumes'] as $volume) {
                $content .= "      - {$volume}\n";
            }
        }
        
        if (isset($service['environment'])) {
            $content .= "    environment:\n";
            foreach ($service['environment'] as $key => $value) {
                $content .= "      {$key}: \"{$value}\"\n";
            }
        }
        
        if (isset($service['command'])) {
            $content .= "    command:\n";
            foreach ($service['command'] as $cmd) {
                $content .= "      - {$cmd}\n";
            }
        }
        
        if (isset($service['depends_on'])) {
            $content .= "    depends_on:\n";
            foreach ($service['depends_on'] as $dep) {
                $content .= "      - {$dep}\n";
            }
        }
        
        $content .= "    networks:\n";
        $content .= "      - backend-network\n";
        
        return $content;
    }
    
    /**
     * Generate volumes section
     */
    private function generateVolumesContent(): string
    {
        $volumes = [];
        
        if (in_array('db', $this->selectedServices)) {
            $volumes[] = 'mysql-data';
        }
        if (in_array('redis', $this->selectedServices)) {
            $volumes[] = 'redis-data';
        }
        
        if (empty($volumes)) {
            return "";
        }
        
        $content = "\nvolumes:\n";
        foreach ($volumes as $volume) {
            $content .= "  {$volume}:\n";
            $content .= "    driver: local\n";
        }
        
        return $content;
    }
    
    /**
     * Generate networks section
     */
    private function generateNetworksContent(): string
    {
        return <<<EOT

networks:
  backend-network:
    driver: bridge
EOT;
    }
    
    /**
     * Display Docker usage instructions
     */
    private function displayDockerInstructions(): void
    {
        $boxShow = new \Gemvc\CLI\Commands\CliBoxShow();
        
        $lines = [
            "\033[1;92m✅ Docker Services Ready!\033[0m",
            "",
            "\033[1;94mTo start your development environment:\033[0m",
            " \033[1;36m$ \033[1;95mdocker-compose up -d\033[0m",
            "",
            "\033[1;94mTo stop the services:\033[0m",
            " \033[1;36m$ \033[1;95mdocker-compose down\033[0m",
            "",
            "\033[1;94mTo view logs:\033[0m",
            " \033[1;36m$ \033[1;95mdocker-compose logs -f\033[0m",
            "",
            "\033[1;94mService URLs:\033[0m",
            " • \033[1;36mOpenSwoole\033[0m: http://localhost:9501"
        ];
        
        if (in_array('phpmyadmin', $this->selectedServices)) {
            $lines[] = " • \033[1;36mphpMyAdmin\033[0m: http://localhost:8080";
        }
        if (in_array('db', $this->selectedServices)) {
            $lines[] = " • \033[1;36mMySQL\033[0m: localhost:3306 (root/rootpassword)";
        }
        if (in_array('redis', $this->selectedServices)) {
            $lines[] = " • \033[1;36mRedis\033[0m: localhost:6379";
        }
        
        $boxShow->displayBox("Docker Services", $lines);
    }
    
    /**
     * Get selected services
     */
    public function getSelectedServices(): array
    {
        return $this->selectedServices;
    }
    
    /**
     * Set selected services (for non-interactive mode)
     */
    public function setSelectedServices(array $services): void
    {
        $this->selectedServices = array_intersect($services, array_keys(self::AVAILABLE_SERVICES));
    }
}
