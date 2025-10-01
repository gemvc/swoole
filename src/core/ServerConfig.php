<?php

namespace Gemvc\Core;

/**
 * Server Configuration Manager
 * 
 * Handles all OpenSwoole server configuration and environment settings
 */
class ServerConfig
{
    private array $config;

    public function __construct()
    {
        $this->loadConfig();
    }

    /**
     * Get the complete server configuration array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get server host
     */
    public function getHost(): string
    {
        return $_ENV["SWOOLE_SERVER_HOST"] ?? "0.0.0.0";
    }

    /**
     * Get server port
     */
    public function getPort(): int
    {
        return (int)($_ENV["SWOOLE_SERVER_PORT"] ?? 9501);
    }

    /**
     * Check if running in development mode
     */
    public function isDev(): bool
    {
        return ($_ENV["APP_ENV"] ?? '') === "dev";
    }

    /**
     * Load server configuration from environment variables
     */
    private function loadConfig(): void
    {
        $this->config = [
            'worker_num' => (int)($_ENV["SWOOLE_WORKERS"] ?? 1),
            'daemonize' => (bool)($_ENV["SWOOLE_RUN_FOREGROUND"] ?? 0),
            'max_request' => (int)($_ENV["SWOOLE_MAX_REQUEST"] ?? 5000),
            'max_conn' => (int)($_ENV["SWOOLE_MAX_CONN"] ?? 1024),
            'max_wait_time' => (int)($_ENV["SWOOLE_MAX_WAIT_TIME"] ?? 120),
            'enable_coroutine' => (bool)($_ENV["SWOOLE_ENABLE_COROUTINE"] ?? 1),
            'max_coroutine' => (int)($_ENV["SWOOLE_MAX_COROUTINE"] ?? 3000),
            'display_errors' => (int)($_ENV["SWOOLE_DISPLAY_ERRORS"] ?? 1),
            'heartbeat_idle_time' => (int)($_ENV["SWOOLE_HEARTBEAT_IDLE_TIME"] ?? 600),
            'heartbeat_check_interval' => (int)($_ENV["SWOOLE__HEARTBEAT_INTERVAL"] ?? 60),
            'log_level' => (int)(($_ENV["SWOOLE_SERVER_LOG_INFO"] ?? 0) ? 0 : 1), // 0 = SWOOLE_LOG_INFO, 1 = SWOOLE_LOG_ERROR
            'reload_async' => true
        ];
    }

    /**
     * Get a specific configuration value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set a configuration value
     */
    public function set(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Check if a configuration key exists
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }
}
