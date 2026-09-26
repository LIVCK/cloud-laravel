<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Exceptions;

/**
 * A connection is used without an API token.
 */
final class MissingTokenException extends ConfigurationException
{
    /** The environment variable the shipped configuration reads the default connection's token from. */
    public const string ENVIRONMENT_VARIABLE = 'LIVCK_CLOUD_TOKEN';

    public static function forConnection(string $name): self
    {
        if ($name === 'default') {
            return new self(sprintf(
                'The LIVCK Cloud connection [default] has no API token. Set %s in the environment; config/livck-cloud.php reads it into livck-cloud.connections.default.token.',
                self::ENVIRONMENT_VARIABLE,
            ));
        }

        return new self(sprintf(
            'The LIVCK Cloud connection [%1$s] has no API token. Set livck-cloud.connections.%1$s.token in config/livck-cloud.php, typically from an environment variable of its own (%2$s belongs to the default connection).',
            $name,
            self::ENVIRONMENT_VARIABLE,
        ));
    }
}
