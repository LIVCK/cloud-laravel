<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * Finds the ping URL of a heartbeat by its key. Never throws: a URL that cannot be read is
 * one that is not known.
 */
interface PingUrlResolver
{
    public function resolve(string $key): ?PingUrl;
}
