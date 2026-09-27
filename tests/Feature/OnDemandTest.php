<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use LIVCK\Cloud\Laravel\Exceptions\MissingTokenException;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;

describe('build()', function (): void {
    it('takes what the array leaves out from the default connection, the token included', function (): void {
        useConnection('default', [
            'token' => TEST_TOKEN,
            'base_uri' => 'https://api.example.test/v1',
            'timeout' => '12',
            'connect_timeout' => '4',
            'max_retries' => '5',
            'max_retry_after' => '30',
            'idempotency' => 'false',
            'locale' => 'de',
            'transport' => 'laravel',
        ]);
        Http::fake(['*' => Http::response(mePayload())]);

        $client = LivckCloud::build(['locale' => 'en']);
        $client->me();
        $options = $client->options();

        expect($options->baseUri)->toBe('https://api.example.test/v1')
            ->and($options->timeout)->toBe(12.0)
            ->and($options->connectTimeout)->toBe(4.0)
            ->and($options->maxRetries)->toBe(5)
            ->and($options->maxRetryAfter)->toBe(30)
            ->and($options->idempotency)->toBeFalse()
            ->and($options->locale)->toBe('en')
            ->and(sentAuthorizations())->toBe([['Bearer ' . TEST_TOKEN]]);
    });

    it('lets the array override every key, null included', function (): void {
        useConnection('default', [
            'token' => TEST_TOKEN,
            'base_uri' => 'https://api.example.test/v1',
            'max_retries' => '5',
            'idempotency' => 'false',
            'locale' => 'de',
            'user_agent_suffix' => 'hoster-panel/2.3',
        ]);
        Http::fake(['*' => Http::response(mePayload())]);

        $client = LivckCloud::build([
            'token' => CUSTOMER_TOKEN,
            'base_uri' => 'https://b.example.test/v1',
            'timeout' => 5,
            'connect_timeout' => 2.5,
            'max_retries' => 0,
            'max_retry_after' => 10,
            'idempotency' => true,
            'locale' => null,
            'user_agent_suffix' => null,
            'transport' => 'laravel',
        ]);
        $client->me();
        $options = $client->options();

        expect($options->baseUri)->toBe('https://b.example.test/v1')
            ->and($options->timeout)->toBe(5.0)
            ->and($options->connectTimeout)->toBe(2.5)
            ->and($options->maxRetries)->toBe(0)
            ->and($options->maxRetryAfter)->toBe(10)
            ->and($options->idempotency)->toBeTrue()
            ->and($options->locale)->toBeNull()
            ->and($options->userAgentSuffix)->toBe(sprintf('%s/%s Laravel/%s', CloudManager::USER_AGENT_PRODUCT, CloudManager::VERSION, app()->version()))
            ->and(sentAuthorizations())->toBe([['Bearer ' . CUSTOMER_TOKEN]]);
    });

    it('refuses a key a connection does not have and names it', function (Closure $build, string $message): void {
        useConnection('default', ['token' => TEST_TOKEN]);

        expect($build)->toThrow(ConfigurationException::class, $message);
    })->with([
        'one' => [
            fn(): CloudClientInterface => LivckCloud::build(['tokn' => CUSTOMER_TOKEN]),
            'LivckCloud::build() does not know the key [tokn]. It takes the keys of a connection in config/livck-cloud.php: [token, base_uri, timeout, connect_timeout, max_retries, max_retry_after, idempotency, locale, user_agent_suffix, transport].',
        ],
        'several' => [
            fn(): CloudClientInterface => LivckCloud::build(['token' => CUSTOMER_TOKEN, 'localle' => 'de', 'retries' => 3]),
            'LivckCloud::build() does not know the keys [localle, retries].',
        ],
    ]);

    it('needs a token of its own when the default connection has none', function (): void {
        expect(fn(): CloudClientInterface => LivckCloud::build(['locale' => 'en']))
            ->toThrow(MissingTokenException::class, 'LivckCloud::build() got no API token, and the default connection [default] has none to inherit. Pass one under the key "token", or configure one for that connection.');
    });

    it('refuses a blank token instead of falling back to the default connection', function (mixed $blank): void {
        useConnection('default', ['token' => TEST_TOKEN]);

        expect(fn(): CloudClientInterface => LivckCloud::build(['token' => $blank]))
            ->toThrow(MissingTokenException::class, 'LivckCloud::build() got a blank API token. It is refused rather than replaced by a configured one, so that a request cannot reach the wrong organization.');
    })->with(['null' => [null], 'empty' => [''], 'blank' => ['   ']]);

    it('names a wrong value after the key it came from', function (): void {
        useConnection('default', ['token' => TEST_TOKEN, 'max_retries' => 'many']);

        expect(fn(): CloudClientInterface => LivckCloud::build(['max_retries' => 'several']))
            ->toThrow(ConfigurationException::class, 'max_retries passed to LivckCloud::build() must be a whole number, got "several".')
            ->and(fn(): CloudClientInterface => LivckCloud::build(['locale' => 'en']))
            ->toThrow(ConfigurationException::class, 'livck-cloud.connections.default.max_retries must be a whole number, got "many".')
            ->and(fn(): CloudClientInterface => LivckCloud::build(['max_retries' => 1, 'base_uri' => 'http://api.example.com/v1']))
            ->toThrow(ConfigurationException::class, 'The LIVCK Cloud client from LivckCloud::build() is misconfigured: The base URI must use https.');
    });
});

