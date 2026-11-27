<?php

declare(strict_types=1);

use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\Heartbeat;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatDeclarer;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatKind;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatList;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatSchedule;
use LIVCK\Cloud\Laravel\Heartbeats\PingUrlSource;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use LIVCK\Cloud\Ping\PingDetails;
use LIVCK\Cloud\Testing\ExpectationFailedException;

it('records pings instead of sending them, with or without a URL, and turns heartbeats on', function (): void {
    config()->set('livck-cloud.heartbeats.urls', ['backup' => 'https://ping.livck.test/hb_backup']);
    expect(app(HeartbeatMonitor::class)->isActive())->toBeFalse();

    $fake = LivckCloud::fake();
    LivckCloud::heartbeat('backup')->start();
    LivckCloud::heartbeat('nightly')->success(PingDetails::make()->withMetric('rows', 3));

    expect(app(HeartbeatMonitor::class)->isActive())->toBeTrue()
        ->and($fake->pings())->toHaveCount(2)
        ->and($fake->pings('backup')[0]->source)->toBe(PingUrlSource::Config)
        ->and($fake->pings('nightly')[0]->source)->toBeNull()
        ->and($fake->pings('nightly')[0]->metrics())->toBe(['rows' => 3]);

    $fake->assertNothingSent();
});

it('asserts what each heartbeat got', function (): void {
    $fake = LivckCloud::fake();
    LivckCloud::heartbeat('export:nightly')->start();
    LivckCloud::heartbeat('export:nightly')->exitCode(3);

    $fake->assertPinged('export:nightly')
        ->assertPinged('export:nightly', 'start', '3')
        ->assertNotPinged('feed:pull');

    LivckCloud::assertPinged('export:nightly', 'start', '3');
    LivckCloud::assertNotPinged('feed:pull');
});

it('fails with what was pinged', function (Closure $assertion, string $message): void {
    LivckCloud::fake();
    LivckCloud::heartbeat('export:nightly')->start();

    expect($assertion)->toThrow(ExpectationFailedException::class, $message);
})->with([
    'not pinged' => [fn(): CloudFake => LivckCloud::assertPinged('feed:pull'), 'The heartbeat [feed:pull] was not pinged. Pinged: export:nightly start'],
    'other signals' => [fn(): CloudFake => LivckCloud::assertPinged('export:nightly', 'start', 'success'), 'The heartbeat [export:nightly] got [start], expected [start, success].'],
    'pinged' => [fn(): CloudFake => LivckCloud::assertNotPinged('export:nightly'), 'The heartbeat [export:nightly] was pinged: [start].'],
    'something' => [fn(): CloudFake => LivckCloud::assertNothingPinged(), 'Expected no heartbeat ping. Pinged: export:nightly start'],
]);

it('passes when nothing was pinged', function (): void {
    LivckCloud::fake()->assertNothingPinged();
    LivckCloud::assertNothingPinged();
});

it('starts over when faked again', function (): void {
    $first = LivckCloud::fake();
    LivckCloud::heartbeat('backup')->start();
    $second = LivckCloud::fake();

    expect($first->pings())->toHaveCount(1)->and($second->pings())->toBe([]);
});

it('needs a fake to assert against', function (): void {
    expect(fn(): CloudFake => app(CloudManager::class)->assertNothingPinged())->toThrow(LogicException::class);
});

