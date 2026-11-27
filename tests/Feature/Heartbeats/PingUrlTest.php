<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\CachedPingUrls;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatPinger;
use LIVCK\Cloud\Laravel\Heartbeats\PingUrlResolver;
use LIVCK\Cloud\Laravel\Heartbeats\PingUrlSource;
use LIVCK\Cloud\Ping\PingDetails;
use LIVCK\Cloud\Support\Secret;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

const CONFIG_URL = 'https://ping.livck.test/hb_FromTheConfigurationAndSecret01';
const CACHE_URL = 'https://ping.livck.test/hb_FromTheCacheAndJustAsSecret0002';

function cachedUrls(): CachedPingUrls
{
    return app(CachedPingUrls::class);
}

describe('resolution', function (): void {
    it('takes the URL of the configuration over the one in the cache', function (): void {
        $http = heartbeatsOn(['backup' => CONFIG_URL]);
        cachedUrls()->put('backup', CACHE_URL);

        $resolved = app(PingUrlResolver::class)->resolve('backup');
        LivckCloud::heartbeat('backup')->success();

        expect($resolved?->source)->toBe(PingUrlSource::Config)
            ->and($resolved?->url->reveal())->toBe(CONFIG_URL)
            ->and(array_map(static fn(RecordedRequest $request): string => $request->uri, $http->recorded()))->toBe([CONFIG_URL]);
    });

    it('falls back to the cache', function (): void {
        $http = heartbeatsOn();
        cachedUrls()->put('backup', new Secret(CACHE_URL));

        expect(app(PingUrlResolver::class)->resolve('backup')?->source)->toBe(PingUrlSource::Cache);

        LivckCloud::heartbeat('backup')->start();

        expect(array_map(static fn(RecordedRequest $request): string => $request->uri, $http->recorded()))->toBe([CACHE_URL . '/start']);
    });

    it('keeps the cached URLs encrypted, under a key without the URL', function (): void {
        cachedUrls()->put('backup', CACHE_URL);
        $key = cachedUrls()->entryKey('backup');
        $stored = Cache::store('array')->get($key);
        $stored = is_string($stored) ? $stored : '';

        expect($key)->toMatch('/\Alivck-cloud:heartbeat:[0-9a-f]{32}\z/')
            ->and($stored)->not->toBe('')
            ->and($stored)->not->toContain('ping.livck.test')
            ->and($stored)->not->toContain('FromTheCache')
            ->and(Crypt::decryptString($stored))->toBe('{"url":"' . CACHE_URL . '","stale":false}');
    });

    it('keeps the URLs of each connection, application and environment apart', function (): void {
        cachedUrls()->put('backup', CACHE_URL);
        $resolver = app(PingUrlResolver::class);

        config()->set('livck-cloud.heartbeats.app', 'admin');
        expect($resolver->resolve('backup'))->toBeNull();

        config()->set('livck-cloud.heartbeats.app', 'shop');
        app()->detectEnvironment(static fn(): string => 'staging');
        expect($resolver->resolve('backup'))->toBeNull();

        app()->detectEnvironment(static fn(): string => 'testing');
        useConnection('customer-b');
        config()->set('livck-cloud.default', 'customer-b');
        expect($resolver->resolve('backup'))->toBeNull();

        config()->set('livck-cloud.default', 'default');
        expect($resolver->resolve('backup')?->url->reveal())->toBe(CACHE_URL);
    });

    it('writes, forgets and writes many', function (): void {
        $cache = cachedUrls();

        expect($cache->putMany(['a' => CACHE_URL, 'b' => new Secret(CONFIG_URL)]))->toBeTrue()
            ->and($cache->resolve('a')?->url->reveal())->toBe(CACHE_URL)
            ->and($cache->resolve('b')?->url->reveal())->toBe(CONFIG_URL)
            ->and($cache->put('c', ''))->toBeFalse()
            ->and($cache->forget('a'))->toBeTrue()
            ->and($cache->resolve('a'))->toBeNull();
    });

    it('reads an entry it cannot decrypt as no entry', function (): void {
        Cache::store('array')->forever(cachedUrls()->entryKey('backup'), 'not encrypted');
        expect(cachedUrls()->resolve('backup'))->toBeNull();

        Cache::store('array')->forever(cachedUrls()->entryKey('backup'), Crypt::encryptString('{"url": 42}'));
        expect(cachedUrls()->resolve('backup'))->toBeNull();
    });

    it('reads a broken configuration as no URL', function (): void {
        config()->set('livck-cloud.heartbeats.urls', 'https://ping.livck.test/hb_x');

        expect(app(PingUrlResolver::class)->resolve('backup'))->toBeNull();
    });
});

