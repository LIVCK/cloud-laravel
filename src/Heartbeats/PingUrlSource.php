<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * Where a heartbeat's ping URL came from.
 */
enum PingUrlSource: string
{
    /** `heartbeats.urls` in config/livck-cloud.php. */
    case Config = 'config';

    /** The application cache. */
    case Cache = 'cache';
}
