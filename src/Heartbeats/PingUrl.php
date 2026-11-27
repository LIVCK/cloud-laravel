<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use LIVCK\Cloud\Support\Secret;

/**
 * A heartbeat's ping URL and where it came from. The URL is a credential, held as a
 * {@see Secret}: it shows in no dump and no log line.
 */
final readonly class PingUrl
{
    public function __construct(
        public Secret $url,
        public PingUrlSource $source,
    ) {}
}
