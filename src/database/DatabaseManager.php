<?php

namespace Gemvc\Database;

use Psr\Container\ContainerInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\Config\Config;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\DbConnection\Connection;
use Gemvc\Helper\ProjectHelper;

/**
 * Manages the database connection pool for the Gemvc framework.
 * This class is self-contained. It uses ProjectHelper to automatically
 * load its configuration from .env and then builds the config array internally.
 */
class DatabaseManager
{
    /**
     * @var Container The DI container from Hyperf.
     */
    protected Container $container;

    /**
     * @var PoolFactory The factory for creating and managing connection pools.
     */
    protected PoolFactory $poolFactory;

    /**
     * The constructor initializes the container and the pool factory.
     * It's designed to be instantiated once when the server starts.
     * It automatically finds and loads the necessary configuration.
     */
    public function __construct()
    {
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

        // Create the PoolFactory, which will use the container to get the configuration.
        $this->poolFactory = new PoolFactory($this->container);
    }

    /**
     * Retrieves a database connection from the specified connection pool.
     *
     * @param string $poolName The name of the connection pool (default: 'default')
     * @return Connection An active database connection object.
     */
    public function getConnection(string $poolName = 'default'): Connection
    {
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
            // Recursively retry to get a healthy connection
            return $this->getConnection($poolName);
        }
        
        return $conn;
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
            // PHP_SAPI is 'cli' when running from the command line.
            if (PHP_SAPI === 'cli') {
                return $_ENV['DB_HOST_CLI_DEV'] ?? 'localhost';
            }
            
            // In any other context (like OpenSwoole server), use the container host.
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

