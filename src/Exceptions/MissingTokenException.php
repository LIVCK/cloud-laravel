<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Exceptions;

/**
 * A connection is used without an API token, or a client built on demand gets none.
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

    /**
     * `LivckCloud::withToken()` or `LivckCloud::build()` got a blank token, which is never
     * replaced by a configured one.
     *
     * @param string $call the call, as the message names it: `LivckCloud::withToken()`
     */
    public static function blank(string $call): self
    {
        return new self(sprintf(
            '%s got a blank API token. It is refused rather than replaced by a configured one, so that a request cannot reach the wrong organization.',
            $call,
        ));
    }

    /**
     * `LivckCloud::build()` got no token, and the default connection has none to inherit.
     */
    public static function notInherited(string $connection): self
    {
        return new self(sprintf(
            'LivckCloud::build() got no API token, and the default connection [%s] has none to inherit. Pass one under the key "token", or configure one for that connection.',
            $connection,
        ));
    }
}
