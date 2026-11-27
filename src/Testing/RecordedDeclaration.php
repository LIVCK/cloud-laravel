<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Testing;

/**
 * A declaration of heartbeats {@see CloudFake} answered instead of sending it: the body
 * `POST /v1/heartbeats/declare` would have carried.
 */
final readonly class RecordedDeclaration
{
    /**
     * @param list<array<string, mixed>> $heartbeats the entries, as sent
     */
    public function __construct(
        public string $app,
        public string $environment,
        public array $heartbeats,
    ) {}

    /**
     * The declared keys, in the order sent.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->heartbeats as $heartbeat) {
            $keys[] = is_string($heartbeat['key'] ?? null) ? $heartbeat['key'] : '';
        }

        return $keys;
    }

    /**
     * The entry of one key, as sent, or null.
     *
     * @return array<string, mixed>|null
     */
    public function heartbeat(string $key): ?array
    {
        foreach ($this->heartbeats as $heartbeat) {
            if (($heartbeat['key'] ?? null) === $key) {
                return $heartbeat;
            }
        }

        return null;
    }
}
