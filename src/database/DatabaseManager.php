<?php

namespace Gemvc\Database;

use Psr\Container\ContainerInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\Config\Config;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\DbConnection\Connection;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\EventDispatcher;
use Hyperf\Event\ListenerProvider;
use Gemvc\Helper\ProjectHelper;

/**
 * Manages the database connection pool for the Gemvc framework.
 * This class is self-contained and uses a singleton pattern for OpenSwoole.
 * It automatically loads configuration from .env and manages the connection pool.
 */
class DatabaseManager
{
    /**
     * @var DatabaseManager|null Singleton instance for OpenSwoole
     */
    private static ?DatabaseManager $instance = null;

    /**
     * @var Container The DI container from Hyperf.
     */
    protected Container $container;

    /**
     * @var PoolFactory The factory for creating and managing connection pools.
     */
    protected PoolFactory $poolFactory;

    /**
     * @var string|null Stores the last error message
     */
    private ?string $error = null;

    /**
     * Private constructor to prevent direct instantiation.
     * Use getInstance() to get the singleton instance.
     */
    private function __construct()
    {
        // Debug: Log when pool is actually created (should only happen once per worker)
        if (($_ENV['APP_ENV'] ?? '') === 'dev') {
            error_log("DatabaseManager: Creating new connection pool [Worker PID: " . getmypid() . "]");
        }
        
        // Use the helper to load environment variables.
        ProjectHelper::loadEnv();

        // Get the configuration directly from the private method inside this class.
        $dbConfig = $this->getDatabaseConfig();

        // Initialize the Hyperf Dependency Injection container.
        $this->container = new Container(new DefinitionSource([]));

        // Bind the database configuration array to the ConfigInterface contract within the container.
        $this->container->set(\Hyperf\Contract\ConfigInterface::class, new Config(['databases' => $dbConfig]));
        
        // Bind the container instance to the Psr\Container\ContainerInterface contract.
        $this->container->set(ContainerInterface::class, $this->container);
        
        // Bind the StdoutLoggerInterface required by Hyperf's database connection pool
        // Use a simple logger implementation that doesn't require Symfony Console
        $this->container->set(StdoutLoggerInterface::class, new class implements StdoutLoggerInterface {
            public function emergency($message, array $context = []): void { error_log("[EMERGENCY] $message"); }
            public function alert($message, array $context = []): void { error_log("[ALERT] $message"); }
            public function critical($message, array $context = []): void { error_log("[CRITICAL] $message"); }
            public function error($message, array $context = []): void { error_log("[ERROR] $message"); }
            public function warning($message, array $context = []): void { error_log("[WARNING] $message"); }
            public function notice($message, array $context = []): void { error_log("[NOTICE] $message"); }
            public function info($message, array $context = []): void { error_log("[INFO] $message"); }
            public function debug($message, array $context = []): void { error_log("[DEBUG] $message"); }
            public function log($level, $message, array $context = []): void { error_log("[$level] $message"); }
        });
        
        // Bind event dispatcher dependencies required by Hyperf's database connection pool
        $listenerProvider = new ListenerProvider();
        $this->container->set(\Psr\EventDispatcher\ListenerProviderInterface::class, $listenerProvider);
        
        // Create event dispatcher instance properly
        $eventDispatcher = new EventDispatcher($listenerProvider, $this->container->get(StdoutLoggerInterface::class));
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);

