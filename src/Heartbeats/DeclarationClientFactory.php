<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Laravel\CloudManager;
use Psr\Http\Client\ClientInterface;

/**
 * Builds the client a declaration goes through: the heartbeat connection's token and
 * settings, with a short timeout and no retries when the scheduler is waiting for it.
 *
 * @internal
 */
final readonly class DeclarationClientFactory
{
    /**
     * @param ClientInterface|null $httpClient the PSR-18 client to send through; the
     *                                         connection's own when null
     */
    public function __construct(private ?ClientInterface $httpClient = null) {}

    /**
     * @param float|null $timeout seconds for the whole request, without retries; the
     *                            connection's settings as they are when null
     */
    public function make(CloudManager $manager, ?float $timeout): CloudClientInterface
    {
        return $manager->heartbeatClient($timeout, $this->httpClient);
    }
}