describe('withToken()', function (): void {
    it('sends with the settings of the default connection and another token', function (): void {
        useConnection('default', ['token' => TEST_TOKEN, 'locale' => 'de', 'transport' => 'laravel']);
        Http::fake(['*' => Http::response(mePayload())]);

        $client = LivckCloud::withToken(CUSTOMER_TOKEN);
        $client->me();
        LivckCloud::me();

        expect($client->options()->baseUri)->toBe('https://api.livck.cloud/v1')
            ->and($client->options()->locale)->toBe('de')
            ->and(sentAuthorizations())->toBe([['Bearer ' . CUSTOMER_TOKEN], ['Bearer ' . TEST_TOKEN]]);
    });

    it('sends with the settings of a named connection, which needs no token of its own', function (): void {
        useConnection('customer-b', ['base_uri' => 'https://b.example.test/v1', 'locale' => 'fr', 'transport' => 'laravel']);
        Http::fake(['*' => Http::response(mePayload())]);

        LivckCloud::withToken(CUSTOMER_TOKEN, 'customer-b')->me();

        Http::assertSent(fn(Request $request): bool => $request->url() === 'https://b.example.test/v1/me'
            && $request->header('Accept-Language') === ['fr']
            && $request->header('Authorization') === ['Bearer ' . CUSTOMER_TOKEN]);
    });

    it('refuses a blank token instead of falling back to the connection', function (string $blank): void {
        useConnection('default', ['token' => TEST_TOKEN]);

        expect(fn(): CloudClientInterface => LivckCloud::withToken($blank))
            ->toThrow(MissingTokenException::class, 'LivckCloud::withToken() got a blank API token. It is refused rather than replaced by a configured one, so that a request cannot reach the wrong organization.');
    })->with(['empty' => [''], 'blank' => [" \t "]]);

    it('refuses an unknown connection', function (): void {
        expect(fn(): CloudClientInterface => LivckCloud::withToken(CUSTOMER_TOKEN, 'customer-x'))
            ->toThrow(ConfigurationException::class, 'The LIVCK Cloud connection [customer-x] is not configured. config/livck-cloud.php defines [default].');
    });

    it('refuses a token the API could never accept, without repeating it', function (): void {
        expect(fn(): CloudClientInterface => LivckCloud::withToken('lvk_with space'))
            ->toThrow(ConfigurationException::class, 'token passed to LivckCloud::withToken() is not usable: The API token must consist of printable ASCII characters without whitespace.');
    });
});

