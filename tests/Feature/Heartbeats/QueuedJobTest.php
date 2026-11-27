<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\BlockedJob;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\FailingJob;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\FlakyJob;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\ReleasingJob;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\SyncOrders;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\UniqueReport;

beforeEach(function (): void {
    useDatabaseQueue();
    BlockedJob::$blocked = true;
    BlockedJob::$takes = 0.0;
});

it('does not ping when the scheduler queues the job, and pings success once a worker has run it', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new SyncOrders())->everyMinute()->heartbeat();

    runSchedule();

    $fake->assertNothingPinged();
    expect(queuedPayloads())->toHaveCount(1)
        ->and(queuedPayloads()[0][HeartbeatMonitor::PAYLOAD_KEY] ?? null)->toBe('job:sync-orders');

    work();

    $fake->assertPinged('job:sync-orders', 'success');
    expect(DB::table('jobs')->count())->toBe(0);
});

it('marks only the job the task queues, not its children', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new SyncOrders(withChild: true))->everyMinute()->heartbeat();

    runSchedule();
    work();

    $payloads = queuedPayloads();

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['displayName'] ?? null)->toContain('ChildJob')
        ->and($payloads[0])->not->toHaveKey(HeartbeatMonitor::PAYLOAD_KEY);

    work();
    $fake->assertPinged('job:sync-orders', 'success');
    expect($fake->pings())->toHaveCount(1);
});

it('leaves no mark behind when a unique job was not queued again', function (): void {
    LivckCloud::fake();
    schedule()->job(new UniqueReport())->everyMinute()->heartbeat();

    runSchedule();
    runSchedule(); // the lock is still held: no payload this time
    SyncOrders::dispatch();

    expect(array_map(static fn(array $payload): mixed => $payload[HeartbeatMonitor::PAYLOAD_KEY] ?? null, queuedPayloads()))
        ->toBe(['job:unique-report', null]);
});

it('does not ping for the same job queued by hand', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new SyncOrders())->everyMinute()->heartbeat();

    SyncOrders::dispatch();
    work();

    $fake->assertNothingPinged();
});

it('sends nothing for a job that released itself, and success once it has run', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new ReleasingJob())->everyMinute()->heartbeat();

    runSchedule();
    work();

    $fake->assertNothingPinged();
    expect(DB::table('jobs')->count())->toBe(1);

    work();

    $fake->assertPinged('job:releasing-job', 'success');
});

it('sends nothing for a job that only releases itself, not even on its hundredth attempt', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new BlockedJob())->everyMinute()->heartbeat();

    runSchedule();

    for ($attempt = 1; $attempt <= 100; $attempt++) {
        work();
    }

    $fake->assertNothingPinged();
    expect(DB::table('jobs')->value('attempts'))->toBe(100);
});

it('pings one success with the time the attempt that ran took', function (): void {
    $fake = LivckCloud::fake();
    Carbon::setTestNow(Carbon::now());
    schedule()->job(new BlockedJob())->everyMinute()->heartbeat();

    runSchedule();
    work();
    work();

    // A long wait between the attempts is no part of the run.
    Carbon::setTestNow(Carbon::now()->addSeconds(50));
    BlockedJob::$blocked = false;
    BlockedJob::$takes = 7.25;
    work();

    $fake->assertPinged('job:blocked-job', 'success');
    expect($fake->pings('job:blocked-job')[0]->metrics())->toBe(['duration' => 7.25])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('gives no failure for an attempt that is retried', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new FlakyJob())->everyMinute()->heartbeat();

    runSchedule();
    work();

    $fake->assertNothingPinged();

    work();

    $fake->assertPinged('job:flaky-job', 'success');
});

it('fails only when the last attempt has failed', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new FailingJob())->everyMinute()->heartbeat();

    runSchedule();
    work();

    $fake->assertNothingPinged();

    work();

    $fake->assertPinged('job:failing-job', 'fail');
    expect(DB::table('jobs')->count())->toBe(0);
});

it('pings from the worker through the URL it knows', function (): void {
    $http = heartbeatsOn(['job:sync-orders' => 'https://ping.livck.test/hb_orders']);
    schedule()->job(new SyncOrders())->everyMinute()->heartbeat();

    runSchedule();
    expect($http->recorded())->toBe([]);

    work();
    expect(pingedPaths($http))->toBe(['/hb_orders']);
});

it('ignores the mark in a worker outside heartbeats.environments', function (): void {
    $http = heartbeatsOn(['job:sync-orders' => 'https://ping.livck.test/hb_orders']);
    schedule()->job(new SyncOrders())->everyMinute()->heartbeat();
    runSchedule();

    // The worker of another environment: a monitor of its own that never turned on.
    config()->set('livck-cloud.heartbeats.environments', ['production']);
    app()->forgetInstance(HeartbeatMonitor::class);
    app(HeartbeatMonitor::class)->boot();

    work();

    expect(queuedPayloads())->toBe([])->and($http->recorded())->toBe([]);
});
