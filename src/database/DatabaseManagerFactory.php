<?php

namespace Gemvc\Database;

use PDO;

/**
 * Database Manager Factory with Performance Optimization
 * 
 * This factory automatically chooses the appropriate database manager
 * implementation based on the current web server environment:
 * 
 * - OpenSwoole: Uses SwooleDatabaseManager (with connection pooling)
 * - Apache/Nginx PHP-FPM: Uses PDO-based manager (configurable)
 *   - Simple: SimplePdoDatabaseManager (default, no persistence)
 *   - Enhanced: EnhancedPdoDatabaseManager (if DB_ENHANCED_CONNECTION=1)
 * 
 * Environment Variable Control:
 * - DB_ENHANCED_CONNECTION=1: Use EnhancedPdoDatabaseManager with persistent connections
 * - DB_ENHANCED_CONNECTION=0 (default): Use SimplePdoDatabaseManager with simple connections
 * 
 * Performance optimizations:
 * - Environment detection cached after first run
 * - Singleton pattern prevents repeated detection
 * - Fast-path checks prioritized
 * - Minimal function calls
 * 
 * The factory detects the environment and returns the appropriate implementation.
 */
class DatabaseManagerFactory
{
    /** @var DatabaseManagerInterface|null Singleton instance */
    private static ?DatabaseManagerInterface $instance = null;

    /** @var string|null Cached environment detection result */
    private static ?string $cachedEnvironment = null;

    /** @var bool Whether detection has been performed */
    private static bool $detectionPerformed = false;

    /**
     * Get the appropriate database manager for the current environment
     * 
     * @return DatabaseManagerInterface The database manager instance
     */
    public static function getManager(): DatabaseManagerInterface
    {
        if (self::$instance === null) {
            self::$instance = self::createManager();
        }
        return self::$instance;
    }

    /**
     * Create the appropriate database manager based on cached environment
     * 
     * @return DatabaseManagerInterface The database manager instance
     */
    private static function createManager(): DatabaseManagerInterface
    {
        $environment = self::getCachedEnvironment();
        
        switch ($environment) {
            case 'swoole':
                return self::createSwooleManager();
                
            case 'apache':
            case 'nginx':
            default:
                return self::createPdoManager();
        }
    }

    /**
     * Get cached environment detection result
     * 
     * @return string The detected environment ('swoole', 'apache', 'nginx')
     */
    private static function getCachedEnvironment(): string
    {
        if (!self::$detectionPerformed) {
            self::$cachedEnvironment = self::detectEnvironment();
            self::$detectionPerformed = true;
        }
        
        return self::$cachedEnvironment ?? 'apache';
    }

    /**
     * Fast environment detection with optimized checks
     * 
     * @return string The detected environment
     */
    private static function detectEnvironment(): string
    {
        // Fast-path: Check environment variable first (fastest)
        $envType = $_ENV['WEBSERVER_TYPE'] ?? null;
        if ($envType === 'swoole') {
            return 'swoole';
        }
        if ($envType === 'apache') {
            return 'apache';
        }
        if ($envType === 'nginx') {
            return 'nginx';
        }

        // Fast-path: Check Swoole constants (very fast)
        if (defined('SWOOLE_BASE') || defined('SWOOLE_PROCESS')) {
            return 'swoole';
        }

        // Medium-path: Check server software (moderate speed)
        if (isset($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE'])) {
            $serverSoftware = strtolower($_SERVER['SERVER_SOFTWARE']);
            if (strpos($serverSoftware, 'nginx') !== false) {
                return 'nginx';
            }
            if (strpos($serverSoftware, 'apache') !== false) {
                return 'apache';
            }
        }

        // Slow-path: Check Swoole classes (slowest - only if needed)
        if (class_exists('\OpenSwoole\Server', false) || class_exists('\Swoole\Server', false)) {
            return 'swoole';
        }

        // Slow-path: Check Swoole functions (slow - only if needed)
        if (function_exists('OpenSwoole\Coroutine::getCid') || function_exists('Swoole\Coroutine::getCid')) {
            return 'swoole';
        }

        // Heuristic checks (fast)
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_REAL_IP'])) {
            return 'nginx'; // Nginx often sets these headers
        }

        // Default fallback
        return 'apache';
    }

