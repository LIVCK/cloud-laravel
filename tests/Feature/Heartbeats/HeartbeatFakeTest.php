<?php

declare(strict_types=1);

use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
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
