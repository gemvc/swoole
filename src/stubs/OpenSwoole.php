<?php

/**
 * OpenSwoole Stub File for Development
 * 
 * This file provides type definitions for OpenSwoole and Redisclasses
 * to improve IDE support and static analysis during development.
 */

namespace OpenSwoole\HTTP;

class Server
{
    public function __construct(string $host, int $port) {}
    public function set(array $settings): void {}
    public function on(string $event, callable $callback): void {}
    public function start(): void {}
    public function reload(): void {}
    public function tick(int $interval, callable $callback): void {}
}

class Request
{
    public array $server;
    public array $get;
    public array $post;
    public array $files;
    public array $cookie;
    public array $header;
}

class Response
{
    public function status(int $code): void {}
    public function header(string $key, string $value): void {}
    public function end(string $data): void {}
}

namespace Redis;

class Redis
{
    public function __construct() {}
    public function connect(string $host, int $port, float $timeout = 0): bool {}
    public function pconnect(string $host, int $port, float $timeout = 0): bool {}
    public function auth(string $password): bool {}
    public function select(int $database): bool {}
    public function setOption(int $option, mixed $value): bool {}
    public function close(): bool {}
    public function set(string $key, mixed $value): bool {}
    public function setex(string $key, int $ttl, mixed $value): bool {}
    public function get(string $key): mixed {}
    public function del(string $key): int {}
    public function exists(string $key): bool {}
    public function ttl(string $key): int {}
    public function flushDB(): bool {}
    public function hSet(string $key, string $field, mixed $value): int {}
    public function hGet(string $key, string $field): mixed {}
    public function hGetAll(string $key): array {}
    public function lPush(string $key, mixed $value): int {}
    public function rPush(string $key, mixed $value): int {}
    public function lPop(string $key): mixed {}
    public function rPop(string $key): mixed {}
    public function sAdd(string $key, mixed $value): int {}
    public function sMembers(string $key): array {}
    public function sIsMember(string $key, mixed $value): bool {}
    public function zAdd(string $key, float $score, mixed $value): int {}
    public function zRange(string $key, int $start, int $end, bool $withScores = false): array {}
    public function publish(string $channel, string $message): int {}
    public function subscribe(array $channels, callable $callback): void {}
    public function multi(int $mode): self {}
    
    public const PIPELINE = 1;
    public const MULTI = 2;
    public const OPT_PREFIX = 1;
    public const OPT_READ_TIMEOUT = 2;
}
