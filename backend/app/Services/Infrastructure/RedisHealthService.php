<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Lightweight, non-fatal Redis availability probe.
 *
 * The application must keep working even when Redis is not configured or is
 * temporarily unreachable (local/dev/testing deployments use the file/database
 * cache and sync queue). Every method here swallows connection errors and
 * returns a graceful fallback value instead of throwing.
 */
class RedisHealthService
{
    /**
     * True if Redis is usable right now.
     *
     * @param  string|null  $connection  Redis connection name (default: default)
     */
    public function isAvailable(?string $connection = null): bool
    {
        return $this->ping($connection) === true;
    }

    /**
     * Ping a Redis connection. Returns true when reachable, false otherwise.
     */
    public function ping(?string $connection = null): bool
    {
        try {
            Redis::connection($connection ?: 'default')->ping();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Round-trip latency in milliseconds (or null when unavailable).
     */
    public function latencyMs(?string $connection = null): ?int
    {
        try {
            $start = microtime(true);
            Redis::connection($connection ?: 'default')->ping();
            $elapsedMs = (microtime(true) - $start) * 1000;

            return (int) round($elapsedMs);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Status payload for the health endpoint. Non-fatal.
     */
    public function status(): array
    {
        return [
            'available' => $this->isAvailable(),
            'latency_ms' => $this->latencyMs(),
            'cache_store' => config('cache.default'),
            'queue_connection' => config('queue.default'),
        ];
    }

    /**
     * Whether the configured cache store is Redis-backed (used to decide if
     * the distributed scheduler lock will actually be distributed).
     */
    public function cacheIsRedis(): bool
    {
        return config('cache.default') === 'redis';
    }

    /**
     * Acquire a non-blocking atomic lock via the app cache store.
     *
     * On a Redis-backed store this is a true distributed lock (single runner
     * across servers). On local stores it still guarantees a single runner per
     * process, which is the correct behaviour for single-server deployments.
     */
    public function acquireLock(string $key, int $seconds = 600): bool
    {
        try {
            return Cache::lock($key, $seconds)->get();
        } catch (\Throwable $e) {
            // Cache not available: fall back to "no remote coordination needed"
            // for single-server deployments rather than hard-failing.
            return true;
        }
    }

    /**
     * Release a previously acquired lock.
     */
    public function releaseLock(string $key): void
    {
        try {
            Cache::lock($key)->forceRelease();
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
