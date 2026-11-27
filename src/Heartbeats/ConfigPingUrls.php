<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use LIVCK\Cloud\Support\Secret;
use Throwable;

/**
 * The ping URLs written into `heartbeats.urls`. Read from the configuration of the moment.
 *
 * @internal
 */
final readonly class ConfigPingUrls implements PingUrlResolver
{
    /**
     * @param Closure(): Config $config
     */
    public function __construct(private Closure $config) {}

    public function resolve(string $key): ?PingUrl
    {
        try {
            $url = (new HeartbeatConfig(($this->config)()))->urls()[$key] ?? null;

            return $url === null ? null : new PingUrl(new Secret($url), PingUrlSource::Config);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        try {
            return array_map(strval(...), array_keys((new HeartbeatConfig(($this->config)()))->urls()));
        } catch (Throwable) {
            return [];
        }
    }
}
