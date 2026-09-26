<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Support;

/**
 * How a connection sends its requests (`transport` in config/livck-cloud.php).
 *
 * @internal
 */
enum Transport: string
{
    /** The SDK's own HTTP client: Guzzle, configured with the connection's timeouts. */
    case Sdk = 'sdk';

    /** Laravel's HTTP client stack, visible to Http::fake(), Telescope and Pulse. */
    case Laravel = 'laravel';
}
