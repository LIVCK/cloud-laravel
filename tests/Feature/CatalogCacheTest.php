<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

/**
 * Collects the cache writes from here on: key, seconds and store of each.
 *
 * @return ArrayObject<int, array{key: string, seconds: int|null, store: string|null}>
 */
function cacheWrites(): ArrayObject
{
    /** @var ArrayObject<int, array{key: string, seconds: int|null, store: string|null}> $writes */
    $writes = new ArrayObject();
    Event::listen(KeyWritten::class, static function (KeyWritten $event) use ($writes): void {
        $writes->append(['key' => $event->key, 'seconds' => $event->seconds, 'store' => $event->storeName]);
    });

    return $writes;
}

it('asks the API once and answers from the cache after that', function (): void {
    $fake = LivckCloud::fake([MockResponse::json(catalogPayload())]);

    $first = LivckCloud::catalog();
    $second = LivckCloud::catalog();

    expect($first)->toBeInstanceOf(CheckTypeCatalog::class)
        ->and($second->keys())->toBe(['http'])
        ->and($second->type('http')->label)->toBe('HTTP/HTTPS')
        ->and($second->type('http')->interval?->min)->toBe(30)
        ->and($second->raw)->toBe($first->raw);

    $fake->assertSentCount(1)
        ->assertSent(fn(RecordedRequest $request): bool => $request->matches('GET', '/v1/meta/check-types'));
});

it('asks again once the time to live has passed', function (): void {
    config()->set('livck-cloud.catalog_cache.ttl', 60);
    $fake = LivckCloud::fake([MockResponse::json(catalogPayload('before')), MockResponse::json(catalogPayload('after'))]);

    expect(LivckCloud::catalog()->type('http')->label)->toBe('before');

    Carbon::setTestNow(Carbon::now()->addSeconds(59));

    expect(LivckCloud::catalog()->type('http')->label)->toBe('before');

    Carbon::setTestNow(Carbon::now()->addSeconds(2));

    expect(LivckCloud::catalog()->type('http')->label)->toBe('after');
    $fake->assertSentCount(2);
});

it('keeps one catalog per connection', function (): void {
    useConnection('customer-b');
    $fake = LivckCloud::fake([MockResponse::json(catalogPayload('default')), MockResponse::json(catalogPayload('customer-b'))]);

    expect(LivckCloud::catalog()->type('http')->label)->toBe('default')
        ->and(LivckCloud::catalog('customer-b')->type('http')->label)->toBe('customer-b')
        ->and(LivckCloud::catalog('default')->type('http')->label)->toBe('default')
        ->and(LivckCloud::catalog('customer-b')->type('http')->label)->toBe('customer-b')
        ->and($fake->recorded('default'))->toHaveCount(1)
        ->and($fake->recorded('customer-b'))->toHaveCount(1);
});

it('keeps one catalog per base URI', function (): void {
    $fake = LivckCloud::fake([MockResponse::json(catalogPayload('production')), MockResponse::json(catalogPayload('staging'))]);

    expect(LivckCloud::catalog()->type('http')->label)->toBe('production');

    useConnection('default', ['base_uri' => 'https://staging.example.test/v1']);
    LivckCloud::purge();

    expect(LivckCloud::catalog()->type('http')->label)->toBe('staging');
    $fake->assertSentCount(2);
});

it('keeps one catalog per locale of a connection that follows the application', function (): void {
    useConnection('default', ['locale' => 'app']);
    $fake = LivckCloud::fake([MockResponse::json(catalogPayload('Überwachung')), MockResponse::json(catalogPayload('Monitoring'))]);

    app()->setLocale('de');
    $german = LivckCloud::catalog()->type('http')->label;
    app()->setLocale('en');
    $english = LivckCloud::catalog()->type('http')->label;
    app()->setLocale('de');

    expect([$german, $english, LivckCloud::catalog()->type('http')->label])->toBe(['Überwachung', 'Monitoring', 'Überwachung'])
        ->and(array_map(static fn(RecordedRequest $request): ?string => $request->header('Accept-Language'), $fake->recorded()))->toBe(['de', 'en']);
});

it('keys the cache without the token', function (): void {
    useConnection('default', ['token' => TEST_TOKEN]);
    $writes = cacheWrites();
    LivckCloud::fake([MockResponse::json(catalogPayload())]);

    LivckCloud::catalog();

    $write = $writes->getArrayCopy()[0] ?? null;

    expect($writes)->toHaveCount(1)
        ->and($write['key'] ?? null)->toMatch('/\Alivck-cloud:catalog:[0-9a-f]{32}\z/')
        ->and($write['key'] ?? null)->not->toContain('lvk_')
        ->and($write['seconds'] ?? null)->toBe(3600)
        ->and($write['store'] ?? null)->toBe('array');
});

it('writes to the configured store', function (): void {
    config()->set('cache.stores.livck-catalog', ['driver' => 'array']);
    config()->set('livck-cloud.catalog_cache.store', 'livck-catalog');
    $writes = cacheWrites();
    LivckCloud::fake([MockResponse::json(catalogPayload())]);

    LivckCloud::catalog();

    expect($writes->getArrayCopy()[0]['store'] ?? null)->toBe('livck-catalog');
});

it('uses the default store when none is named', function (): void {
    config()->set('cache.default', 'array');
    config()->set('livck-cloud.catalog_cache.store', null);
    $writes = cacheWrites();
    LivckCloud::fake([MockResponse::json(catalogPayload())]);

    LivckCloud::catalog();

    expect($writes->getArrayCopy()[0]['store'] ?? null)->toBe('array');
});

it('asks the API every time when the cache is off', function (): void {
    config()->set('livck-cloud.catalog_cache.ttl', 0);
    $writes = cacheWrites();
    $fake = LivckCloud::fake([MockResponse::json(catalogPayload()), MockResponse::json(catalogPayload())]);

    LivckCloud::catalog();
    LivckCloud::catalog();

    expect($writes)->toHaveCount(0);
    $fake->assertSentCount(2);
});

it('caches nothing when the API refuses', function (): void {
    $writes = cacheWrites();
    LivckCloud::fake([MockResponse::error('This action is unauthorized.', 403)]);

    expect(fn(): CheckTypeCatalog => LivckCloud::catalog())->toThrow(PermissionDeniedException::class)
        ->and($writes)->toHaveCount(0);
});

it('refuses a cache setting of the wrong type', function (string $key, mixed $value, string $message): void {
    config()->set('livck-cloud.catalog_cache.' . $key, $value);
    LivckCloud::fake([MockResponse::json(catalogPayload())]);

    expect(fn(): CheckTypeCatalog => LivckCloud::catalog())->toThrow(ConfigurationException::class, $message);
})->with([
    'ttl' => ['ttl', 'hourly', 'livck-cloud.catalog_cache.ttl must be a whole number, got "hourly".'],
    'store' => ['store', ['redis'], 'livck-cloud.catalog_cache.store must be a string, got array.'],
]);
