<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue as QueueFacade;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Tests\Fixtures\EnsureCustomerTag;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

it('runs a queued job with the client injected into handle()', function (): void {
    config()->set('queue.default', 'sync');
    $fake = LivckCloud::fake([MockResponse::json(['data' => tagPayload()], 201)]);

    app(Dispatcher::class)->dispatch(new EnsureCustomerTag('4711'));

    $fake->assertSentCount(1)
        ->assertSent(fn(RecordedRequest $request): bool => $request->matches('POST', '/v1/tags/ensure')
            && $request->json() === ['key' => 'customer', 'value' => '4711']
            && $request->idempotencyKey() !== null);
});

it('queues nothing of the client, only the job data', function (): void {
    useConnection('default', ['token' => TEST_TOKEN]);
    QueueFacade::fake();

    EnsureCustomerTag::dispatch('4711');

    QueueFacade::assertPushed(EnsureCustomerTag::class, function (EnsureCustomerTag $job): bool {
        $payload = serialize($job);

        return $job->customer === '4711'
            && ! str_contains($payload, 'CloudClient')
            && ! str_contains($payload, TEST_TOKEN);
    });
});

it('serves jobs of a long-running worker with one client', function (): void {
    config()->set('queue.default', 'sync');
    $fake = LivckCloud::fake([
        MockResponse::json(['data' => tagPayload()], 201),
        MockResponse::json(['data' => tagPayload(['value' => '4712'])], 201),
    ]);
    $client = LivckCloud::connection();

    app(Dispatcher::class)->dispatch(new EnsureCustomerTag('4711'));
    app(Dispatcher::class)->dispatch(new EnsureCustomerTag('4712'));

    expect(LivckCloud::connection())->toBe($client)
        ->and(array_map(static fn(RecordedRequest $request): mixed => $request->json()['value'] ?? null, $fake->recorded()))->toBe(['4711', '4712']);
});
