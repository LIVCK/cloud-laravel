<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use LIVCK\Cloud\Laravel\Tests\Fixtures\CustomerStatusController;
use LIVCK\Cloud\Testing\ExpectationFailedException;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use Psr\Http\Message\RequestInterface;

describe('reach', function (): void {
    it('fakes the facade', function (): void {
        $fake = LivckCloud::fake([MockResponse::json(['data' => tagPayload()], 201)]);

        $ensured = LivckCloud::tags()->ensure('customer', '4711');

        expect($fake)->toBeInstanceOf(CloudFake::class)
            ->and($ensured->wasCreated())->toBeTrue();

        $fake->assertSent(fn(RecordedRequest $request): bool => $request->matches('POST', '/v1/tags/ensure')
            && $request->json() === ['key' => 'customer', 'value' => '4711']);
    });

    it('fakes an injected client', function (): void {
        $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

        $me = app(CloudClientInterface::class)->me();

        expect($me->organization->name)->toBe('Example Hosting');
        $fake->assertSent(fn(RecordedRequest $request, string $connection): bool => $request->matches('GET', '/v1/me') && $connection === 'default');
    });

    it('fakes a client injected into a controller', function (): void {
        Route::get('/customer-status', CustomerStatusController::class);
        $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

        $response = app(Kernel::class)->handle(Request::create('/customer-status'));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('{"organization":"Example Hosting"}');
        $fake->assertSentCount(1);
    });

    it('fakes named connections and tells them apart', function (): void {
        useConnection('customer-b', ['base_uri' => 'https://b.example.test/v1', 'locale' => 'de']);
        $fake = LivckCloud::fake([MockResponse::json(mePayload()), MockResponse::json(mePayload())]);

        LivckCloud::me();
        LivckCloud::connection('customer-b')->me();

        expect($fake->recorded())->toHaveCount(2)
            ->and($fake->recorded('customer-b'))->toHaveCount(1)
            ->and($fake->recorded('customer-b')[0]->uri)->toBe('https://b.example.test/v1/me')
            ->and($fake->recorded('customer-b')[0]->header('Accept-Language'))->toBe('de')
            ->and($fake->recorded('default')[0]->uri)->toBe('https://api.livck.cloud/v1/me')
            ->and($fake->recorded('customer-x'))->toBe([])
            ->and($fake->connectionOf($fake->recorded()[1]))->toBe('customer-b');

        $fake->assertSent(fn(RecordedRequest $request, string $connection): bool => $connection === 'customer-b')
            ->assertNotSent(fn(RecordedRequest $request, string $connection): bool => $connection === 'customer-x');
    });

    it('needs no token and never sends a configured one', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);
        $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

        LivckCloud::me();

        expect($fake->lastRequest()?->header('Authorization'))->toBe('Bearer ' . CloudFake::TOKEN);
    });

    it('replaces clients built before it', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);
        $real = LivckCloud::connection();

        LivckCloud::fake();

        expect(LivckCloud::connection())->not->toBe($real);
    });

    it('starts over when faked again', function (): void {
        $first = LivckCloud::fake([MockResponse::json(mePayload())]);
        LivckCloud::me();

        $second = LivckCloud::fake([MockResponse::json(mePayload())]);
        LivckCloud::me();

        expect($second)->not->toBe($first)
            ->and($first->recorded())->toHaveCount(1)
            ->and($second->recorded())->toHaveCount(1);
    });
});

describe('strictness', function (): void {
    it('fails loudly on a request nobody queued a response for', function (): void {
        useConnection('customer-b');
        $fake = LivckCloud::fake();

        expect(fn(): Me => LivckCloud::connection('customer-b')->me())
            ->toThrow(LogicException::class, 'FakeHttpClient has no response left for GET https://api.livck.cloud/v1/me (request #1).')
            ->and($fake->connectionOf($fake->recorded()[0]))->toBe('customer-b');
    });

    it('sends nothing without a fake when a token is missing, and says why', function (): void {
        expect(fn(): Me => LivckCloud::me())->toThrow(LIVCK\Cloud\Laravel\Exceptions\MissingTokenException::class);
    });
});

