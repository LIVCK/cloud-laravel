<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The tiny job the scheduler puts into each queue of `heartbeats.queues` every minute. When a
 * worker runs it, it pings the queue's heartbeat with the time it waited (`wait_seconds`).
 *
 * At most one waits in a queue at a time: the unique lock is held until a worker has run it,
 * and expires after {@see UNIQUE_FOR} seconds, so that a pulse lost with the queue does not
 * stop the next one for good. The lock lives in the heartbeat cache store, which the
 * scheduler and the workers share.
 *
 * @internal
 */
final class QueuePulse implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int UNIQUE_FOR = 300;

    /** A pulse that fails is not retried; the next one is a minute away. */
    public int $tries = 1;

    public function __construct(
        public readonly string $heartbeat,
        string $connection,
        string $queue,
        public readonly float $queuedAt,
    ) {
        $this->onConnection($connection);
        $this->onQueue($queue);
    }

    public function handle(): void
    {
        HeartbeatMonitor::current()?->pulse($this->heartbeat, $this->queuedAt);
    }

    public function uniqueId(): string
    {
        return $this->heartbeat;
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR;
    }

    public function uniqueVia(): Cache
    {
        $container = Container::getInstance();
        $store = (new HeartbeatConfig($container->make(Config::class)))->cacheStore();

        return $container->make(CacheFactory::class)->store($store);
    }

    public function displayName(): string
    {
        return 'LIVCK Cloud queue pulse [' . $this->heartbeat . ']';
    }
}
