<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Exceptions;

use InvalidArgumentException;
use LIVCK\Cloud\Exceptions\LivckCloudException;

/**
 * config/livck-cloud.php does not describe a usable connection: an unknown connection name,
 * a value of the wrong type, an option the SDK refuses. Nothing was sent to the API.
 */
class ConfigurationException extends InvalidArgumentException implements LivckCloudException
{
    /**
     * @param list<array-key> $configured the names config/livck-cloud.php does define
     */
    public static function unknownConnection(string $name, array $configured): self
    {
        return new self(sprintf(
            'The LIVCK Cloud connection [%s] is not configured. config/livck-cloud.php defines %s.',
            $name,
            $configured === [] ? 'no connections' : '[' . implode(', ', $configured) . ']',
        ));
    }
}