    /**
     * Create Swoole database manager
     * 
     * @return DatabaseManagerInterface The Swoole database manager
     */
    private static function createSwooleManager(): DatabaseManagerInterface
    {
        // Create a wrapper that implements our interface
        return new SwooleDatabaseManagerAdapter();
    }

    /**
     * Create a PDO database manager instance based on configuration
     * 
     * Checks DB_ENHANCED_CONNECTION environment variable:
     * - If set to '1' or 'true': Uses EnhancedPdoDatabaseManager with persistent connections
     * - Otherwise: Uses SimplePdoDatabaseManager with simple connections
     * 
     * @return DatabaseManagerInterface The appropriate PDO database manager instance
     */
    private static function createPdoManager(): DatabaseManagerInterface
    {
        $useEnhanced = $_ENV['DB_ENHANCED_CONNECTION'] ?? '0';
        
        // Check if enhanced connections are enabled
        if ($useEnhanced === '1' || $useEnhanced === 'true' || $useEnhanced === 'yes') {
            return EnhancedPdoDatabaseManager::getInstance(true); // Use persistent connections
        }
        
        return SimplePdoDatabaseManager::getInstance(); // Use simple connections
    }

    /**
     * Reset the singleton instance and cache (useful for testing)
     * 
     * @return void
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            // Call reset on the underlying manager if it has one
            if (method_exists(self::$instance, 'resetInstance')) {
                self::$instance->resetInstance();
            }
            self::$instance = null;
        }
        self::$cachedEnvironment = null;
        self::$detectionPerformed = false;
    }

    /**
     * Get information about the current database manager
     * 
     * @return array<string, mixed> Manager information
     */
    public static function getManagerInfo(): array
    {
        $manager = self::getManager();
        $environment = self::getCachedEnvironment();
        
        $info = [
            'environment' => $environment,
            'manager_class' => get_class($manager),
            'pool_stats' => $manager->getPoolStats(),
            'initialized' => $manager->isInitialized(),
            'has_error' => $manager->getError() !== null,
            'error' => $manager->getError(),
            'detection_cached' => self::$detectionPerformed,
            'performance_mode' => 'optimized'
        ];
        
        // Add PDO-specific configuration info
        if ($environment === 'apache' || $environment === 'nginx') {
            $useEnhanced = $_ENV['DB_ENHANCED_CONNECTION'] ?? '0';
            $info['pdo_config'] = [
                'enhanced_connection' => $useEnhanced,
                'persistent_enabled' => ($useEnhanced === '1' || $useEnhanced === 'true' || $useEnhanced === 'yes'),
                'implementation' => ($useEnhanced === '1' || $useEnhanced === 'true' || $useEnhanced === 'yes') 
                    ? 'EnhancedPdoDatabaseManager' 
                    : 'SimplePdoDatabaseManager'
            ];
        }
        
        return $info;
    }

    /**
     * Force environment detection (bypasses cache)
     * 
     * @return string The detected environment
     */
    public static function forceDetection(): string
    {
        self::$detectionPerformed = false;
        return self::getCachedEnvironment();
    }

    /**
     * Get performance metrics for detection
     * 
     * @return array<string, mixed> Performance metrics
     */
    public static function getPerformanceMetrics(): array
    {
        $start = microtime(true);
        $environment = self::forceDetection();
        $detectionTime = (microtime(true) - $start) * 1000; // Convert to milliseconds
        
        return [
            'detection_time_ms' => round($detectionTime, 3),
            'environment' => $environment,
            'cached' => self::$detectionPerformed,
            'performance_level' => $detectionTime < 0.1 ? 'excellent' : ($detectionTime < 1 ? 'good' : 'needs_optimization')
        ];
    }
}

/**
 * Adapter for SwooleDatabaseManager to implement DatabaseManagerInterface
 * 
 * This adapter wraps the existing SwooleDatabaseManager to provide
 * a consistent interface while maintaining backward compatibility.
 */