describe('assertions', function (): void {
    it('passes when the expectation holds', function (): void {
        $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

        LivckCloud::me();

        $fake->assertSent(fn(RecordedRequest $request): bool => $request->matches('GET', '/v1/me'))
            ->assertNotSent(fn(RecordedRequest $request): bool => $request->method === 'POST')
            ->assertSentCount(1)
            ->assertNoPendingResponses();

        LivckCloud::assertSent(fn(RecordedRequest $request): bool => $request->matches('GET', '/v1/me'))
            ->assertSentCount(1);
        LivckCloud::assertNotSent(fn(RecordedRequest $request): bool => $request->method === 'DELETE');
        LivckCloud::assertSentCount(1);
    });

    it('passes when nothing was sent', function (): void {
        LivckCloud::fake()->assertNothingSent();
        LivckCloud::assertNothingSent();
    });

    it('fails with what was sent', function (Closure $assertion, string $message): void {
        LivckCloud::fake([MockResponse::json(mePayload())]);
        LivckCloud::me();

        expect($assertion)->toThrow(ExpectationFailedException::class, $message);
    })->with([
        'sent' => [fn(): CloudFake => LivckCloud::assertSent(fn(RecordedRequest $request): bool => $request->method === 'POST'), 'No request matched the expectation. Sent: GET https://api.livck.cloud/v1/me'],
        'not sent' => [fn(): CloudFake => LivckCloud::assertNotSent(fn(RecordedRequest $request): bool => $request->method === 'GET'), 'A request matched an expectation that should not have been met: GET https://api.livck.cloud/v1/me'],
        'count' => [fn(): CloudFake => LivckCloud::assertSentCount(2), 'Expected 2 request(s), 1 were sent'],
        'nothing' => [fn(): CloudFake => LivckCloud::assertNothingSent(), 'Expected no request, 1 were sent'],
        'message' => [fn(): CloudFake => LivckCloud::assertSentCount(3, 'three calls expected'), 'three calls expected'],
    ]);

    it('fails when queued responses were never requested', function (): void {
        $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

        expect(fn(): CloudFake => $fake->assertNoPendingResponses())
            ->toThrow(ExpectationFailedException::class, '1 queued response(s) were never requested.');
    });

    it('needs a fake to assert against', function (): void {
        expect(fn(): CloudFake => app(CloudManager::class)->assertSentCount(0))
            ->toThrow(LogicException::class, 'LivckCloud::fake() has not been called, so no requests were recorded.');
    });
});

describe('queue and inspection', function (): void {
    it('answers queued responses, closures included, and counts what is left', function (): void {
        $fake = LivckCloud::fake();
        $fake->queue(
            MockResponse::json(mePayload()),
            fn(RequestInterface $request): MockResponse => MockResponse::json(mePayload(['organization' => ['public_id' => 'o2', 'name' => $request->getMethod()]])),
        );

        expect($fake->remaining())->toBe(2)
            ->and(LivckCloud::me()->organization->name)->toBe('Example Hosting')
            ->and(LivckCloud::me()->organization->name)->toBe('GET')
            ->and($fake->remaining())->toBe(0)
            ->and($fake->http()->recorded())->toBe($fake->recorded());
    });

    it('retries without sleeping and shows the waits', function (): void {
        $fake = LivckCloud::fake([
            MockResponse::error('Service Unavailable', 503)->withRetryAfter(2),
            MockResponse::json(mePayload()),
        ]);

        LivckCloud::me();

        expect($fake->recorded())->toHaveCount(2)
            ->and($fake->delays())->toBe([2.0]);
    });

    it('has no connection for a request sent past the connections', function (): void {
        $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

        $fake->http()->sendRequest(new GuzzleHttp\Psr7\Request('GET', 'https://api.livck.cloud/v1/me'));
        $recorded = $fake->lastRequest();

        expect($recorded)->not->toBeNull()
            ->and($recorded instanceof RecordedRequest ? $fake->connectionOf($recorded) : 'missing')->toBeNull();

        $fake->assertSent(fn(RecordedRequest $request, string $connection): bool => $connection === '');
    });
});