describe('declarations', function (): void {
    it('answers the scheduler\'s declaration, so that the tasks are pinged with the URL it returned', function (): void {
        $fake = LivckCloud::fake();
        schedule()->exec('true')->everyMinute()->heartbeat('export:nightly', maxDuration: 900);

        runSchedule();

        $fake->assertDeclared('export:nightly')
            ->assertPinged('export:nightly', 'start', 'success')
            ->assertNothingSent();

        expect($fake->declarations())->toHaveCount(1)
            ->and($fake->declarations()[0]->app)->toBe('shop')
            ->and($fake->declarations()[0]->environment)->toBe('testing')
            ->and($fake->declarations()[0]->heartbeat('export:nightly'))->toBe([
                'key' => 'export:nightly',
                'kind' => 'schedule',
                'schedule' => ['type' => 'cron', 'expression' => '* * * * *', 'timezone' => 'UTC'],
                'max_duration_seconds' => 900,
            ])
            ->and($fake->pings('export:nightly')[0]->source)->toBe(PingUrlSource::Cache);
    });

    it('answers like LIVCK: created, unchanged, updated and unlisted', function (): void {
        $fake = LivckCloud::fake();
        $list = static fn(Heartbeat ...$heartbeats): HeartbeatList => new HeartbeatList('shop', 'production', $heartbeats);
        $daily = new Heartbeat('export:nightly', HeartbeatKind::Schedule, HeartbeatSchedule::cron('0 3 * * *', 'UTC'));
        $declarer = app(HeartbeatDeclarer::class);

        $first = $declarer->declare($list($daily, new Heartbeat('report:send', HeartbeatKind::Schedule, HeartbeatSchedule::simple(3600))))->result;
        $second = $declarer->declare($list(new Heartbeat('export:nightly', HeartbeatKind::Schedule, HeartbeatSchedule::cron('0 4 * * *', 'UTC'))))->result;
        $third = $declarer->declare($list(new Heartbeat('export:nightly', HeartbeatKind::Schedule, HeartbeatSchedule::cron('0 4 * * *', 'UTC'))))->result;

        expect(array_map(static fn($heartbeat): string => $heartbeat->result->value, $first->heartbeats ?? []))->toBe(['created', 'created'])
            ->and($first?->pingUrls())->toHaveKeys(['export:nightly', 'report:send'])
            ->and($second?->heartbeats[0]->result->value)->toBe('updated')
            ->and($second?->unlistedKeys())->toBe(['report:send'])
            ->and($third?->heartbeats[0]->result->value)->toBe('unchanged')
            ->and($third?->pingUrls()['export:nightly']->reveal())->toBe($first?->pingUrls()['export:nightly']->reveal())
            ->and($third?->allExist())->toBeTrue();

        $fake->assertDeclared('report:send')->assertDeclared('export:nightly', 'report:send');
    });

    it('declares without a token', function (): void {
        useConnection('default', ['token' => null]);
        $fake = LivckCloud::fake();
        config()->set('livck-cloud.heartbeats.custom', ['import' => 600]);

        app(HeartbeatMonitor::class)->schedulerStarting();

        $fake->assertDeclared('import');
    });

    it('fails with what was declared', function (Closure $assertion, string $message): void {
        LivckCloud::fake();
        schedule()->command('export:nightly')->daily()->heartbeat();
        app(HeartbeatMonitor::class)->schedulerStarting();

        expect($assertion)->toThrow(ExpectationFailedException::class, $message);
    })->with([
        'other keys' => [fn(): CloudFake => LivckCloud::assertDeclared('export:nightly', 'feed:pull'), 'No declaration named [export:nightly, feed:pull]. Declared: [export:nightly]'],
        'declared' => [fn(): CloudFake => LivckCloud::assertNotDeclared('export:nightly'), 'The heartbeat [export:nightly] was declared. Declared: [export:nightly]'],
        'something' => [fn(): CloudFake => LivckCloud::assertNothingDeclared(), 'Expected no declaration of heartbeats. Declared: [export:nightly]'],
    ]);

    it('fails when nothing was declared, and passes the opposite', function (): void {
        $fake = LivckCloud::fake();

        expect(fn(): CloudFake => $fake->assertDeclared())->toThrow(ExpectationFailedException::class, 'No heartbeats were declared.');

        $fake->assertNothingDeclared()->assertNotDeclared('export:nightly');
        LivckCloud::assertNothingDeclared();
    });

    it('records nothing when every heartbeat has a URL in the configuration', function (): void {
        config()->set('livck-cloud.heartbeats.urls', ['export:nightly' => 'https://ping.livck.test/hb_export']);
        $fake = LivckCloud::fake();
        schedule()->exec('true')->everyMinute()->heartbeat('export:nightly');

        runSchedule();

        $fake->assertNothingDeclared()->assertPinged('export:nightly', 'start', 'success');
    });
});
