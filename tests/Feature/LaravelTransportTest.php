<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Exceptions\TransportException;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Http\LaravelHttpClient;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use LIVCK\Cloud\Testing\MockResponse;
use Psr\Http\Message\RequestInterface;

beforeEach(function (): void {
    useConnection('default', ['token' => TEST_TOKEN, 'transport' => 'laravel']);
});

/**
 * The HTTP client events dispatched while the callback runs, by short class name.
 *
 * @param Closure(): mixed $callback
 * @return list<string>
 */
function httpEvents(Closure $callback): array
{
    $events = new ArrayObject();

    foreach ([RequestSending::class, ResponseReceived::class, ConnectionFailed::class] as $event) {
        Event::listen($event, static function (object $dispatched) use ($events): void {
            $events->append(class_basename($dispatched));
        });
    }

    try {
        $callback();
    } finally {
        /** @var list<string> $names */
        $names = array_values($events->getArrayCopy());
    }

    return $names;
}

/**
 * A client over Laravel's stack whose innermost handler answers from a queue instead of cURL.
 *
 * @param list<PsrResponse|Throwable> $queue
 * @return array{0: CloudClient, 1: MockHandler}
 */
function laravelStackClient(array $queue, int $maxRetries = 0): array
{
    $handler = new MockHandler($queue);
    $client = new CloudClient(
        TEST_TOKEN,
        new ClientOptions(maxRetries: $maxRetries, backoffBase: 0.001, backoffCap: 0.001),
        new LaravelHttpClient(static fn(): Factory => app(Factory::class), 7.5, 2.5, $handler),
    );

    return [$client, $handler];
}

it('sends through Laravel when the connection asks for it', function (): void {
    $client = LivckCloud::connection();

    expect($client instanceof CloudClient ? $client->__debugInfo()['httpClient'] : null)->toBe(LaravelHttpClient::class);
});

it('lets Http::fake() answer the SDK and Http::assertSent() see it', function (): void {
    Http::fake(['api.livck.cloud/v1/me' => Http::response(mePayload())]);

    $me = LivckCloud::me();

    expect($me->organization->name)->toBe('Example Hosting');

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.livck.cloud/v1/me'
        && $request->method() === 'GET'
        && $request->header('User-Agent') === [sprintf('livck-cloud-php/%s PHP/%s livck-cloud-laravel/1.1.0 Laravel/%s', CloudClient::VERSION, PHP_VERSION, app()->version())]
        && $request->header('Authorization') === ['Bearer ' . TEST_TOKEN]);
    Http::assertSentCount(1);
});

it('sees a fake that came after the client was built', function (): void {
    $client = LivckCloud::connection();

    Http::fake(['*' => Http::response(mePayload(['organization' => ['public_id' => 'o', 'name' => 'Faked later']]))]);

    expect($client->me()->organization->name)->toBe('Faked later');
});

it('refuses stray requests when Laravel is told to', function (): void {
    Http::preventStrayRequests();

    expect(fn(): Me => LivckCloud::me())->toThrow(RuntimeException::class, 'https://api.livck.cloud/v1/me');
});

it('fires the HTTP client events, faked or not', function (): void {
    Http::fake(['*' => Http::response(mePayload())]);

    expect(httpEvents(fn(): Me => LivckCloud::me()))->toBe(['RequestSending', 'ResponseReceived']);
});

it('hands the response to ResponseReceived listeners without using it up', function (): void {
    Http::fake(['*' => Http::response(mePayload())]);
    $seen = new ArrayObject();
    Event::listen(ResponseReceived::class, static function (ResponseReceived $event) use ($seen): void {
        $seen->append([$event->request->url(), $event->response->status(), $event->response->json('organization.name')]);
    });

    $me = LivckCloud::me();

    expect($seen->getArrayCopy())->toBe([['https://api.livck.cloud/v1/me', 200, 'Example Hosting']])
        ->and($me->organization->name)->toBe('Example Hosting');
});

it('runs global middleware, where Pulse records outgoing requests', function (): void {
    $seen = new ArrayObject();
    Http::globalMiddleware(static fn(callable $handler): Closure => static function (RequestInterface $request, array $options) use ($handler, $seen): mixed {
        $seen->append((string) $request->getUri());

        return $handler($request, $options);
    });
    Http::fake(['*' => Http::response(mePayload())]);

    LivckCloud::me();

    expect($seen->getArrayCopy())->toBe(['https://api.livck.cloud/v1/me']);
});

