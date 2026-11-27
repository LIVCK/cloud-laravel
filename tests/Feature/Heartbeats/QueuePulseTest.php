<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\QueuePulse;

beforeEach(function (): void {
    useDatabaseQueue();
    config()->set('livck-cloud.heartbeats.queues', ['default', 'mails']);
});

/**
 * @return list<string>
 */
function pulseEvents(): array
{
    return array_values(array_filter(array_map(static fn(Event $event): string => (string) $event->description, schedule()->events()), static fn(string $name): bool => str_starts_with($name, 'livck-cloud:queue-pulse')));
}

it('adds one pulse per queue to the schedule, every minute', function (): void {
    LivckCloud::fake();

    expect(pulseEvents())->toBe(['livck-cloud:queue-pulse queue:default', 'livck-cloud:queue-pulse queue:mails'])
        ->and(array_values(array_unique(array_map(static fn(Event $event): string => $event->expression, schedule()->events()))))->toBe(['* * * * *']);
});

it('keeps at most one pulse waiting per queue', function (): void {
    LivckCloud::fake();

    runSchedule();
    runSchedule();
    runSchedule();

    expect(DB::table('jobs')->orderBy('queue')->pluck('queue')->all())->toBe(['default', 'mails']);
});

it('pings the queue with the time the pulse waited, then queues the next one', function (): void {
    $fake = LivckCloud::fake();
    Carbon::setTestNow(Carbon::now());

    runSchedule();
    Carbon::setTestNow(Carbon::now()->addSeconds(5));
    work('default');

    $fake->assertPinged('queue:default', 'success')->assertNotPinged('queue:mails');
    expect($fake->pings('queue:default')[0]->metrics())->toBe(['wait_seconds' => 5.0]);

    runSchedule();

    expect(DB::table('jobs')->orderBy('queue')->pluck('queue')->all())->toBe(['default', 'mails']);
});

it('pulses a queue of another connection under its own key', function (): void {
    config()->set('queue.connections.second', config('queue.connections.database'));
    config()->set('livck-cloud.heartbeats.queues', ['second' => ['reports']]);
    $fake = LivckCloud::fake();

    runSchedule();
    Artisan::call('queue:work', ['connection' => 'second', '--once' => true, '--queue' => 'reports', '--sleep' => 0]);

    $fake->assertPinged('queue:second:reports', 'success');
});

it('locks the pulse in the heartbeat cache store for a limited time', function (): void {
    config()->set('cache.stores.shared', ['driver' => 'array']);
    config()->set('livck-cloud.heartbeats.cache_store', 'shared');
    $pulse = new QueuePulse('queue:default', 'database', 'default', 0.0);

    expect($pulse->uniqueId())->toBe('queue:default')
        ->and($pulse->uniqueFor())->toBe(QueuePulse::UNIQUE_FOR)
        ->and($pulse->uniqueVia())->toBe(app('cache')->store('shared'))
        ->and($pulse->displayName())->toBe('LIVCK Cloud queue pulse [queue:default]');
});

it('adds no pulse outside heartbeats.environments', function (): void {
    runSchedule();

    expect(pulseEvents())->toBe([])->and(DB::table('jobs')->count())->toBe(0);
});

it('pings through the URL of the queue in an allowed environment', function (): void {
    $http = heartbeatsOn(['queue:default' => 'https://ping.livck.test/hb_queue']);

    runSchedule();
    work('default');

    expect(pingedPaths($http))->toBe(['/hb_queue'])
        ->and($http->recorded()[0]->json())->toHaveKey('metrics.wait_seconds');
});
