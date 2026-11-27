<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use LIVCK\Cloud\Ping\PingDetails;
use LIVCK\Cloud\Ping\PingResult;
use Throwable;

/**
 * A heartbeat pinged from your own code, by its key: `LivckCloud::heartbeat('backup')`. The
 * URL comes from `heartbeats.urls` (or the cache), so the code never holds it.
 *
 *     $heartbeat = LivckCloud::heartbeat('backup');
 *     $heartbeat->start();
 *     $heartbeat->exitCode(run_backup());
 *
 * Like every ping of the package it never throws, and outside `heartbeats.environments` it
 * sends nothing (under `LivckCloud::fake()` it is recorded).
 */
final readonly class NamedHeartbeat
{
    /**
     * @internal built by LivckCloud::heartbeat()
     */
    public function __construct(
        public string $key,
        private HeartbeatPinger $pinger,
        private HeartbeatMonitor $monitor,
    ) {}

    public function start(?PingDetails $details = null): PingResult
    {
        return $this->enabled() ? $this->pinger->start($this->key, $details) : $this->off();
    }

    public function success(?PingDetails $details = null): PingResult
    {
        return $this->enabled() ? $this->pinger->success($this->key, $details) : $this->off();
    }

    public function fail(?PingDetails $details = null): PingResult
    {
        return $this->enabled() ? $this->pinger->fail($this->key, $details) : $this->off();
    }

    /** 0 is a success, anything else a failure with that code. */
    public function exitCode(int $code, ?PingDetails $details = null): PingResult
    {
        return $this->enabled() ? $this->pinger->exitCode($this->key, $code, $details) : $this->off();
    }

    private function enabled(): bool
    {
        try {
            return $this->monitor->isActive() || $this->monitor->allowsEnvironment();
        } catch (Throwable) {
            return false;
        }
    }

    private function off(): PingResult
    {
        return PingResult::notSent('Heartbeats are off in this environment (livck-cloud.heartbeats.environments).');
    }
}