        // Create the PoolFactory, which will use the container to get the configuration.
        $this->poolFactory = new PoolFactory($this->container);
    }

    /**
     * Get the singleton instance of DatabaseManager.
     * This ensures only one pool exists per OpenSwoole worker process.
     * 
     * @return DatabaseManager The singleton instance
     */
    public static function getInstance(): DatabaseManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        } else {
            // Debug: Confirm singleton is being reused
            if (($_ENV['APP_ENV'] ?? '') === 'dev') {
                error_log("DatabaseManager: Reusing existing pool [Worker PID: " . getmypid() . "]");
            }
        }
        return self::$instance;
    }

    /**
     * Reset the singleton instance (useful for testing).
     * In production, this should never be called.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Get the last error message.
     * 
     * @return string|null Error message or null if no error occurred
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Set an error message with optional context.
     * 
     * @param string|null $error The error message to set
     * @param array $context Additional context information
     */
    public function setError(?string $error, array $context = []): void
    {
        if ($error === null) {
            $this->error = null;
            return;
        }
        
        // Add context information to error message
        if (!empty($context)) {
            $contextStr = ' [Context: ' . json_encode($context) . ']';
            $this->error = $error . $contextStr;
        } else {
            $this->error = $error;
        }
    }

    /**
     * Clear the error message.
     */
    public function clearError(): void
    {
        $this->error = null;
    }

    /**
     * Retrieves a database connection from the specified connection pool.
     *
     * @param string $poolName The name of the connection pool (default: 'default')
     * @return Connection An active database connection object.
     */
    public function getConnection(string $poolName = 'default'): ?Connection
    {
        $this->clearError();
        
        try {
            /** @var Connection $conn */
            $conn = $this->poolFactory->getPool($poolName)->get();
            
            // Verify connection is alive with a lightweight ping
            try {
                $conn->getPdo()->query('SELECT 1');
            } catch (\Throwable $e) {
                // Connection is broken, release it and get a new one
                error_log("Broken connection detected: " . $e->getMessage());
                try {
                    $conn->release();
                } catch (\Throwable $releaseError) {
                    error_log("Error releasing broken connection: " . $releaseError->getMessage());
                }
                // Return null instead of recursing to prevent infinite loop
                return null;
            }
            
            return $conn;
        } catch (\Throwable $e) {
            $context = [
                'pool' => $poolName,
                'worker_pid' => getmypid(),
                'timestamp' => date('Y-m-d H:i:s'),
                'error_code' => $e->getCode()
            ];
            $this->setError('Failed to get database connection: ' . $e->getMessage(), $context);
            error_log("DatabaseManager::getConnection() - Error: " . $e->getMessage() . " [Pool: $poolName]");
            return null;
        }
    }

    /**
     * Builds the database configuration array by reading environment variables.
     * This makes the class independent of external config files.
     *
     * @return array The database configuration array.
     */
    private function getDatabaseConfig(): array
    {
        /**
         * Determines the correct database host based on the execution context (CLI vs Server).
         * @return string The database host.
         */
        $getDbHost = function (): string {
            // Check if we're running in OpenSwoole server context
            // OpenSwoole runs in CLI mode but we need to detect if it's the web server
            if (PHP_SAPI === 'cli' && (defined('SWOOLE_BASE') || class_exists('\OpenSwoole\Server'))) {
                // Running in OpenSwoole server - use container host
                return $_ENV['DB_HOST'] ?? 'db';
            }
            
            // True CLI context - use localhost
            if (PHP_SAPI === 'cli') {
                return $_ENV['DB_HOST_CLI_DEV'] ?? 'localhost';
            }
            
            // In any other context (like web server), use the container host.
            return $_ENV['DB_HOST'] ?? 'db';
        };

        return [
            'default' => [
                'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
                'host' => $getDbHost(),
                'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
                'database' => $_ENV['DB_NAME'] ?? 'gemvc_db',
                'username' => $_ENV['DB_USER'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
                'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
                'collation' =>  $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
                'pool' => [
                    'min_connections' => (int) ($_ENV['MIN_DB_CONNECTION_POOL'] ?? 1),
                    'max_connections' => (int) ($_ENV['MAX_DB_CONNECTION_POOL'] ?? 10),
                    'connect_timeout' => (float) ($_ENV['DB_CONNECTION_TIME_OUT'] ?? 10.0),
                    'wait_timeout' => (float) ($_ENV['DB_CONNECTION_EXPIER_TIME'] ?? 3.0),
                    'heartbeat' => -1,
                    'max_idle_time' => (float) ($_ENV['DB_CONNECTION_MAX_AGE'] ?? 60.0),
                ],
            ],
        ];
    }
}

