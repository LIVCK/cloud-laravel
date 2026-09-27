<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Support;

use Closure;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\InvalidArgumentException as SdkInvalidArgumentException;
use LIVCK\Cloud\Http\BearerToken;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * One entry of `livck-cloud.connections`, read and checked. A key that is missing or empty
 * takes the SDK's default.
 *
 * The token is held as the SDK's {@see BearerToken}, which keeps it out of dumps, logs and
 * serialised state. Parameters that carry it are marked sensitive, so that the trace of an
 * exception thrown while reading does not record it either.
 *
 * @internal
 */
final readonly class ConnectionConfig
{
    /** The `locale` value that follows the application's locale. */
    public const string APP_LOCALE = 'app';

    /** The keys of an entry under `livck-cloud.connections`. */
    public const array KEYS = [
        'token',
        'base_uri',
        'timeout',
        'connect_timeout',
        'max_retries',
        'max_retry_after',
        'idempotency',
        'locale',
        'user_agent_suffix',
        'transport',
    ];

    /**
     * @param string $subject what an error message calls the connection or client
     */
    private function __construct(
        public string $name,
        public ?BearerToken $token,
        public string $baseUri,
        public float $timeout,
        public float $connectTimeout,
        public int $maxRetries,
        public int $maxRetryAfter,
        public bool $idempotency,
        public ?string $locale,
        public ?string $userAgentSuffix,
        public Transport $transport,
        private string $subject,
    ) {}

    /**
     * @param array<array-key, mixed> $settings
     *
     * @throws ConfigurationException for a value of the wrong type
     */
    public static function fromArray(string $name, #[SensitiveParameter] array $settings): self
    {
        return self::read(
            $name,
            $settings,
            static fn(string $option): string => sprintf('livck-cloud.connections.%s.%s', $name, $option),
            sprintf('The LIVCK Cloud connection [%s]', $name),
        );
    }

    /**
     * The settings of a client built on demand: what a call passes, over the settings of the
     * connection it starts from. A key the call passes wins, even with null.
     *
     * Error messages name a value after where it came from: the call or the configuration.
     * What the SDK refuses is put down to the connection when the call passed nothing but a
     * token, and to the call otherwise.
     *
     * @param string $call the call, as error messages name it: `LivckCloud::build()`
     * @param array<array-key, mixed> $inherited the settings of the connection
     * @param array<array-key, mixed> $given the settings of the call
     *
     * @throws ConfigurationException for a value of the wrong type
     */
    public static function onDemand(string $call, string $connection, #[SensitiveParameter] array $inherited, #[SensitiveParameter] array $given): self
    {
        $passed = array_keys($given);

        return self::read(
            $connection,
            [...$inherited, ...$given],
            static fn(string $option): string => in_array($option, $passed, true)
                ? sprintf('%s passed to %s', $option, $call)
                : sprintf('livck-cloud.connections.%s.%s', $connection, $option),
            array_diff($passed, ['token']) === []
                ? sprintf('The LIVCK Cloud connection [%s]', $connection)
                : sprintf('The LIVCK Cloud client from %s', $call),
        );
    }

    /** Whether the connection sends the application's locale of the moment (`locale: 'app'`). */
    public function followsApplicationLocale(): bool
    {
        return $this->locale === self::APP_LOCALE;
    }

    /**
     * The SDK options of this connection. A connection following the application's locale
     * gets none here; the manager adds the current one per call.
     *
     * @param string $userAgentSuffix the complete suffix, the package's own product token included
     *
     * @throws ConfigurationException when the SDK refuses a value (a plain-http base URI, a negative timeout)
     */
    public function options(string $userAgentSuffix, LoggerInterface $logger): ClientOptions
    {
        try {
            return new ClientOptions(
                baseUri: $this->baseUri,
                locale: $this->followsApplicationLocale() ? null : $this->locale,
                timeout: $this->timeout,
                connectTimeout: $this->connectTimeout,
                maxRetries: $this->maxRetries,
                maxRetryAfter: $this->maxRetryAfter,
                idempotency: $this->idempotency,
                userAgentSuffix: $userAgentSuffix,
                logger: $logger,
            );
        } catch (SdkInvalidArgumentException $e) {
            throw new ConfigurationException(sprintf('%s is misconfigured: %s', $this->subject, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $settings
     * @param Closure(string): string $key what an error message calls an option
     *
     * @throws ConfigurationException for a value of the wrong type
     */
    private static function read(string $name, #[SensitiveParameter] array $settings, Closure $key, string $subject): self
    {
        $defaults = new ClientOptions();

        return new self(
            $name,
            self::token($settings['token'] ?? null, $key('token')),
            ConfigValue::string($settings['base_uri'] ?? null, $key('base_uri')) ?? $defaults->baseUri,
            ConfigValue::float($settings['timeout'] ?? null, $key('timeout'), $defaults->timeout),
            ConfigValue::float($settings['connect_timeout'] ?? null, $key('connect_timeout'), $defaults->connectTimeout),
            ConfigValue::int($settings['max_retries'] ?? null, $key('max_retries'), $defaults->maxRetries),
            ConfigValue::int($settings['max_retry_after'] ?? null, $key('max_retry_after'), $defaults->maxRetryAfter),
            ConfigValue::bool($settings['idempotency'] ?? null, $key('idempotency'), $defaults->idempotency),
            ConfigValue::string($settings['locale'] ?? null, $key('locale')),
            ConfigValue::string($settings['user_agent_suffix'] ?? null, $key('user_agent_suffix')),
            self::transport($settings['transport'] ?? null, $key('transport')),
            $subject,
        );
    }

    private static function token(#[SensitiveParameter] mixed $value, string $key): ?BearerToken
    {
        $token = ConfigValue::string($value, $key);

        try {
            return $token === null ? null : new BearerToken($token);
        } catch (SdkInvalidArgumentException $e) {
            throw new ConfigurationException(sprintf('%s is not usable: %s', $key, $e->getMessage()), 0, $e);
        }
    }

    private static function transport(mixed $value, string $key): Transport
    {
        $transport = ConfigValue::string($value, $key);

        if ($transport === null) {
            return Transport::Sdk;
        }

        return Transport::tryFrom($transport) ?? throw new ConfigurationException(sprintf(
            '%s must be "%s" or "%s", got "%s".',
            $key,
            Transport::Sdk->value,
            Transport::Laravel->value,
            $transport,
        ));
    }
}
