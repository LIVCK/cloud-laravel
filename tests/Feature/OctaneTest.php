<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

/**
 * One request the way Octane serves it: a clone of the booted application with its own
 * configuration, made the current container for the duration of the request.
 *
 * @template T
 *
 * @param Closure(Application): T $handle
 * @return T
 */
function inOctaneRequest(Application $worker, Closure $handle): mixed
{
    $sandbox = clone $worker;
    $sandbox->instance('config', clone $worker->make(Repository::class));
    Container::setInstance($sandbox);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($sandbox);

    try {
        return $handle($sandbox);
    } finally {
        Container::setInstance($worker);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($worker);
    }
}

it('sends each request the locale of its own application', function (): void {
    useConnection('default', ['locale' => 'app']);
    $worker = app();
    $manager = $worker->make(CloudManager::class); // resolved while the worker boots, then shared
    $fake = $manager->fake(array_fill(0, 4, MockResponse::json(mePayload())));

    inOctaneRequest($worker, function (Application $app): void {
        $app->setLocale('de');
        LivckCloud::me();
        $app->make(CloudClientInterface::class)->me();
    });

    inOctaneRequest($worker, function (Application $app): void {
        $app->setLocale('fr');
        LivckCloud::me();
    });

    // A request that sets no locale gets the application's default, not the last request's.
    inOctaneRequest($worker, function (): void {
        LivckCloud::me();
    });

    expect(array_map(static fn(RecordedRequest $request): ?string => $request->header('Accept-Language'), $fake->recorded()))
        ->toBe(['de', 'de', 'fr', 'en'])
        ->and($worker->getLocale())->toBe('en');
});

it('keeps no locale in the shared client', function (): void {
    useConnection('default', ['locale' => 'app']);
    $manager = app(CloudManager::class);
    $manager->fake();

    app()->setLocale('de');
    $first = $manager->connection();
    $second = $manager->connection();

    expect($first)->not->toBe($second)
        ->and($first->options()->locale)->toBe('de');

    app()->setLocale('fr');

    expect($first->options()->locale)->toBe('de')
        ->and($manager->connection()->options()->locale)->toBe('fr');
});

it('shares one client across requests when the locale is fixed', function (): void {
    useConnection('default', ['locale' => 'de', 'token' => TEST_TOKEN]);
    $worker = app();
    $manager = $worker->make(CloudManager::class);

    $clients = [
        inOctaneRequest($worker, fn(Application $app): CloudClientInterface => $app->make(CloudClientInterface::class)),
        inOctaneRequest($worker, function (Application $app): CloudClientInterface {
            $app->setLocale('fr');

            return LivckCloud::connection();
        }),
    ];

    expect($clients[0])->toBe($clients[1])
        ->and($clients[0])->toBe($manager->connection())
        ->and($clients[0]->options()->locale)->toBe('de');
});

it('never lets the token of one request reach another', function (): void {
    useConnection('default', ['token' => TEST_TOKEN, 'transport' => 'laravel']);
    Http::fake(['*' => Http::response(mePayload())]);
    $worker = app();
    $worker->make(CloudManager::class); // resolved while the worker boots, then shared

    $first = inOctaneRequest($worker, function (): CloudClientInterface {
        $client = LivckCloud::withToken('lvk_CustomerOfTheFirstRequest000000');
        $client->me();

        return $client;
    });

    $second = inOctaneRequest($worker, function (): CloudClientInterface {
        $client = LivckCloud::build(['token' => 'lvk_CustomerOfTheSecondRequest00000']);
        $client->me();
        LivckCloud::me();

        return $client;
    });

    expect($first)->not->toBe($second)
        ->and(sentAuthorizations())->toBe([
            ['Bearer lvk_CustomerOfTheFirstRequest000000'],
            ['Bearer lvk_CustomerOfTheSecondRequest00000'],
            ['Bearer ' . TEST_TOKEN],
        ]);

    // Once the requests are over, the worker holds neither client.
    $references = [WeakReference::create($first), WeakReference::create($second)];
    unset($first, $second);
    gc_collect_cycles();

    expect($references[0]->get())->toBeNull()
        ->and($references[1]->get())->toBeNull();
});

it('reads the configuration of the request it serves', function (): void {
    useConnection('default', ['token' => TEST_TOKEN]);
    $worker = app();
    $manager = $worker->make(CloudManager::class);

    $default = inOctaneRequest($worker, function (Application $app): string {
        $app->make(Repository::class)->set('livck-cloud.default', 'customer-b');

        return LivckCloud::getDefaultConnection();
    });

    expect($default)->toBe('customer-b')
        ->and($manager->getDefaultConnection())->toBe('default');
});