class SwooleDatabaseManagerAdapter implements DatabaseManagerInterface
{
    /** @var SwooleDatabaseManager The wrapped Swoole database manager */
    private SwooleDatabaseManager $swooleManager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->swooleManager = SwooleDatabaseManager::getInstance();
    }

    /**
     * Get a database connection
     * 
     * @param string $poolName Connection pool name
     * @return \PDO|null Active PDO connection or null on error
     */
    public function getConnection(string $poolName = 'default'): ?\PDO
    {
        $connection = $this->swooleManager->getConnection($poolName);
        
        // Convert Hyperf\DbConnection\Connection to PDO
        if ($connection !== null) {
            // @phpstan-ignore-next-line
            return $connection->getPdo();
        }
        
        return null;
    }

    /**
     * Release a connection back to the pool
     * 
     * @param \PDO $connection The connection to release
     * @return void
     */
    public function releaseConnection(\PDO $connection): void
    {
        // In Swoole, connections are managed by the pool
        // We don't need to explicitly release them
    }

    /**
     * Get the last error message
     * 
     * @return string|null Error message or null if no error occurred
     */
    public function getError(): ?string
    {
        return $this->swooleManager->getError();
    }

    /**
     * Set an error message
     * 
     * @param string|null $error The error message to set
     * @param array<string, mixed> $context Additional context information
     * @return void
     */
    public function setError(?string $error, array $context = []): void
    {
        $this->swooleManager->setError($error, $context);
    }

    /**
     * Clear the last error message
     * 
     * @return void
     */
    public function clearError(): void
    {
        $this->swooleManager->clearError();
    }

    /**
     * Check if the database manager is properly initialized
     * 
     * @return bool True if initialized, false otherwise
     */
    public function isInitialized(): bool
    {
        return true; // SwooleDatabaseManager is always initialized
    }

    /**
     * Get connection pool statistics
     * 
     * @return array<string, mixed> Pool statistics
     */
    public function getPoolStats(): array
    {
        return [
            'type' => 'Swoole Database Manager',
            'environment' => 'OpenSwoole',
            'has_error' => $this->swooleManager->getError() !== null,
            'error' => $this->swooleManager->getError(),
        ];
    }

    /**
     * Begin a database transaction
     * 
     * @param string $poolName Connection pool name
     * @return bool True on success, false on failure
     */
    public function beginTransaction(string $poolName = 'default'): bool
    {
        $connection = $this->swooleManager->getConnection($poolName);
        if ($connection === null) {
            return false;
        }

        try {
            // @phpstan-ignore-next-line
            return $connection->getPdo()->beginTransaction();
        } catch (\Exception $e) {
            $this->setError('Failed to begin transaction: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Commit the current transaction
     * 
     * @param string $poolName Connection pool name
     * @return bool True on success, false on failure
     */
    public function commit(string $poolName = 'default'): bool
    {
        $connection = $this->swooleManager->getConnection($poolName);
        if ($connection === null) {
            return false;
        }

        try {
            // @phpstan-ignore-next-line
            return $connection->getPdo()->commit();
        } catch (\Exception $e) {
            $this->setError('Failed to commit transaction: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Rollback the current transaction
     * 
     * @param string $poolName Connection pool name
     * @return bool True on success, false on failure
     */
    public function rollback(string $poolName = 'default'): bool
    {
        $connection = $this->swooleManager->getConnection($poolName);
        if ($connection === null) {
            return false;
        }

        try {
            // @phpstan-ignore-next-line
            return $connection->getPdo()->rollBack();
        } catch (\Exception $e) {
            $this->setError('Failed to rollback transaction: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if currently in a transaction
     * 
     * @param string $poolName Connection pool name
     * @return bool True if in transaction, false otherwise
     */
    public function inTransaction(string $poolName = 'default'): bool
    {
        $connection = $this->swooleManager->getConnection($poolName);
        if ($connection === null) {
            return false;
        }

        try {
            // @phpstan-ignore-next-line
            return $connection->getPdo()->inTransaction();
        } catch (\Exception $e) {
            return false;
        }
    }
}

