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
