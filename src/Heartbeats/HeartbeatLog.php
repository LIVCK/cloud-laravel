<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use LIVCK\Cloud\Laravel\Support\ConfigValue;
use Psr\Log\LoggerInterface;

/**
 * Where the package's heartbeat warnings go: `livck-cloud.log_channel` when it is set, the
 * application's default channel otherwise. A heartbeat that is not watched should not go
 * unnoticed because no channel was set up for the package.
 *
 * @internal
 */
final class HeartbeatLog
{
    public static function logger(Application $app): LoggerInterface
    {
        $channel = ConfigValue::string($app->make(Config::class)->get('livck-cloud.log_channel'), 'livck-cloud.log_channel');
        $log = $app->make(LogManager::class);

        return $channel === null ? $log->channel() : $log->channel($channel);
    }
}
