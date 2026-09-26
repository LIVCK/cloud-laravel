<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use LIVCK\Cloud\Laravel\Exceptions\MissingTokenException;
use LIVCK\Cloud\Laravel\Support\ConnectionConfig;
use LIVCK\Cloud\Laravel\Support\Transport;
use Psr\Log\NullLogger;

it('reads every option of a connection', function (): void {
    $config = ConnectionConfig::fromArray('customer-b', [
        'token' => 'lvk_token',
        'base_uri' => 'http://localhost:8000/api/v1/',
        'timeout' => '12.5',
        'connect_timeout' => 3,
        'max_retries' => '4',
        'max_retry_after' => 30,
        'idempotency' => false,
        'locale' => 'de',
        'user_agent_suffix' => 'panel/2.3',
        'transport' => 'laravel',
    ]);
    $options = $config->options('suffix/1.0', new NullLogger());

    expect($config->name)->toBe('customer-b')
        ->and($config->token?->authorizationHeader())->toBe('Bearer lvk_token')
        ->and($config->transport)->toBe(Transport::Laravel)
        ->and($config->userAgentSuffix)->toBe('panel/2.3')
        ->and($config->followsApplicationLocale())->toBeFalse()
        ->and($options->baseUri)->toBe('http://localhost:8000/api/v1')
        ->and($options->timeout)->toBe(12.5)
        ->and($options->connectTimeout)->toBe(3.0)
        ->and($options->maxRetries)->toBe(4)
        ->and($options->maxRetryAfter)->toBe(30)
        ->and($options->idempotency)->toBeFalse()
        ->and($options->locale)->toBe('de')
        ->and($options->userAgentSuffix)->toBe('suffix/1.0');
});

it('takes the SDK defaults for whatever is missing', function (): void {
    $config = ConnectionConfig::fromArray('default', []);
    $options = $config->options('suffix/1.0', new NullLogger());
    $defaults = new ClientOptions();

    expect($config->token)->toBeNull()
        ->and($config->transport)->toBe(Transport::Sdk)
        ->and($config->locale)->toBeNull()
        ->and($options->baseUri)->toBe(ClientOptions::DEFAULT_BASE_URI)
        ->and($options->timeout)->toBe($defaults->timeout)
        ->and($options->connectTimeout)->toBe($defaults->connectTimeout)
        ->and($options->maxRetries)->toBe($defaults->maxRetries)
        ->and($options->maxRetryAfter)->toBe($defaults->maxRetryAfter)
        ->and($options->idempotency)->toBe($defaults->idempotency);
});

it('leaves the locale to the caller when the connection follows the application', function (): void {
    $config = ConnectionConfig::fromArray('default', ['locale' => 'app']);

    expect($config->followsApplicationLocale())->toBeTrue()
        ->and($config->options('suffix/1.0', new NullLogger())->locale)->toBeNull();
});

it('treats a blank token as missing', function (): void {
    expect(ConnectionConfig::fromArray('default', ['token' => '   '])->token)->toBeNull();
});

it('keeps the token out of dumps', function (): void {
    $config = ConnectionConfig::fromArray('default', ['token' => TEST_TOKEN]);

    expect(print_r($config, true))->not->toContain(TEST_TOKEN)
        ->and(var_export($config, true))->not->toContain(TEST_TOKEN);
});

it('refuses a token the API could never accept, without repeating it', function (): void {
    expect(fn(): ConnectionConfig => ConnectionConfig::fromArray('default', ['token' => "lvk_with space\u{00e4}"]))
        ->toThrow(ConfigurationException::class, 'livck-cloud.connections.default.token is not usable: The API token must consist of printable ASCII characters without whitespace.');
});

it('names the allowed transports', function (): void {
    expect(fn(): ConnectionConfig => ConnectionConfig::fromArray('default', ['transport' => 'curl']))
        ->toThrow(ConfigurationException::class, 'livck-cloud.connections.default.transport must be "sdk" or "laravel", got "curl".');
});

it('reports what the SDK refuses with the connection it belongs to', function (array $settings, string $message): void {
    $config = ConnectionConfig::fromArray('customer-b', $settings);

    expect(fn(): ClientOptions => $config->options('suffix/1.0', new NullLogger()))
        ->toThrow(ConfigurationException::class, 'The LIVCK Cloud connection [customer-b] is misconfigured: ' . $message);
})->with([
    'plain http' => [['base_uri' => 'http://api.example.com/v1'], 'The base URI must use https.'],
    'negative timeout' => [['timeout' => -1], 'timeout and connectTimeout must be positive.'],
    'too many retries' => [['max_retries' => 11], 'maxRetries must be between 0 and 10.'],
    'non-ascii locale' => [['locale' => 'dé'], 'locale must be a non-empty printable ASCII string.'],
]);

it('marks every configuration error as a LIVCK Cloud exception and an invalid argument', function (): void {
    expect(new ConfigurationException('x'))->toBeInstanceOf(LivckCloudException::class)
        ->and(new ConfigurationException('x'))->toBeInstanceOf(InvalidArgumentException::class)
        ->and(MissingTokenException::forConnection('default'))->toBeInstanceOf(ConfigurationException::class);
});
