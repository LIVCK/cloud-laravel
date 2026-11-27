<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The heartbeats of one application in one environment, as the code describes them: from the
 * tasks marked with `->heartbeat()`, the queues and the URLs of `heartbeats.urls`, sorted by
 * key. Tasks that cannot be watched are listed apart as problems.
 *
 * @implements IteratorAggregate<string, Heartbeat>
 */
final readonly class HeartbeatList implements Countable, IteratorAggregate
{
    /** @var array<string, Heartbeat> */
    private array $heartbeats;

    /**
     * @param iterable<Heartbeat> $heartbeats one per key; a later one of the same key wins
     * @param list<HeartbeatProblem> $problems
     */
    public function __construct(
        public string $app,
        public string $environment,
        iterable $heartbeats,
        private array $problems = [],
    ) {
        $byKey = [];

        foreach ($heartbeats as $heartbeat) {
            $byKey[$heartbeat->key] = $heartbeat;
        }

        ksort($byKey, SORT_STRING);
        $this->heartbeats = $byKey;
    }

    public function get(string $key): ?Heartbeat
    {
        return $this->heartbeats[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->heartbeats[$key]);
    }

    /**
     * @return list<Heartbeat>
     */
    public function heartbeats(): array
    {
        return array_values($this->heartbeats);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->heartbeats));
    }

    /**
     * @return list<HeartbeatProblem>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The heartbeats the callback keeps, with the same problems.
     *
     * @param Closure(Heartbeat): bool $keep
     */
    public function filter(Closure $keep): self
    {
        return new self($this->app, $this->environment, array_filter($this->heartbeats, $keep), $this->problems);
    }

    /**
     * Application, environment and heartbeats, as LIVCK reads a list. Problems are not part of it.
     *
     * @return array{app: string, environment: string, heartbeats: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'app' => $this->app,
            'environment' => $this->environment,
            'heartbeats' => array_map(static fn(Heartbeat $heartbeat): array => $heartbeat->toArray(), $this->heartbeats()),
        ];
    }

    /**
     * A fingerprint of {@see toArray()}: the same for the same list, whatever order the tasks
     * were defined or chained in, and different as soon as a key, a schedule or an option changes.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function count(): int
    {
        return count($this->heartbeats);
    }

    /**
     * @return Traversable<string, Heartbeat>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->heartbeats);
    }
}
