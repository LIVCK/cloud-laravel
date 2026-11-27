<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event as Events;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Heartbeats\PingClientFactory;
use LIVCK\Cloud\Laravel\Testing\RecordedPing;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\BuildReport;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\SyncOrders;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The before and after callbacks of an event.
 *
 * @return array{0: int, 1: int}
 */
function hookCount(Event $event): array
{
    $read = Closure::bind(static fn(Event $event): array => [$event->beforeCallbacks, $event->afterCallbacks], null, Event::class);
    [$before, $after] = $read($event);

    return [is_array($before) ? count($before) : 0, is_array($after) ? count($after) : 0];
}

it('pings start before the task and success after it', function (): void {
    $fake = LivckCloud::fake();
    $ran = new ArrayObject();
    schedule()->call(static function () use ($fake, $ran): void {
        $ran->append(count($fake->pings()));
    })->everyMinute()->heartbeat('cleanup');
    schedule()->exec('true')->everyMinute()->heartbeat('backup');

    expect(runSchedule())->toBe(0);

    $fake->assertPinged('cleanup', 'start', 'success')
        ->assertPinged('backup', 'start', 'success');

    expect($ran->getArrayCopy())->toBe([1])
        ->and(array_map(static fn(RecordedPing $ping): string => $ping->key . ' ' . $ping->signal, $fake->pings()))
        ->toBe(['cleanup start', 'cleanup success', 'backup start', 'backup success']);
});

it('sends the exit code of a task that fails', function (): void {
    $fake = LivckCloud::fake();
    schedule()->exec('exit 3')->everyMinute()->heartbeat('backup');
    schedule()->call(static function (): void {
        throw new RuntimeException('boom');
    })->everyMinute()->heartbeat('cleanup');
    schedule()->call(static fn(): bool => false)->everyMinute()->heartbeat('prune');

    runSchedule();

    $fake->assertPinged('backup', 'start', '3')
        ->assertPinged('cleanup', 'start', '1')
        ->assertPinged('prune', 'start', '1');

    expect($fake->pings('backup')[1]->exitCode())->toBe(3)
        ->and($fake->pings('backup')[0]->exitCode())->toBeNull();
});

it('pings a job the scheduler runs itself like a command', function (): void {
    $fake = LivckCloud::fake();
    schedule()->job(new BuildReport())->everyMinute()->heartbeat();
    schedule()->job(new BuildReport(fails: true))->everyMinute()->heartbeat('job:build-report-failing');

    runSchedule();

    $fake->assertPinged('job:build-report', 'start', 'success')
        ->assertPinged('job:build-report-failing', 'start', '1');
});

it('pings the end of a task run in the background from schedule:finish', function (): void {
    $fake = LivckCloud::fake();
    $ok = schedule()->exec('true')->everyMinute()->runInBackground()->heartbeat('bg-ok');
    $failed = schedule()->exec('exit 4')->hourly()->runInBackground()->heartbeat('bg-fail');

    // What schedule:run does for a background task before it starts the process.
    $ok->callBeforeCallbacks(app());
    $failed->callBeforeCallbacks(app());
    $fake->assertPinged('bg-ok', 'start')->assertPinged('bg-fail', 'start');

    // What the process runs when the task has ended: a schedule:finish of its own.
    Artisan::call('schedule:finish', ['id' => $ok->mutexName(), 'code' => 0]);
    Artisan::call('schedule:finish', ['id' => $failed->mutexName(), 'code' => 4]);

    $fake->assertPinged('bg-ok', 'start', 'success')
        ->assertPinged('bg-fail', 'start', '4');
});

it('pings nothing for a task that is a problem of the list', function (): void {
    $fake = LivckCloud::fake();
    schedule()->call(static fn(): null => null)->everyMinute()->heartbeat();
    schedule()->call(static fn(): null => null)->everyMinute()->when(static fn(): bool => true)->heartbeat('conditional');
    schedule()->exec('true')->everyMinute()->heartbeat('twice');
    schedule()->exec('true')->everyMinute()->heartbeat('twice');

    runSchedule();

    $fake->assertNothingPinged();
});

