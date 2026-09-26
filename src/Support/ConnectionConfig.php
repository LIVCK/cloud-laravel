<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Support;

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\InvalidArgumentException as SdkInvalidArgumentException;
use LIVCK\Cloud\Http\BearerToken;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * One entry of `livck-cloud.connections`, read and checked. A key that is missing or empty
 * takes the SDK's default.
 *
 * The token is held as the SDK's {@see BearerToken}, which keeps it out of dumps, logs and
 * serialised state.
 *
 * @internal
 */
final readonly class ConnectionConfig
{
    /** The `locale` value that follows the application's locale. */
    public const string APP_LOCALE = 'app';

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
    ) {}

    /**
     * @param array<array-key, mixed> $settings
     *
     * @throws ConfigurationException for a value of the wrong type
     */
    public static function fromArray(string $name, array $settings): self
    {
        $key = static fn(string $option): string => sprintf('livck-cloud.connections.%s.%s', $name, $option);
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
            throw new ConfigurationException(sprintf('The LIVCK Cloud connection [%s] is misconfigured: %s', $this->name, $e->getMessage()), 0, $e);
        }
    }

    private static function token(mixed $value, string $key): ?BearerToken
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
