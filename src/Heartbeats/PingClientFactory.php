<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use LIVCK\Cloud\Ping\HeartbeatPing;
use LIVCK\Cloud\Ping\HeartbeatPingInterface;
use LIVCK\Cloud\Support\Secret;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the SDK's ping for one URL. A ping never goes through a connection's transport: it
 * carries no API token, has its own short timeout and is never retried.
 *
 * @internal
 */
final readonly class PingClientFactory
{
    /**
     * @param ClientInterface|null $httpClient the PSR-18 client to send through; the SDK builds
     *                                         its own with the timeout when null
     */
    public function __construct(private ?ClientInterface $httpClient = null) {}

    public function make(Secret $url, float $timeout, LoggerInterface $logger, string $userAgentSuffix): HeartbeatPingInterface
    {
        return new HeartbeatPing($url, $timeout, $this->httpClient, logger: $logger, userAgentSuffix: $userAgentSuffix);
    }
}
