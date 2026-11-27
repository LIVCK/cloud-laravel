<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * A queue of `heartbeats.queues`: where the pulse goes and which heartbeat it pings.
 *
 * @internal
 */
final readonly class QueueTarget
{
    public function __construct(
        public string $connection,
        public string $queue,
        public string $key,
    ) {}

    /**
     * `queue:<name>` on the default queue connection, `queue:<connection>:<name>` on another.
     */
    public static function on(string $connection, string $queue, string $defaultConnection): self
    {
        $key = $connection === $defaultConnection ? 'queue:' . $queue : sprintf('queue:%s:%s', $connection, $queue);

        return new self($connection, $queue, $key);
    }
}