describe('lifetime', function (): void {
    it('builds a new client on every call and keeps none of them', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);
        $default = LivckCloud::connection();

        $clients = [LivckCloud::withToken(CUSTOMER_TOKEN), LivckCloud::withToken(CUSTOMER_TOKEN), LivckCloud::build([]), LivckCloud::build([])];
        $references = array_map(WeakReference::create(...), $clients);

        expect($clients[0])->not->toBe($clients[1])
            ->and($clients[2])->not->toBe($clients[3])
            ->and($clients[3])->toBeInstanceOf(CloudClient::class)
            ->and($clients)->not->toContain($default);

        unset($clients);
        gc_collect_cycles();

        expect(array_map(static fn(WeakReference $reference): ?object => $reference->get(), $references))->toBe([null, null, null, null])
            ->and(LivckCloud::connection())->toBe($default)
            ->and(app(CloudClientInterface::class))->toBe($default);
    });

    it('sends the application locale of the moment with locale: app', function (): void {
        useConnection('default', ['token' => TEST_TOKEN, 'locale' => 'app']);
        useConnection('customer-b', ['locale' => 'de']);

        app()->setLocale('fr');
        $clients = [
            LivckCloud::withToken(CUSTOMER_TOKEN),
            LivckCloud::build(['token' => CUSTOMER_TOKEN]),
            LivckCloud::withToken(CUSTOMER_TOKEN, 'customer-b'),
            LivckCloud::build(['locale' => 'en']),
        ];
        app()->setLocale('nl');

        expect(array_map(static fn(CloudClientInterface $client): ?string => $client->options()->locale, $clients))->toBe(['fr', 'fr', 'de', 'en'])
            ->and(LivckCloud::withToken(CUSTOMER_TOKEN)->options()->locale)->toBe('nl');
    });
});

describe('secrecy', function (): void {
    it('keeps the token out of dumps and refuses to serialize it', function (Closure $build): void {
        useConnection('default', ['token' => TEST_TOKEN]);

        $client = $build();

        expect(print_r($client, true))->not->toContain(CUSTOMER_TOKEN)
            ->and(fn(): string => serialize($client))->toThrow(LogicException::class, 'An API token must not be serialized.');
    })->with([
        'withToken()' => [fn(): CloudClientInterface => LivckCloud::withToken(CUSTOMER_TOKEN)],
        'build()' => [fn(): CloudClientInterface => LivckCloud::build(['token' => CUSTOMER_TOKEN])],
    ]);

    it('keeps the token out of an exception, its trace and the exceptions before it', function (Closure $call): void {
        useConnection('default', ['token' => TEST_TOKEN]);

        $e = thrownWithArguments($call);
        $arguments = recordedArguments($e);

        // The arguments were recorded, the token's as a SensitiveParameterValue.
        expect($e)->toBeInstanceOf(ConfigurationException::class)
            ->and($arguments)->toContain(SensitiveParameterValue::class)
            ->and($arguments)->not->toContain('Cust0mer')
            ->and((string) $e)->not->toContain('Cust0mer');
    })->with([
        'unknown key' => [fn(): CloudClientInterface => LivckCloud::build(['tokn' => CUSTOMER_TOKEN])],
        'wrong value beside it' => [fn(): CloudClientInterface => LivckCloud::build(['token' => CUSTOMER_TOKEN, 'timeout' => 'soon'])],
        'option the SDK refuses' => [fn(): CloudClientInterface => LivckCloud::build(['token' => CUSTOMER_TOKEN, 'base_uri' => 'http://api.example.com/v1'])],
        'unknown connection' => [fn(): CloudClientInterface => LivckCloud::withToken(CUSTOMER_TOKEN, 'customer-x')],
        'unusable token' => [fn(): CloudClientInterface => LivckCloud::withToken(CUSTOMER_TOKEN . "\u{00e4}")],
    ]);
});