it('reaches the network handler with the connection timeouts when nothing is faked', function (): void {
    [$client, $handler] = laravelStackClient([new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(mePayload()))]);

    $events = httpEvents(fn(): Me => $client->me());
    $options = $handler->getLastOptions();

    expect($events)->toBe(['RequestSending', 'ResponseReceived'])
        ->and($options['timeout'] ?? null)->toBe(7.5)
        ->and($options['connect_timeout'] ?? null)->toBe(2.5)
        ->and($options['allow_redirects'] ?? null)->toBeFalse()
        ->and($options['http_errors'] ?? null)->toBeFalse()
        ->and($options['laravel_data'] ?? null)->toBe([]);
});

it('reports a failed connection as the SDK does', function (): void {
    $refused = static fn(): ConnectException => new ConnectException('Connection refused', new PsrRequest('GET', 'https://api.livck.cloud/v1/me'));
    [$client] = laravelStackClient([$refused(), $refused()], maxRetries: 1);

    expect(fn(): Me => $client->me())
        ->toThrow(TransportException::class, 'Connection refused (GET https://api.livck.cloud/v1/me, 2 attempts)');
});

it('fires ConnectionFailed for every attempt that got no answer', function (): void {
    [$client] = laravelStackClient([
        new ConnectException('Connection refused', new PsrRequest('GET', 'https://api.livck.cloud/v1/me')),
        new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(mePayload())),
    ], maxRetries: 1);
    $failures = new ArrayObject();
    Event::listen(ConnectionFailed::class, static function (ConnectionFailed $event) use ($failures): void {
        $failures->append([$event->request->url(), $event->exception->getMessage(), $event->exception->getPrevious()]);
    });

    $me = $client->me();

    expect($me->organization->name)->toBe('Example Hosting')
        ->and($failures->getArrayCopy())->toBe([['https://api.livck.cloud/v1/me', 'Connection refused', null]]);
});

it('leaves the stage to LivckCloud::fake()', function (): void {
    Http::preventStrayRequests();
    $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

    LivckCloud::me();

    $fake->assertSentCount(1);
    Http::assertNothingSent();
});

it('sends a client built on demand the way its settings say', function (): void {
    useConnection('customer-b', ['transport' => 'sdk']);
    Http::fake(['*' => Http::response(mePayload())]);
    $httpClientOf = static fn(CloudClientInterface $client): ?string => $client instanceof CloudClient ? $client->__debugInfo()['httpClient'] : null;

    $client = LivckCloud::withToken(CUSTOMER_TOKEN);
    $client->me();

    expect($httpClientOf($client))->toBe(LaravelHttpClient::class)
        ->and($httpClientOf(LivckCloud::build(['token' => CUSTOMER_TOKEN])))->toBe(LaravelHttpClient::class)
        ->and($httpClientOf(LivckCloud::build(['transport' => 'sdk'])))->toBe(GuzzleHttp\Client::class)
        ->and($httpClientOf(LivckCloud::withToken(CUSTOMER_TOKEN, 'customer-b')))->toBe(GuzzleHttp\Client::class);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.livck.cloud/v1/me'
        && $request->header('User-Agent') === [sprintf('livck-cloud-php/%s PHP/%s livck-cloud-laravel/1.1.0 Laravel/%s', CloudClient::VERSION, PHP_VERSION, app()->version())]
        && $request->header('Authorization') === ['Bearer ' . CUSTOMER_TOKEN]);
    Http::assertSentCount(1);
});

it('leaves clients built on demand to LivckCloud::fake() as well', function (): void {
    Http::preventStrayRequests();
    $fake = LivckCloud::fake([MockResponse::json(mePayload()), MockResponse::json(mePayload())]);

    LivckCloud::withToken(CUSTOMER_TOKEN)->me();
    LivckCloud::build(['transport' => 'laravel'])->me();

    expect($fake->recorded(CloudFake::ON_DEMAND))->toHaveCount(2);
    Http::assertNothingSent();
});
