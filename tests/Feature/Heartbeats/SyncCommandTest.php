<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\CachedPingUrls;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Testing\MockResponse;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

const SYNC_URL = 'https://ping.livck.test/hb_FromTheSyncCommandAndSecret01';

/**
 * @param array<string, mixed> $options
 * @return array{0: int, 1: string}
 */
function sync(array $options = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
{
    $output = new BufferedOutput($verbosity);
    $exitCode = Artisan::call('livck-cloud:heartbeats:sync', $options, $output);

    return [$exitCode, preg_replace('/[ \t]+/', ' ', $output->fetch()) ?? ''];
}

function scheduleShop(): void
{
    schedule()->command('export:nightly')->dailyAt('03:00')->heartbeat(maxDuration: 3600);
    schedule()->command('report:send')->hourly()->heartbeat();
}

it('declares now and shows what LIVCK did per key', function (): void {
    $declarations = declarationsVia([declared([], [
        'heartbeats' => [
            declaredEntry('export:nightly', SYNC_URL, ['schedule' => ['type' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'UTC', 'period_seconds' => null], 'max_duration_seconds' => 3600]),
            declaredEntry('report:send', 'https://ping.livck.test/hb_ReportSendUrlAndSecret00002', ['result' => 'unchanged', 'status' => 'up', 'schedule' => ['type' => 'cron', 'expression' => '0 * * * *', 'timezone' => 'UTC', 'period_seconds' => null]]),
        ],
        'counts' => ['created' => 1, 'updated' => 0, 'unchanged' => 1],
    ])]);
    heartbeatsOn();
    scheduleShop();

    [$exitCode, $output] = sync();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('shop')->toContain('testing')
        ->toContain('| export:nightly | schedule | 0 3 * * * (UTC) | created | unknown |')
        ->toContain('| report:send | schedule | 0 * * * * (UTC) | unchanged | up |')
        ->toContain('1, 0, 1')
        ->toContain('2 heartbeats declared.')
        ->and($output)->not->toContain('no longer in the code')
        ->and($output)->not->toContain('hb_From')
        ->and(app(CachedPingUrls::class)->resolve('export:nightly')?->url->reveal())->toBe(SYNC_URL)
        ->and($declarations->recorded())->toHaveCount(1);
});

it('declares whatever the scheduler declared before', function (): void {
    $declarations = declarationsVia([declared(['export:nightly' => SYNC_URL, 'report:send' => SYNC_URL]), declared(['export:nightly' => SYNC_URL, 'report:send' => SYNC_URL])]);
    heartbeatsOn();
    scheduleShop();

    app(HeartbeatMonitor::class)->schedulerStarting();
    [$exitCode] = sync();

    expect($exitCode)->toBe(0)->and($declarations->recorded())->toHaveCount(2);
});

it('fails with what was not created and why, and lists what is no longer in the code', function (): void {
    declarationsVia([declared(['export:nightly' => SYNC_URL], [
        'not_created' => ['keys' => ['report:send'], 'reason' => 'service_limit', 'missing' => 1, 'retry_after' => null],
        'unlisted' => [['key' => 'import:legacy', 'service_id' => 'svcLegacyImport00001'], ['key' => 'feed:pull', 'service_id' => 'svcFeedPull000000001']],
        'skipped_tags' => ['livck.env:testing'],
    ])]);
    heartbeatsOn();
    scheduleShop();

    [$exitCode, $output] = sync();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Not created: report:send. Reason: the plan has no room for them (1 more services needed).')
        ->toContain('Not tagged with livck.env:testing')
        ->toContain('In LIVCK, no longer in the code (shop in testing)')
        ->toContain('⇂ import:legacy')->toContain('⇂ feed:pull')
        ->toContain('These heartbeats stay in LIVCK and report an outage once their ping stays away. If you removed or renamed the task, delete the heartbeat in LIVCK.')
        ->and($output)->not->toContain('heartbeats declared.');
});

it('shows what would be sent and sends nothing with --dry-run', function (): void {
    $declarations = declarationsVia();
    heartbeatsOn(['report:send' => 'https://ping.livck.test/hb_report']);
    config()->set('livck-cloud.heartbeats.urls', ['report:send' => 'https://ping.livck.test/hb_report', 'offsite' => 'https://ping.livck.test/hb_offsite']);
    scheduleShop();
    schedule()->command('feed:pull')->everyFiveMinutes()->heartbeat(grace: 120, tolerance: 2);

    [$exitCode, $output] = sync(['--dry-run' => true]);
    [, $verbose] = sync(['--dry-run' => true], OutputInterface::VERBOSITY_VERBOSE);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('| export:nightly | schedule | 0 3 * * * (UTC) | max duration 3600 s |')
        ->toContain('report:send: ping URL in the configuration')
        ->toContain('offsite: ping URL in the configuration')
        ->toContain('| feed:pull | schedule | */5 * * * * (UTC) | grace 120 s, tolerance 2 missed pings |')
        ->toContain('2 heartbeats would be declared (POST /v1/heartbeats/declare). Nothing was sent.')
        ->and($output)->not->toContain('"heartbeats"')
        ->and($verbose)->toContain('"key": "export:nightly"')->toContain('"max_duration_seconds": 3600')
        ->and($declarations->recorded())->toBe([]);
});

it('shows a dry run without a token and outside the environments, and says that a run from here declares nothing', function (): void {
    scheduleShop();

    [$exitCode, $output] = sync(['--dry-run' => true]);
    $output = preg_replace('/\s+/', ' ', $output) ?? '';

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('| export:nightly | schedule | 0 3 * * * (UTC) | max duration 3600 s |')
        ->toContain('Heartbeats are off in the environment [testing] (livck-cloud.heartbeats.environments): without --dry-run, the command declares nothing from here.')
        ->toContain('No API token is configured: without --dry-run, the command declares nothing, and neither does the scheduler.')
        ->toContain('2 heartbeats to declare (POST /v1/heartbeats/declare), but not from here. Nothing was sent.')
        ->and($output)->not->toContain('would be declared');
});

it('names only the missing token in a dry run where heartbeats are on', function (): void {
    heartbeatsOn();
    scheduleShop();

    [$exitCode, $output] = sync(['--dry-run' => true]);
    $output = preg_replace('/\s+/', ' ', $output) ?? '';

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No API token is configured')
        ->toContain('but not from here. Nothing was sent.')
        ->and($output)->not->toContain('Heartbeats are off');
});

it('refuses to declare from an environment without heartbeats', function (): void {
    $declarations = declarationsVia();
    scheduleShop();

    [$exitCode, $output] = sync();

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Heartbeats are off in the environment [testing] (livck-cloud.heartbeats.environments)')
        ->toContain('--env=production')
        ->and($declarations->recorded())->toBe([]);
});

it('needs a token', function (): void {
    heartbeatsOn();
    scheduleShop();

    [$exitCode, $output] = sync();

    expect($exitCode)->toBe(2)->and($output)->toContain('Declaring heartbeats needs an API token with heartbeats.declare');
});

it('has nothing to declare when every heartbeat has a URL in the configuration', function (): void {
    $declarations = declarationsVia();
    heartbeatsOn(['export:nightly' => 'https://ping.livck.test/hb_export', 'report:send' => 'https://ping.livck.test/hb_report']);
    scheduleShop();

    [$exitCode, $output] = sync();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Nothing to declare')
        ->and($declarations->recorded())->toBe([]);
});

it('fails with the reason when LIVCK refuses', function (MockResponse $response, string $message): void {
    declarationsVia([$response]);
    useConnection('default', ['token' => TEST_TOKEN, 'max_retries' => 0]);
    heartbeatsOn();
    scheduleShop();

    [$exitCode, $output] = sync();

    expect($exitCode)->toBe(1)
        ->and(preg_replace('/\s+/', ' ', $output))->toContain($message)
        ->and($output)->not->toContain(TEST_TOKEN);
})->with([
    '401' => [MockResponse::error('Unauthenticated.', 401), 'LIVCK does not accept the API token of the connection [default] (HTTP 401)'],
    '403 plan' => [MockResponse::error('No API access.', 403, extra: ['upsell' => ['reason' => 'feature', 'key' => 'api_access']]), 'does not include API access (HTTP 403)'],
    '403 ability' => [MockResponse::error("This token lacks the required 'heartbeats.declare' permission.", 403), 'may not declare heartbeats (HTTP 403: This token lacks the required'],
    '403 restricted view' => [MockResponse::error('Declaring heartbeats requires a token that sees the whole organization.', 403, extra: ['code' => 'restricted_view']), 'sees only part of the organization (HTTP 403), so the heartbeats were not declared.'],
    'no answer' => [MockResponse::networkError('Connection refused'), 'LIVCK could not be reached'],
    '429' => [MockResponse::error('Too Many Attempts.', 429)->withRetryAfter(45), 'LIVCK limits how often a token may declare heartbeats and asked to wait (HTTP 429), so nothing was declared. Run the command again in 45 seconds; the scheduler waits as long.'],
]);

it('keeps the scheduler away for as long as a 429 of the command asks', function (): void {
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-11-27 10:00:00', 'UTC'));
    $declarations = declarationsVia([MockResponse::error('Too Many Attempts.', 429)->withRetryAfter(45), declared(['export:nightly' => SYNC_URL])]);
    useConnection('default', ['token' => TEST_TOKEN, 'max_retries' => 0]);
    heartbeatsOn();
    schedule()->command('export:nightly')->dailyAt('03:00')->heartbeat();

    expect(sync()[0])->toBe(1);

    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::now()->addSeconds(44));
    app(HeartbeatMonitor::class)->schedulerStarting();
    expect($declarations->recorded())->toHaveCount(1);

    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::now()->addSeconds(2));
    app(HeartbeatMonitor::class)->schedulerStarting();
    expect($declarations->recorded())->toHaveCount(2)
        ->and(app(CachedPingUrls::class)->resolve('export:nightly')?->url->reveal())->toBe(SYNC_URL);

    Illuminate\Support\Carbon::setTestNow();
});

it('works with the fake', function (): void {
    $fake = LivckCloud::fake();
    scheduleShop();

    sync();
    schedule()->command('feed:pull')->everyFiveMinutes()->heartbeat();

    [$exitCode, $output] = sync();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('| export:nightly | schedule | 0 3 * * * (UTC) | unchanged | unknown |')
        ->toContain('| feed:pull | schedule | */5 * * * * (UTC) | created | unknown |')
        ->and($output)->not->toContain('no longer in the code');

    $fake->assertDeclared('export:nightly', 'report:send', 'feed:pull')->assertNothingSent();
});
