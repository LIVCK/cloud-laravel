<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue as QueueFacade;
use Illuminate\Support\Facades\Schema;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Customer;
use LIVCK\Cloud\Laravel\Tests\Fixtures\EnsureCustomerTag;
use LIVCK\Cloud\Laravel\Tests\Fixtures\ListCustomerServices;
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

it('serves each customer with the token from their record', function (): void {
    useConnection('default', ['token' => TEST_TOKEN, 'transport' => 'laravel']);
    config()->set('queue.default', 'sync');
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    Schema::create('customers', static function (Blueprint $table): void {
        $table->id();
        $table->text('livck_token');
    });
    $customers = [
        Customer::query()->create(['livck_token' => CUSTOMER_TOKEN]),
        Customer::query()->create(['livck_token' => 'lvk_AnotherCustomerOfTheReseller000']),
    ];
    Http::fake(['*' => Http::response(MockResponse::page([])->body, 200, ['Content-Type' => 'application/json'])]);
    $payloads = new ArrayObject();
    QueueFacade::before(static function (JobProcessing $event) use ($payloads): void {
        $payloads->append($event->job->getRawBody());
    });

    foreach ($customers as $customer) {
        app(Dispatcher::class)->dispatch(new ListCustomerServices($customer));
    }

    $queued = implode("\n", $payloads->getArrayCopy());

    // The worker read each token from the database; the queue and the table never held one in plain text.
    expect(sentAuthorizations())->toBe([['Bearer ' . CUSTOMER_TOKEN], ['Bearer lvk_AnotherCustomerOfTheReseller000']])
        ->and($payloads)->toHaveCount(2)
        ->and($queued)->toContain('ModelIdentifier')
        ->and($queued)->not->toContain('lvk_')
        ->and(DB::table('customers')->pluck('livck_token')->implode("\n"))->not->toContain('lvk_');
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