describe('pings', function (): void {
    it('sends each signal to its path, with the details as the body and never a token', function (): void {
        useConnection('default', ['token' => TEST_TOKEN]);
        $http = heartbeatsOn(['backup' => CONFIG_URL]);
        $heartbeat = LivckCloud::heartbeat('backup');

        $heartbeat->start();
        $heartbeat->success(PingDetails::make()->withMetric('rows', 12));
        $heartbeat->fail();
        $heartbeat->exitCode(3);
        $heartbeat->exitCode(0);

        expect(pingedPaths($http))->toBe(['/hb_FromTheConfigurationAndSecret01/start', '/hb_FromTheConfigurationAndSecret01', '/hb_FromTheConfigurationAndSecret01/fail', '/hb_FromTheConfigurationAndSecret01/3', '/hb_FromTheConfigurationAndSecret01'])
            ->and($http->recorded()[1]->json())->toBe(['metrics' => ['rows' => 12]])
            ->and(array_filter($http->recorded(), static fn(RecordedRequest $request): bool => $request->hasHeader('Authorization')))->toBe([])
            ->and($http->recorded()[0]->header('User-Agent'))->toContain('livck-cloud-laravel/');
    });

    it('sends nothing for a key without a URL, and says so once per process', function (): void {
        $http = heartbeatsOn();
        $logged = loggedMessages();

        $first = LivckCloud::heartbeat('backup')->start();
        LivckCloud::heartbeat('backup')->success();
        LivckCloud::heartbeat('other')->success();

        expect($first->sent)->toBeFalse()
            ->and($http->recorded())->toBe([])
            ->and($logged->getArrayCopy())->toBe([
                'warning: No ping URL is known for the heartbeat [backup], so it was not pinged. Put one under livck-cloud.heartbeats.urls, or let the scheduler declare it with an API token; a heartbeat of your own needs the seconds between two pings under livck-cloud.heartbeats.custom for that.',
                'warning: No ping URL is known for the heartbeat [other], so it was not pinged. Put one under livck-cloud.heartbeats.urls, or let the scheduler declare it with an API token; a heartbeat of your own needs the seconds between two pings under livck-cloud.heartbeats.custom for that.',
            ]);
    });

    it('marks a cached URL LIVCK does not know as stale and stops using it', function (): void {
        $http = heartbeatsOn(responses: [MockResponse::error('Not Found', 404)]);
        cachedUrls()->put('backup', CACHE_URL);
        $logged = loggedMessages();

        $result = LivckCloud::heartbeat('backup')->start();

        expect($result->unknownUrl)->toBeTrue()
            ->and(cachedUrls()->isStale('backup'))->toBeTrue()
            ->and(cachedUrls()->hasStale())->toBeTrue()
            ->and(cachedUrls()->resolve('backup'))->toBeNull()
            ->and($logged->getArrayCopy())->toBe(['warning: LIVCK does not know the ping URL of the heartbeat [backup] (HTTP 404); it was dropped from the cache.']);

        LivckCloud::heartbeat('backup')->success();
        expect($http->recorded())->toHaveCount(1);

        cachedUrls()->put('backup', CACHE_URL);
        expect(cachedUrls()->isStale('backup'))->toBeFalse()
            ->and(cachedUrls()->clearStale())->toBeTrue()
            ->and(cachedUrls()->hasStale())->toBeFalse();
    });

    it('keeps a configured URL LIVCK does not know, and asks for a new one', function (): void {
        $http = heartbeatsOn(['backup' => CONFIG_URL], [MockResponse::error('Not Found', 404), MockResponse::error('Not Found', 404)]);
        $logged = loggedMessages();

        LivckCloud::heartbeat('backup')->start();
        LivckCloud::heartbeat('backup')->success();

        expect($http->recorded())->toHaveCount(2)
            ->and(cachedUrls()->hasStale())->toBeFalse()
            ->and($logged->getArrayCopy()[0] ?? null)->toBe('warning: LIVCK does not know the ping URL configured for the heartbeat [backup] (HTTP 404). Replace it under livck-cloud.heartbeats.urls.');
    });

    it('pauses pings for a minute when LIVCK cannot be reached', function (): void {
        $http = heartbeatsOn(['backup' => CONFIG_URL], [MockResponse::networkError('Could not resolve host'), MockResponse::json(['ok' => true])]);
        Carbon::setTestNow(Carbon::now());
        $logged = loggedMessages();

        expect(LivckCloud::heartbeat('backup')->start()->sent)->toBeFalse()
            ->and(LivckCloud::heartbeat('backup')->success()->error)->toContain('pause')
            ->and($http->recorded())->toHaveCount(1)
            ->and(implode("\n", $logged->getArrayCopy()))->not->toContain('FromTheConfiguration');

        Carbon::setTestNow(Carbon::now()->addSeconds(HeartbeatPinger::PAUSE + 1));

        expect(LivckCloud::heartbeat('backup')->success()->ok())->toBeTrue()
            ->and($http->recorded())->toHaveCount(2);
    });

    it('warns about a ping LIVCK refused without the URL', function (): void {
        heartbeatsOn(['backup' => CONFIG_URL], [MockResponse::error('Too Many Requests', 429)]);
        $logged = loggedMessages();

        LivckCloud::heartbeat('backup')->success();

        expect($logged->getArrayCopy())->toBe(['warning: The heartbeat [backup] could not be pinged: Too many pings for this URL (HTTP 429); this ping was not recorded.']);
    });

    it('never throws for a URL it cannot use, and does not pause the others for it', function (): void {
        $http = heartbeatsOn(['backup' => 'http://ping.livck.test/hb_plain', 'export' => CONFIG_URL]);
        $logged = loggedMessages();

        expect(LivckCloud::heartbeat('backup')->success()->sent)->toBeFalse()
            ->and(LivckCloud::heartbeat('export')->success()->ok())->toBeTrue()
            ->and(pingedPaths($http))->toBe(['/hb_FromTheConfigurationAndSecret01'])
            ->and($logged->getArrayCopy())->toBe(['warning: The heartbeat [backup] could not be pinged: The ping URL must use https; plain http is accepted for loopback hosts only.']);
    });
});
