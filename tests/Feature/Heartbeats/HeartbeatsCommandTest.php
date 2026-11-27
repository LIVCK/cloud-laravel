<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use LIVCK\Cloud\Laravel\Heartbeats\CachedPingUrls;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\SyncOrders;

/**
 * @return array{0: int, 1: string}
 */
function heartbeatsCommand(): array
{
    $exitCode = Artisan::call('livck-cloud:heartbeats');

    return [$exitCode, preg_replace('/[ \t]+/', ' ', Artisan::output()) ?? ''];
}

it('lists the heartbeats, their schedule and where their URL comes from, then the problems', function (): void {
    $http = heartbeatsOn(['export:nightly' => 'https://ping.livck.test/hb_export', 'offsite' => 'https://ping.livck.test/hb_offsite']);
    config()->set('livck-cloud.heartbeats.queues', ['default']);
    app(CachedPingUrls::class)->put('feed:pull', 'https://ping.livck.test/hb_feed');
    schedule()->command('export:nightly')->dailyAt('03:00')->timezone('Europe/Berlin')->heartbeat();
    schedule()->command('feed:pull')->everyMinute()->heartbeat(every: 600);
    schedule()->call(static fn(): null => null)->hourly()->heartbeat();
    schedule()->command('report:send')->hourly()->when(static fn(): bool => true)->heartbeat();

    [$exitCode, $output] = heartbeatsCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Application')->toContain('shop')
        ->toContain('testing, heartbeats on')
        ->toContain('Last declaration')->toContain('not declared, no API token is configured')
        ->toContain('| export:nightly | schedule | 0 3 * * * (Europe/Berlin) | no, URL in config | config |')
        ->toContain('| feed:pull | schedule | every 600 s | no, no API token | cache |')
        ->toContain('| offsite | custom | - | no, URL in config | config |')
        ->toContain('| queue:default | queue | every 60 s | no, no API token | none |')
        ->toContain("The task [Closure] needs a key of its own: ->heartbeat('your-key').")
        ->toContain('The task [report:send] runs under a condition')
        ->and($output)->not->toContain('hb_export')
        ->and($http->recorded())->toBe([]);
});

it('lists a queued job with maxDuration and says the option is ignored', function (): void {
    heartbeatsOn();
    schedule()->job(new SyncOrders())->everyMinute()->heartbeat(maxDuration: 600);

    [$exitCode, $output] = heartbeatsCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('| job:sync-orders | job | * * * * * (UTC) | no, no API token | none |')
        ->toContain('The heartbeat [job:sync-orders] is a queued job')
        ->toContain('maxDuration has nothing to measure and is not declared');
});

it('says when heartbeats are off and when there are none', function (): void {
    [$exitCode, $output] = heartbeatsCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('testing, heartbeats off (livck-cloud.heartbeats.environments)')
        ->toContain('No heartbeats found.');
});

it('says yes in the declare column only when the scheduler here declares', function (): void {
    declarationsVia();
    heartbeatsOn(['offsite' => 'https://ping.livck.test/hb_offsite']);
    schedule()->command('export:nightly')->dailyAt('03:00')->heartbeat();

    [, $output] = heartbeatsCommand();

    expect($output)->toContain('never declared; the next scheduler run declares')
        ->toContain('| export:nightly | schedule | 0 3 * * * (UTC) | yes | none |')
        ->toContain('| offsite | custom | - | no, URL in config | config |')
        ->and($output)->not->toContain('no API token');
});

it('declares nothing in an environment with heartbeats off, and says so in both places', function (): void {
    declarationsVia();
    config()->set('livck-cloud.heartbeats.urls', ['offsite' => 'https://ping.livck.test/hb_offsite']);
    schedule()->command('export:nightly')->dailyAt('03:00')->heartbeat();

    [$exitCode, $output] = heartbeatsCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('testing, heartbeats off (livck-cloud.heartbeats.environments)')
        ->toContain('not declared, heartbeats are off in this environment')
        ->toContain('| export:nightly | schedule | 0 3 * * * (UTC) | no, heartbeats off | none |')
        ->toContain('| offsite | custom | - | no, URL in config | config |')
        ->and($output)->not->toContain('| yes |')
        ->and($output)->not->toContain('the next scheduler run declares');
});

it('shows a broken configuration as an error', function (): void {
    config()->set('livck-cloud.heartbeats.queues', 'default');

    [$exitCode, $output] = heartbeatsCommand();

    expect($exitCode)->toBe(2)->and($output)->toContain('livck-cloud.heartbeats.queues must be a list of queue names, got string.');
});

it('says how the last declaration relates to the code', function (): void {
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-11-27 10:00:00', 'UTC'));
    $declarations = declarationsVia([
        declared(['export:nightly' => 'https://ping.livck.test/hb_DeclaredExportUrlAndSecret0001']),
        LIVCK\Cloud\Testing\MockResponse::error('Unauthenticated.', 401),
    ]);
    heartbeatsOn();
    $event = schedule()->command('export:nightly')->dailyAt('03:00')->heartbeat();

    expect(heartbeatsCommand()[1])->toContain('never declared; the next scheduler run declares');

    app(LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor::class)->schedulerStarting();
    expect(heartbeatsCommand()[1])->toContain('matches the code (2026-11-27 10:00:00 UTC)')
        ->toContain('| export:nightly | schedule | 0 3 * * * (UTC) | yes | cache |');

    $event->dailyAt('04:00');
    expect(heartbeatsCommand()[1])->toContain('differs from the code (2026-11-27 10:00:00 UTC); the next scheduler run declares');

    app(LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor::class)->schedulerStarting();
    expect(heartbeatsCommand()[1])->toContain('paused until 2026-11-27 10:15:00 UTC: the API token is not accepted')
        ->and($declarations->recorded())->toHaveCount(2);

    Illuminate\Support\Carbon::setTestNow();
});

it('tells a missing permission apart from a limited view', function (LIVCK\Cloud\Testing\MockResponse $response, string $line, string $not): void {
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-11-27 10:00:00', 'UTC'));
    declarationsVia([$response]);
    heartbeatsOn();
    schedule()->command('export:nightly')->dailyAt('03:00')->heartbeat();

    app(LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor::class)->schedulerStarting();

    $output = heartbeatsCommand()[1];

    expect($output)->toContain('paused until 2026-11-27 10:15:00 UTC: ' . $line)
        ->and($output)->not->toContain($not);

    Illuminate\Support\Carbon::setTestNow();
})->with([
    'permission' => [LIVCK\Cloud\Testing\MockResponse::error("This token lacks the required 'heartbeats.declare' permission.", 403), 'the token lacks heartbeats.declare', 'sees only part'],
    'restricted view' => [LIVCK\Cloud\Testing\MockResponse::error('Declaring heartbeats requires a token that sees the whole organization.', 403, extra: ['code' => 'restricted_view']), 'the token sees only part of the organization', 'lacks heartbeats.declare'],
]);
