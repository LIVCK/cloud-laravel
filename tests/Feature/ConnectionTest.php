<?php

declare(strict_types=1);

use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use LIVCK\Cloud\Laravel\Exceptions\MissingTokenException;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;

describe('resolution', function (): void {
    it('builds the default connection from the configuration', function (): void {
        useConnection('default', [
            'token' => TEST_TOKEN,
            'base_uri' => 'https://api.example.test/v1',
            'timeout' => '12',
            'connect_timeout' => '4',
            'max_retries' => '5',
            'max_retry_after' => '30',
            'idempotency' => 'false',
            'locale' => 'de',
        ]);

        $options = LivckCloud::connection()->options();

        expect($options->baseUri)->toBe('https://api.example.test/v1')
            ->and($options->timeout)->toBe(12.0)
            ->and($options->connectTimeout)->toBe(4.0)
            ->and($options->maxRetries)->toBe(5)
            ->and($options->maxRetryAfter)->toBe(30)
            ->and($options->idempotency)->toBeFalse()
            ->and($options->locale)->toBe('de');
    });

    it('builds a real client that sends through the SDK its own HTTP client', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);

        $client = LivckCloud::connection();

        expect($client)->toBeInstanceOf(CloudClient::class)
            ->and($client instanceof CloudClient ? $client->__debugInfo()['httpClient'] : null)->toBe(GuzzleHttp\Client::class)
            ->and(print_r($client, true))->not->toContain(TEST_TOKEN);
    });

    it('keeps one client per connection', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);
        useConnection('customer-b', ['token' => TEST_TOKEN, 'base_uri' => 'https://b.example.test/v1']);

        $default = LivckCloud::connection();

        expect(LivckCloud::connection())->toBe($default)
            ->and(LivckCloud::connection('default'))->toBe($default)
            ->and(LivckCloud::connection('customer-b'))->not->toBe($default)
            ->and(LivckCloud::connection('customer-b'))->toBe(LivckCloud::connection('customer-b'))
            ->and(LivckCloud::connection('customer-b')->options()->baseUri)->toBe('https://b.example.test/v1');
    });

    it('follows the configured default connection', function (): void {
        useConnection('customer-b', ['token' => TEST_TOKEN, 'base_uri' => 'https://b.example.test/v1']);
        config()->set('livck-cloud.default', 'customer-b');

        expect(LivckCloud::getDefaultConnection())->toBe('customer-b')
            ->and(LivckCloud::connection())->toBe(LivckCloud::connection('customer-b'))
            ->and(app(CloudClientInterface::class)->options()->baseUri)->toBe('https://b.example.test/v1');
    });

    it('builds a connection anew after purge', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);
        useConnection('customer-b', ['token' => TEST_TOKEN]);
        $default = LivckCloud::connection();
        $customer = LivckCloud::connection('customer-b');

        useConnection('default', ['token' => TEST_TOKEN, 'base_uri' => 'https://rotated.example.test/v1']);

        expect(LivckCloud::connection())->toBe($default);

        LivckCloud::purge('default');

        expect(LivckCloud::connection())->not->toBe($default)
            ->and(LivckCloud::connection()->options()->baseUri)->toBe('https://rotated.example.test/v1')
            ->and(LivckCloud::connection('customer-b'))->toBe($customer);

        LivckCloud::purge();

        expect(LivckCloud::connection('customer-b'))->not->toBe($customer);
    });
});

describe('errors', function (): void {
    it('refuses an unknown connection and lists the configured ones', function (): void {
        useConnection('customer-b', ['token' => TEST_TOKEN]);

        expect(fn(): CloudClientInterface => LivckCloud::connection('customer-x'))
            ->toThrow(InvalidArgumentException::class, 'The LIVCK Cloud connection [customer-x] is not configured. config/livck-cloud.php defines [default, customer-b].');
    });

    it('says so when no connection is configured at all', function (): void {
        config()->set('livck-cloud.connections', null);

        expect(fn(): CloudClientInterface => LivckCloud::connection())
            ->toThrow(ConfigurationException::class, 'The LIVCK Cloud connection [default] is not configured. config/livck-cloud.php defines no connections.');
    });

    it('treats a connection that is not an array as not configured', function (): void {
        config()->set('livck-cloud.connections.default', 'lvk_misplaced');

        expect(fn(): CloudClientInterface => LivckCloud::connection())
            ->toThrow(ConfigurationException::class, 'The LIVCK Cloud connection [default] is not configured.');
    });

    it('needs a default connection name', function (mixed $default): void {
        config()->set('livck-cloud.default', $default);

        expect(fn(): CloudClientInterface => LivckCloud::connection())
            ->toThrow(ConfigurationException::class, 'livck-cloud.default must name one of livck-cloud.connections.');
    })->with(['null' => [null], 'empty' => ['']]);

    it('names the environment variable when the default connection has no token', function (): void {
        expect(fn(): CloudClientInterface => LivckCloud::connection())
            ->toThrow(MissingTokenException::class, 'The LIVCK Cloud connection [default] has no API token. Set LIVCK_CLOUD_TOKEN in the environment');
    });

    it('names the configuration key when another connection has no token', function (): void {
        useConnection('customer-b');

        expect(fn(): CloudClientInterface => LivckCloud::connection('customer-b'))
            ->toThrow(MissingTokenException::class, 'Set livck-cloud.connections.customer-b.token in config/livck-cloud.php');
    });

    it('does not keep a connection that failed, so fixing the configuration is enough', function (): void {
        expect(fn(): CloudClientInterface => LivckCloud::connection())->toThrow(MissingTokenException::class);

        useConnection('default', ['token' => TEST_TOKEN]);

        expect(LivckCloud::connection())->toBeInstanceOf(CloudClient::class);
    });

    it('refuses a log channel that is not a name', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);
        config()->set('livck-cloud.log_channel', ['stack']);

        expect(fn(): CloudClientInterface => LivckCloud::connection())
            ->toThrow(ConfigurationException::class, 'livck-cloud.log_channel must be a string, got array.');
    });
});

it('can be used without the facade', function (): void {
    useConnection('default', ['token' => TEST_TOKEN]);

    expect(app(CloudManager::class)->connection())->toBe(LivckCloud::connection());
});
