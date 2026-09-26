<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('reaches every resource of the default connection', function (Closure $call, string $method, string $path): void {
    useConnection('customer-b', ['base_uri' => 'https://b.example.test/v1']);
    $fake = LivckCloud::fake([MockResponse::page([])]);

    $call();

    $fake->assertSentCount(1)
        ->assertSent(fn(RecordedRequest $request, string $connection): bool => $request->matches($method, $path)
            && str_starts_with($request->uri, 'https://api.livck.cloud/')
            && $connection === 'default');
})->with([
    'tags' => [fn() => LivckCloud::tags()->list(), 'GET', '/v1/tags'],
    'statuspages' => [fn() => LivckCloud::statuspages()->list(), 'GET', '/v1/statuspages'],
    'services' => [fn() => LivckCloud::services()->list(), 'GET', '/v1/services'],
    'incidents' => [fn() => LivckCloud::incidents()->list(), 'GET', '/v1/incidents'],
    'maintenances' => [fn() => LivckCloud::maintenances()->list(), 'GET', '/v1/maintenances'],
    'request()' => [fn() => LivckCloud::request('GET', 'oncall/on-call'), 'GET', '/v1/oncall/on-call'],
    'send()' => [fn() => LivckCloud::send(Request::get('oncall/on-call')), 'GET', '/v1/oncall/on-call'],
]);

it('answers the discovery calls of the default connection', function (): void {
    LivckCloud::fake([
        MockResponse::json(mePayload()),
        MockResponse::json(probesPayload()),
        MockResponse::json(catalogPayload()),
    ]);

    expect(LivckCloud::me()->organization->name)->toBe('Example Hosting')
        ->and(array_map(static fn(Probe $probe): string => $probe->code, LivckCloud::probes()))->toBe(['ffm', 'hel'])
        ->and(LivckCloud::checkTypes()->keys())->toBe(['http']);
});

it('passes query, body and headers of request() through', function (): void {
    $fake = LivckCloud::fake([MockResponse::json(['data' => []], 201)]);

    LivckCloud::request('POST', 'services/abc/notes', ['page' => 2], ['text' => 'hello'], ['Idempotency-Key' => 'note-1', 'X-Trace' => 't-1']);

    $sent = $fake->lastRequest();

    expect($sent?->query())->toBe(['page' => '2'])
        ->and($sent?->json())->toBe(['text' => 'hello'])
        ->and($sent?->idempotencyKey())->toBe('note-1')
        ->and($sent?->header('X-Trace'))->toBe('t-1');
});

it('derives copies of the default connection and leaves it as it is', function (): void {
    $fake = LivckCloud::fake([MockResponse::json(mePayload()), MockResponse::json(mePayload()), MockResponse::json(mePayload())]);

    LivckCloud::withLocale('en')->me();
    LivckCloud::withOptions(LivckCloud::options()->withLocale('fr'))->me();
    LivckCloud::me();

    expect(array_map(static fn(RecordedRequest $request): ?string => $request->header('Accept-Language'), $fake->recorded()))
        ->toBe(['en', 'fr', null])
        ->and(LivckCloud::options())->toBeInstanceOf(ClientOptions::class)
        ->and(LivckCloud::options()->locale)->toBeNull();
});

it('lets a copy talk through another HTTP client', function (): void {
    $http = new class implements ClientInterface {
        public ?RequestInterface $request = null;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->request = $request;

            return new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(mePayload()));
        }
    };
    $fake = LivckCloud::fake();

    $me = LivckCloud::withHttpClient($http)->me();

    expect($me->organization->name)->toBe('Example Hosting')
        ->and($http->request?->getUri()->getPath())->toBe('/v1/me');

    $fake->assertNothingSent();
});

it('reaches a named connection', function (): void {
    useConnection('customer-b', ['base_uri' => 'https://b.example.test/v1']);
    $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

    LivckCloud::connection('customer-b')->me();

    expect($fake->lastRequest()?->uri)->toBe('https://b.example.test/v1/me');
});

it('forwards what it does not declare to the default connection', function (): void {
    LivckCloud::fake();

    // __debugInfo() stands in for a method a newer SDK adds: CloudClient has it, the manager does not.
    $info = LivckCloud::__callStatic('__debugInfo', []);

    expect(is_array($info) ? $info['token'] ?? null : null)->toBe('[redacted]');
});

it('reports a method nobody has under its own name', function (): void {
    LivckCloud::fake();

    expect(fn(): mixed => app(CloudManager::class)->__call('doesNotExist', []))
        ->toThrow(BadMethodCallException::class, 'Call to undefined method LIVCK\Cloud\Laravel\CloudManager::doesNotExist()');
});

it('hands out the client interface for injection', function (): void {
    LivckCloud::fake();

    expect(LivckCloud::connection())->toBeInstanceOf(CloudClientInterface::class);
});
