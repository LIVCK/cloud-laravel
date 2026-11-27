<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Where the package keeps what it knows about the heartbeats of this application: the store of
 * `heartbeats.cache_store` (the default one when null), under keys of one LIVCK Cloud
 * connection, application and environment. Staging and production sharing a cache never mix.
 *
 * @internal
 */
final class HeartbeatStore
{
    private const string PREFIX = 'livck-cloud:heartbeat:';

    public static function cache(Application $app): Cache
    {
        $store = (new HeartbeatConfig($app->make(Config::class)))->cacheStore();

        return $app->make(CacheFactory::class)->store($store);
    }

    /** A cache key of this connection, application and environment; the suffix is hashed in. */
    public static function key(Application $app, string $suffix): string
    {
        $config = new HeartbeatConfig($app->make(Config::class));
        $scope = implode("\n", [$config->connection(), $config->app(), $app->environment(), $suffix]);

        return self::PREFIX . hash('xxh128', $scope);
    }
}