it('logs the problems of the list once at the start of schedule:run', function (): void {
    LivckCloud::fake();
    $logged = loggedMessages();
    schedule()->call(static fn(): null => null)->everyMinute()->heartbeat();

    Events::dispatch(new CommandStarting('schedule:finish', new ArrayInput([]), new NullOutput()));
    expect($logged->getArrayCopy())->toBe([]);

    Events::dispatch(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput()));

    expect($logged->getArrayCopy())->toBe(["warning: Heartbeat not watched: The task [Closure] needs a key of its own: ->heartbeat('your-key')."]);
});

it('logs an option a queued job cannot use as ignored, not as unwatched', function (): void {
    LivckCloud::fake();
    $logged = loggedMessages();
    schedule()->job(new SyncOrders())->everyMinute()->heartbeat(maxDuration: 600);

    Events::dispatch(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput()));

    expect($logged->getArrayCopy())->toHaveCount(1)
        ->and($logged[0])->toStartWith('warning: Heartbeat option ignored: The heartbeat [job:sync-orders] is a queued job');
});

it('skips tasks silently in maintenance mode', function (): void {
    $fake = LivckCloud::fake();
    schedule()->exec('true')->everyMinute()->heartbeat('backup');
    schedule()->exec('true')->everyMinute()->evenInMaintenanceMode()->heartbeat('even-down');
    app()->maintenanceMode()->activate([]);

    try {
        runSchedule();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    $fake->assertNotPinged('backup')->assertPinged('even-down', 'start', 'success');
});

it('keeps the task running when the ping client throws', function (): void {
    $http = heartbeatsOn(['cleanup' => 'https://ping.livck.test/hb_cleanup']);
    app()->instance(PingClientFactory::class, new PingClientFactory(new class implements ClientInterface {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new Error('the HTTP stack is broken');
        }
    }));
    $ran = new ArrayObject();
    schedule()->call(static function () use ($ran): void {
        $ran->append('ran');
    })->everyMinute()->heartbeat('cleanup');
    $logged = loggedMessages();

    expect(runSchedule())->toBe(0)
        ->and($ran->getArrayCopy())->toBe(['ran'])
        ->and($http->recorded())->toBe([])
        ->and($logged->getArrayCopy())->toContain('warning: The heartbeat [cleanup] could not be pinged (Error).');
});

it('sends real pings in an allowed environment', function (): void {
    $http = heartbeatsOn(['backup' => 'https://ping.livck.test/hb_backup', 'failing' => 'https://ping.livck.test/hb_failing']);
    schedule()->exec('true')->everyMinute()->heartbeat('backup');
    schedule()->exec('exit 2')->everyMinute()->heartbeat('failing');

    runSchedule();

    expect(pingedPaths($http))->toBe(['/hb_backup/start', '/hb_backup', '/hb_failing/start', '/hb_failing/2']);
});

it('adds no hooks outside heartbeats.environments', function (): void {
    $event = schedule()->exec('true')->everyMinute()->heartbeat('backup');

    expect(app(HeartbeatMonitor::class)->isActive())->toBeFalse()
        ->and(hookCount($event))->toBe([0, 0]);

    $http = heartbeatsOn(['backup' => 'https://ping.livck.test/hb_backup']);

    expect(hookCount($event))->toBe([1, 1])
        ->and(hookCount(schedule()->exec('true')->everyMinute()->heartbeat('later')))->toBe([1, 1]);

    app(HeartbeatMonitor::class)->activate();
    expect(hookCount($event))->toBe([1, 1]);

    runSchedule();
    expect(pingedPaths($http))->toBe(['/hb_backup/start', '/hb_backup']);
});

it('sends nothing outside heartbeats.environments', function (): void {
    $http = heartbeatsOn(['backup' => 'https://ping.livck.test/hb_backup']);
    config()->set('livck-cloud.heartbeats.environments', ['production']);
    app()->forgetInstance(HeartbeatMonitor::class);
    app(HeartbeatMonitor::class)->boot();

    schedule()->exec('true')->everyMinute()->heartbeat('backup');
    runSchedule();

    expect($http->recorded())->toBe([])
        ->and(LivckCloud::heartbeat('backup')->success()->sent)->toBeFalse()
        ->and($http->recorded())->toBe([]);
});
