<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use LIVCK\Cloud\Laravel\CloudServiceProvider;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** The shipped default connection, with the values its environment variables default to. */
    public const array DEFAULT_CONNECTION = [
        'token' => null,
        'base_uri' => 'https://api.livck.cloud/v1',
        'timeout' => 30,
        'connect_timeout' => 10,
        'max_retries' => 2,
        'max_retry_after' => 60,
        'idempotency' => true,
        'locale' => null,
        'user_agent_suffix' => null,
        'transport' => 'sdk',
    ];

    protected function getPackageProviders($app): array
    {
        return [CloudServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['LivckCloud' => LivckCloud::class];
    }

    protected function defineEnvironment($app): void
    {
        // Known values instead of whatever the shell exports, such as the smoke test's variables.
        $config = $app->make(Repository::class);
        $config->set('livck-cloud.default', 'default');
        $config->set('livck-cloud.connections', ['default' => self::DEFAULT_CONNECTION]);
        $config->set('livck-cloud.log_channel', null);
        $config->set('livck-cloud.catalog_cache', ['store' => 'array', 'ttl' => 3600]);
    }
}
