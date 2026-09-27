<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Exceptions;

use InvalidArgumentException;
use LIVCK\Cloud\Exceptions\LivckCloudException;

/**
 * config/livck-cloud.php, or the settings passed to `LivckCloud::build()` or
 * `LivckCloud::withToken()`, do not describe a usable client: an unknown connection name or
 * key, a value of the wrong type, an option the SDK refuses. Nothing was sent to the API.
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

    /**
     * `LivckCloud::build()` got keys a connection does not have, a typo most likely. Names the
     * keys, never their values.
     *
     * @param list<array-key> $unknown the keys it does not know
     * @param list<string> $known the keys of a connection
     */
    public static function unknownKeys(array $unknown, array $known): self
    {
        return new self(sprintf(
            'LivckCloud::build() does not know the %s [%s]. It takes the keys of a connection in config/livck-cloud.php: [%s].',
            count($unknown) === 1 ? 'key' : 'keys',
            implode(', ', $unknown),
            implode(', ', $known),
        ));
    }
}
