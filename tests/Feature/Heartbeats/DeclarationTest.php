<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\CachedPingUrls;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatDeclarer;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatStore;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\SyncOrders;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

const EXPORT_URL = 'https://ping.livck.test/hb_DeclaredExportUrlAndSecret0001';
const ORDERS_URL = 'https://ping.livck.test/hb_DeclaredOrdersUrlAndSecret0002';
const RENEWED_URL = 'https://ping.livck.test/hb_RenewedAfterTheOldOneWasGone03';

/**
 * The declarations a fake HTTP client received, as decoded bodies.
 *
 * @return list<array<array-key, mixed>>
 */
function declarations(LIVCK\Cloud\Testing\FakeHttpClient $http): array
{
    return array_values(array_map(
        static fn(RecordedRequest $request): array => $request->json() ?? [],
        array_filter($http->recorded(), static fn(RecordedRequest $request): bool => $request->matches('POST', '/v1/heartbeats/declare')),
    ));
}

/**
 * The heartbeats of one declaration a fake HTTP client received.
 *
 * @return list<array<array-key, mixed>>
 */
function declaredHeartbeats(LIVCK\Cloud\Testing\FakeHttpClient $http, int $index = 0): array
{
    $heartbeats = declarations($http)[$index]['heartbeats'] ?? [];
    $entries = [];

    foreach (is_array($heartbeats) ? $heartbeats : [] as $entry) {
        $entries[] = is_array($entry) ? $entry : [];
    }

    return $entries;
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-27 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('who declares what', function (): void {
    it('never calls the API for a key with a URL in the configuration', function (): void {
        $declarations = declarationsVia();
        $pings = heartbeatsOn(['export:nightly' => 'https://ping.livck.test/hb_configured']);
        schedule()->command('export:nightly')->everyMinute()->heartbeat();

        expect(runSchedule())->toBe(0)
            ->and($declarations->recorded())->toBe([])
            ->and(pingedPaths($pings))->toBe(['/hb_configured/start', '/hb_configured']);
    });

    it('declares a key without a URL and pings it at the URL LIVCK returns, in the same run', function (): void {
        $declarations = declarationsVia([declared(['export:nightly' => EXPORT_URL])]);
        $pings = heartbeatsOn();
        schedule()->command('export:nightly')->everyMinute()->timezone('Europe/Berlin')->heartbeat(grace: 300, maxDuration: 3600, tolerance: 2);

        runSchedule();

        expect(declarations($declarations))->toBe([[
            'app' => 'shop',
            'environment' => 'testing',
            'heartbeats' => [[
                'key' => 'export:nightly',
                'kind' => 'schedule',
                'schedule' => ['type' => 'cron', 'expression' => '* * * * *', 'timezone' => 'Europe/Berlin'],
                'grace_seconds' => 300,
                'max_duration_seconds' => 3600,
                'schedule_tolerance' => 2,
            ]],
        ]])
            ->and($declarations->recorded()[0]->header('Authorization'))->toBe('Bearer ' . TEST_TOKEN)
            ->and(app(CachedPingUrls::class)->resolve('export:nightly')?->url->reveal())->toBe(EXPORT_URL)
            ->and(pingedPaths($pings))->toBe(['/hb_DeclaredExportUrlAndSecret0001/start', '/hb_DeclaredExportUrlAndSecret0001']);
    });

    it('declares only the keys without a URL in the configuration, and nothing without a schedule', function (): void {
        $declarations = declarationsVia([declared(['queue:default' => ORDERS_URL])]);
        heartbeatsOn(['export:nightly' => 'https://ping.livck.test/hb_configured', 'offsite' => 'https://ping.livck.test/hb_offsite']);
        config()->set('livck-cloud.heartbeats.queues', ['default']);
        schedule()->command('export:nightly')->daily()->heartbeat();

        app(HeartbeatMonitor::class)->schedulerStarting();

        expect(array_column(declaredHeartbeats($declarations), 'key'))->toBe(['queue:default']);
    });

    it('declares the queue pulse with its grace, and a queued job without maxDuration', function (): void {
        $declarations = declarationsVia([declared(['job:sync-orders' => EXPORT_URL, 'queue:default' => ORDERS_URL])]);
        heartbeatsOn();
        config()->set('livck-cloud.heartbeats.queues', ['default']);
        config()->set('livck-cloud.heartbeats.queue_grace', 180);
        schedule()->job(new SyncOrders())->everyMinute()->heartbeat(maxDuration: 600);

        app(HeartbeatMonitor::class)->schedulerStarting();

        expect(declaredHeartbeats($declarations))->toBe([
            ['key' => 'job:sync-orders', 'kind' => 'job', 'schedule' => ['type' => 'cron', 'expression' => '* * * * *', 'timezone' => 'UTC']],
            ['key' => 'queue:default', 'kind' => 'queue', 'schedule' => ['type' => 'simple', 'period_seconds' => 60], 'grace_seconds' => 180],
        ]);
    });

    it('sends nothing without a token and without a URL, and says so once per list', function (): void {
        $declarations = declarationsVia();
        useConnection('default', ['token' => null]);
        heartbeatsOn();
        $logged = loggedMessages();
        schedule()->command('export:nightly')->everyMinute()->heartbeat();
        schedule()->command('report:send')->hourly()->heartbeat();

        runSchedule();
        runSchedule();

        $noToken = array_values(array_filter($logged->getArrayCopy(), static fn(string $line): bool => str_contains($line, 'no API token')));

        expect($declarations->recorded())->toBe([])
            ->and($noToken)->toBe(['warning: The heartbeats [export:nightly, report:send] have no ping URL and are not declared: the connection [default] has no API token. Set one that has heartbeats.declare (LIVCK_CLOUD_TOKEN), or put their ping URLs under livck-cloud.heartbeats.urls.']);
    });

    it('does not call LIVCK again for the same list, and once for a changed schedule', function (): void {
        $declarations = declarationsVia([declared(['export:nightly' => EXPORT_URL]), declared(['export:nightly' => EXPORT_URL])]);
        heartbeatsOn();
        $event = schedule()->command('export:nightly')->dailyAt('03:00')->heartbeat();

        app(HeartbeatMonitor::class)->schedulerStarting();
        app(HeartbeatMonitor::class)->schedulerStarting();
        Carbon::setTestNow(Carbon::now()->addDay());
        app(HeartbeatMonitor::class)->schedulerStarting();

        expect($declarations->recorded())->toHaveCount(1);

        $event->dailyAt('04:00');
        app(HeartbeatMonitor::class)->schedulerStarting();
        app(HeartbeatMonitor::class)->schedulerStarting();

        expect(declarations($declarations))->toHaveCount(2)
            ->and(declaredHeartbeats($declarations, 1)[0]['schedule'] ?? null)->toBe(['type' => 'cron', 'expression' => '0 4 * * *', 'timezone' => 'UTC']);
    });

    it('never declares from a worker, even when its list differs from the one declared', function (): void {
        useDatabaseQueue();
        $declarations = declarationsVia([declared(['job:sync-orders' => ORDERS_URL])]);
        $pings = heartbeatsOn();
        schedule()->job(new SyncOrders())->everyMinute()->heartbeat();

        runSchedule();
        expect($declarations->recorded())->toHaveCount(1);

        // The worker of an earlier deploy: the same job, another schedule.
        schedule()->command('report:send')->hourly()->heartbeat();
        work();
        LivckCloud::heartbeat('job:sync-orders')->success();

        expect($declarations->recorded())->toHaveCount(1)
            ->and(pingedPaths($pings))->toBe(['/hb_DeclaredOrdersUrlAndSecret0002', '/hb_DeclaredOrdersUrlAndSecret0002']);
    });

    it('declares only in schedule:run, not in another Artisan command', function (): void {
        $declarations = declarationsVia();
        heartbeatsOn();
        schedule()->command('export:nightly')->everyMinute()->heartbeat();

        Illuminate\Support\Facades\Artisan::call('livck-cloud:heartbeats');
        Illuminate\Support\Facades\Artisan::call('schedule:list');

        expect($declarations->recorded())->toBe([]);
    });

    it('declares once when two runs start at the same moment', function (): void {
        $declarations = declarationsVia();
        $declarations->queue(static function (): MockResponse {
            // A second scheduler starts while the first one waits for LIVCK.
            app(HeartbeatMonitor::class)->schedulerStarting();

            return declared(['export:nightly' => EXPORT_URL]);
        });
        heartbeatsOn();
        schedule()->command('export:nightly')->everyMinute()->heartbeat();

        app(HeartbeatMonitor::class)->schedulerStarting();
        app(HeartbeatMonitor::class)->schedulerStarting();

        expect($declarations->recorded())->toHaveCount(1);
    });

    it('leaves LIVCK alone while another process holds the lock', function (): void {
        $declarations = declarationsVia();
        heartbeatsOn();
        schedule()->command('export:nightly')->everyMinute()->heartbeat();
        $store = Cache::store('array')->getStore();
        assert($store instanceof LockProvider);
        $lock = $store->lock(HeartbeatStore::key(app(), 'declaration-lock'), 30);
        $lock->get();

        app(HeartbeatMonitor::class)->schedulerStarting();
        expect($declarations->recorded())->toBe([]);

        $lock->release();
        $declarations->queue(declared(['export:nightly' => EXPORT_URL]));
        app(HeartbeatMonitor::class)->schedulerStarting();
        expect($declarations->recorded())->toHaveCount(1);
    });

    it('declares through the connection with a short timeout and no retries', function (): void {
        useConnection('default', ['token' => TEST_TOKEN, 'max_retries' => 2, 'timeout' => 30]);
        $manager = app(CloudManager::class);

        $short = $manager->heartbeatClient(3.0)->options();
        $configured = $manager->heartbeatClient(null)->options();

        expect([$short->timeout, $short->connectTimeout, $short->maxRetries])->toBe([3.0, 3.0, 0])
            ->and([$configured->timeout, $configured->maxRetries])->toBe([30.0, 2]);

        $declarations = declarationsVia([MockResponse::error('Server Error', 500), MockResponse::error('Server Error', 500)]);
        useConnection('default', ['token' => TEST_TOKEN, 'max_retries' => 2]);
        heartbeatsOn();
        schedule()->command('export:nightly')->everyMinute()->heartbeat();

        app(HeartbeatMonitor::class)->schedulerStarting();

        expect($declarations->recorded())->toHaveCount(1);
    });

    it('sends through Laravel\'s HTTP client with transport laravel', function (): void {
        useConnection('default', ['token' => TEST_TOKEN, 'transport' => 'laravel']);
        Http::fake(['*' => Http::response(declared(['export:nightly' => EXPORT_URL])->body, 200, ['Content-Type' => 'application/json'])]);
        heartbeatsOn();
        schedule()->command('export:nightly')->everyMinute()->heartbeat();

        app(HeartbeatMonitor::class)->schedulerStarting();

        Http::assertSentCount(1);
        expect(app(CachedPingUrls::class)->resolve('export:nightly')?->url->reveal())->toBe(EXPORT_URL);
    });
});

describe('failures', function (): void {
    it('pauses, keeps pinging the known URL and never throws', function (MockResponse $response, ?int $pause, string $logged): void {
        $declarations = declarationsVia([$response, declared(['export:nightly' => EXPORT_URL])]);
        $pings = heartbeatsOn();
        app(CachedPingUrls::class)->put('export:nightly', 'https://ping.livck.test/hb_KnownBeforeTheFailure');
        $log = loggedMessages();
        $event = schedule()->command('export:nightly')->everyMinute()->heartbeat();

        expect(runSchedule())->toBe(0)
            ->and($declarations->recorded())->toHaveCount(1)
            ->and(pingedPaths($pings))->toBe(['/hb_KnownBeforeTheFailure/start', '/hb_KnownBeforeTheFailure'])
            ->and(implode("\n", $log->getArrayCopy()))->toContain($logged)
            ->and(implode("\n", $log->getArrayCopy()))->not->toContain(TEST_TOKEN)
            ->and(implode("\n", $log->getArrayCopy()))->not->toContain('KnownBeforeTheFailure');

        if ($pause === null) {
            // Until the list changes.
            Carbon::setTestNow(Carbon::now()->addDay());
            app(HeartbeatMonitor::class)->schedulerStarting();
            expect($declarations->recorded())->toHaveCount(1);

            $event->hourly();
        } elseif ($pause > 0) {
            Carbon::setTestNow(Carbon::now()->addSeconds($pause - 1));
            app(HeartbeatMonitor::class)->schedulerStarting();
            expect($declarations->recorded())->toHaveCount(1);

            Carbon::setTestNow(Carbon::now()->addSeconds(2));
        }

        app(HeartbeatMonitor::class)->schedulerStarting();

        expect($declarations->recorded())->toHaveCount(2)
            ->and(app(CachedPingUrls::class)->resolve('export:nightly')?->url->reveal())->toBe(EXPORT_URL);
    })->with([
        'no answer' => [MockResponse::networkError('Connection timed out'), 60, 'LIVCK could not be reached to declare the heartbeats'],
        '5xx' => [MockResponse::error('Server Error', 502), 60, 'LIVCK could not take the declaration of the heartbeats (HTTP 502). The next try is in a minute. The ping URLs known so far are used meanwhile.'],
        'monitoring frozen' => [MockResponse::error('Monitoring is paused', 503), 60, '(HTTP 503)'],
        '429' => [MockResponse::error('Too Many Attempts.', 429)->withRetryAfter(120), 120, 'LIVCK asked to wait before the heartbeats are declared (HTTP 429). The next try is in 120 seconds.'],
        '409' => [MockResponse::error('Another declaration is running.', 409), 0, 'Another process declared the same heartbeats at the same moment (HTTP 409). The next scheduler run declares them again.'],
        '401' => [MockResponse::error('Unauthenticated.', 401), 900, 'LIVCK does not accept the API token of the connection [default] (HTTP 401): it is unknown, revoked or expired, so the heartbeats were not declared. The ping URLs known so far are used meanwhile. Create a token with heartbeats.declare under Settings > Organization > API tokens.'],
        '403 ability' => [MockResponse::error("This token lacks the required 'heartbeats.declare' permission.", 403), 900, "The API token of the connection [default] may not declare heartbeats (HTTP 403: This token lacks the required 'heartbeats.declare' permission). The ping URLs known so far are used meanwhile. Create a token with heartbeats.declare under Settings > Organization > API tokens."],
        '403 restricted view' => [MockResponse::error('Declaring heartbeats requires a token that sees the whole organization.', 403, extra: ['code' => 'restricted_view']), 900, "The API token of the connection [default] sees only part of the organization (HTTP 403), so the heartbeats were not declared. A token sees what the member who created it sees, and this member's view is limited by access areas; declared heartbeats belong to the whole organization. The ping URLs known so far are used meanwhile. More abilities do not help: create a token with heartbeats.declare as a member who sees the whole organization, and use it for the connection."],
        '403 plan' => [MockResponse::error('API access is not part of your plan.', 403, extra: ['upsell' => ['reason' => 'feature', 'key' => 'api_access']]), 900, 'does not include API access (HTTP 403), so the heartbeats were not declared. The ping URLs known so far are used meanwhile. A heartbeat without one is not pinged: put its ping URL under livck-cloud.heartbeats.urls, which works on every plan'],
        '422' => [MockResponse::error('The heartbeats.0.key field format is invalid.', 422, ['heartbeats.0.key' => ['Use letters, digits, ".", "_", "-", ":" and "/" only.']]), null, 'LIVCK refused the list of heartbeats (HTTP 422): heartbeats.0.key: Use letters, digits, ".", "_", "-", ":" and "/" only. It is not declared again until the list changes.'],
    ]);

    it('swallows an answer it cannot read, and tries again a minute later', function (): void {
        $declarations = declarationsVia([MockResponse::json(['data' => ['heartbeats' => 'nope']]), declared(['export:nightly' => EXPORT_URL])]);
        heartbeatsOn();
        schedule()->command('export:nightly')->everyMinute()->heartbeat();

        expect(runSchedule())->toBe(0);

        Carbon::setTestNow(Carbon::now()->addSeconds(HeartbeatDeclarer::PAUSE_UNREACHABLE));
        app(HeartbeatMonitor::class)->schedulerStarting();

        expect($declarations->recorded())->toHaveCount(2);
    });
});

describe('ping URLs LIVCK no longer knows', function (): void {
    it('declares anew after a 404, at most every ten minutes', function (): void {
        $declarations = declarationsVia([
            declared(['export:nightly' => EXPORT_URL]),
            declared(['export:nightly' => RENEWED_URL]),
            declared(['export:nightly' => EXPORT_URL]),
        ]);
        $pings = heartbeatsOn(responses: [
            MockResponse::error('Not Found', 404),      // run 1: start at the first URL
            MockResponse::json(['ok' => true]),         // run 2: start at the renewed URL
            MockResponse::json(['ok' => true]),         //        and its success
            MockResponse::error('Not Found', 404),      // run 3: the renewed one is gone too
            MockResponse::json(['ok' => true]),         // run 5: start at the third URL
            MockResponse::json(['ok' => true]),
        ]);
        schedule()->command('export:nightly')->everyMinute()->heartbeat();

        runSchedule();
        expect(app(CachedPingUrls::class)->hasStale())->toBeTrue();

        runSchedule();
        expect($declarations->recorded())->toHaveCount(2)
            ->and(pingedPaths($pings))->toBe(['/hb_DeclaredExportUrlAndSecret0001/start', '/hb_RenewedAfterTheOldOneWasGone03/start', '/hb_RenewedAfterTheOldOneWasGone03']);

        Carbon::setTestNow(Carbon::now()->addMinute());
        runSchedule();
        Carbon::setTestNow(Carbon::now()->addMinutes(8));
        runSchedule();
        expect($declarations->recorded())->toHaveCount(2);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        runSchedule();
        expect($declarations->recorded())->toHaveCount(3)
            ->and(app(CachedPingUrls::class)->hasStale())->toBeFalse()
            ->and(array_slice(pingedPaths($pings), -2))->toBe(['/hb_DeclaredExportUrlAndSecret0001/start', '/hb_DeclaredExportUrlAndSecret0001']);
    });
});

describe('the answer', function (): void {
    it('logs what was not created, what is no longer in the code and the skipped tags, once per list', function (): void {
        $answer = static fn(): MockResponse => declared(['export:nightly' => EXPORT_URL], [
            'not_created' => ['keys' => ['report:send', 'feed:pull'], 'reason' => 'service_limit', 'missing' => 2, 'retry_after' => null],
            'unlisted' => [['key' => 'import:legacy', 'service_id' => 'svcLegacyImport00001']],
            'skipped_tags' => ['livck.app:shop'],
        ]);
        $declarations = declarationsVia([$answer(), $answer()]);
        heartbeatsOn();
        $logged = loggedMessages();
        schedule()->command('export:nightly')->daily()->heartbeat();
        schedule()->command('report:send')->daily()->heartbeat();
        schedule()->command('feed:pull')->daily()->heartbeat();

        app(HeartbeatMonitor::class)->schedulerStarting();
        app(HeartbeatMonitor::class)->schedulerStarting();

        // The heartbeats LIVCK did not create are tried again later; the log stays quiet then.
        Carbon::setTestNow(Carbon::now()->addSeconds(HeartbeatDeclarer::RETRY_NOT_CREATED));
        app(HeartbeatMonitor::class)->schedulerStarting();

        expect($declarations->recorded())->toHaveCount(2)
            ->and($logged->getArrayCopy())->toBe([
                'warning: LIVCK did not create the heartbeats [report:send, feed:pull]: the plan has no room for them (2 more services needed). Delete services you no longer need or choose a larger plan; the next declaration creates them. They are not pinged until they exist; the others were declared.',
                'warning: The heartbeats [import:legacy] are in LIVCK for [shop] in [testing] but no longer in the code. They stay in LIVCK and report an outage once their ping stays away. If you removed or renamed the task, delete the heartbeat in LIVCK.',
                'warning: LIVCK could not tag the heartbeats with [livck.app:shop]: the organization has no room for more tags. The heartbeats were declared all the same.',
            ]);
    });

    it('tries heartbeats LIVCK could not create yet when the creation limit has room again', function (): void {
        $declarations = declarationsVia([
            declared([], ['not_created' => ['keys' => ['export:nightly'], 'reason' => 'creation_rate', 'missing' => null, 'retry_after' => 1800]]),
            declared(['export:nightly' => EXPORT_URL]),
        ]);
        heartbeatsOn();
        schedule()->command('export:nightly')->daily()->heartbeat();

        app(HeartbeatMonitor::class)->schedulerStarting();
        Carbon::setTestNow(Carbon::now()->addSeconds(1799));
        app(HeartbeatMonitor::class)->schedulerStarting();
        expect($declarations->recorded())->toHaveCount(1);

        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        app(HeartbeatMonitor::class)->schedulerStarting();
        expect($declarations->recorded())->toHaveCount(2)
            ->and(app(CachedPingUrls::class)->resolve('export:nightly'))->not->toBeNull();
    });
});

describe('heartbeats of your own', function (): void {
    it('declares a key of heartbeats.custom with its period and pings it at the URL LIVCK returns', function (): void {
        $declarations = declarationsVia([declared(['import' => EXPORT_URL])]);
        $pings = heartbeatsOn();
        config()->set('livck-cloud.heartbeats.custom', ['import' => 3600, 'unset' => null]);

        app(HeartbeatMonitor::class)->schedulerStarting();
        LivckCloud::heartbeat('import')->success();

        expect(declaredHeartbeats($declarations))->toBe([
            ['key' => 'import', 'kind' => 'custom', 'schedule' => ['type' => 'simple', 'period_seconds' => 3600]],
        ])
            ->and(pingedPaths($pings))->toBe(['/hb_DeclaredExportUrlAndSecret0001']);
    });

    it('leaves a key without a period to the URL in the configuration, and says what to do without one', function (): void {
        $declarations = declarationsVia();
        heartbeatsOn(['backup' => 'https://ping.livck.test/hb_backup']);
        $logged = loggedMessages();

        app(HeartbeatMonitor::class)->schedulerStarting();
        LivckCloud::heartbeat('import')->success();

        expect($declarations->recorded())->toBe([])
            ->and(app(HeartbeatMonitor::class)->list()->get('backup')?->schedule)->toBeNull()
            ->and($logged->getArrayCopy())->toBe(['warning: No ping URL is known for the heartbeat [import], so it was not pinged. Put one under livck-cloud.heartbeats.urls, or let the scheduler declare it with an API token; a heartbeat of your own needs the seconds between two pings under livck-cloud.heartbeats.custom for that.']);
    });

    it('reports a period below one and a key the schedule has already', function (): void {
        heartbeatsOn();
        config()->set('livck-cloud.heartbeats.custom', ['import' => 0, 'export:nightly' => 60]);
        schedule()->command('export:nightly')->daily()->heartbeat();

        $list = app(HeartbeatMonitor::class)->list();

        expect($list->keys())->toBe(['export:nightly'])
            ->and(array_map(static fn($problem): string => $problem->message, $list->problems()))->toBe([
                'The heartbeat [import] of livck-cloud.heartbeats.custom needs the seconds between two pings, a positive number.',
                'The key [export:nightly] of livck-cloud.heartbeats.custom is a scheduled task or a queue already. Remove it from heartbeats.custom.',
            ]);
    });
});
