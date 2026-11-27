<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * The URL of a key from the configuration first, then from the cache: a URL written by hand
 * always wins.
 *
 * @internal
 */
final readonly class PingUrls implements PingUrlResolver
{
    public function __construct(
        private ConfigPingUrls $config,
        private CachedPingUrls $cache,
    ) {}

    public function resolve(string $key): ?PingUrl
    {
        return $this->config->resolve($key) ?? $this->cache->resolve($key);
    }
}
