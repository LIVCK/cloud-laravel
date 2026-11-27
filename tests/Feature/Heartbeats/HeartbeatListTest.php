<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatKind;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatList;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatProblem;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatProblemReason;
use LIVCK\Cloud\Laravel\Heartbeats\ScheduledTask;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\BuildReport;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\ImportCSVFile;
use LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats\SyncOrders;

/**
 * The list of a schedule defined by the callback, built as the package builds it.
 *
 * @param Closure(Schedule): mixed $define
 */
function listOf(Closure $define): HeartbeatList
{
    $schedule = new Schedule('UTC');
    $define($schedule);
    app()->instance(Schedule::class, $schedule);

    return app(HeartbeatMonitor::class)->list();
}

/**
 * @return list<string>
 */
function problemReasons(HeartbeatList $list): array
{
    return array_map(static fn(HeartbeatProblem $problem): string => $problem->reason->value . ' ' . ($problem->key ?? '-'), $list->problems());
}

describe('the macro', function (): void {
    it('marks command, job, call and exec, and returns the event for the rest of the chain', function (): void {
        $schedule = schedule();
        $command = $schedule->command('export:nightly --force');
        $call = $schedule->call(static fn(): null => null);

        expect($command->heartbeat())->toBe($command)
            ->and($call->heartbeat('cleanup'))->toBe($call)
            ->and(app(HeartbeatMonitor::class)->options($command)?->key)->toBeNull()
            ->and(app(HeartbeatMonitor::class)->options($call)?->key)->toBe('cleanup');

        $list = listOf(static function (Schedule $schedule): void {
            $schedule->command('export:nightly --force')->dailyAt('03:00')->timezone('Europe/Berlin')->heartbeat();
            $schedule->job(new SyncOrders())->everyFiveMinutes()->heartbeat();
            $schedule->job(BuildReport::class)->hourly()->heartbeat(maxDuration: 900);
            $schedule->call(static fn(): null => null)->hourly()->heartbeat('report:build', grace: 120);
            $schedule->exec('/usr/local/bin/backup.sh --full')->daily()->heartbeat('backup', tolerance: 2);
        });

        expect($list->problems())->toBe([])
            ->and($list->toArray())->toBe([
                'app' => 'shop',
                'environment' => 'testing',
                'heartbeats' => [
                    ['key' => 'backup', 'kind' => 'schedule', 'schedule' => ['type' => 'cron', 'expression' => '0 0 * * *', 'timezone' => 'UTC'], 'schedule_tolerance' => 2],
                    ['key' => 'export:nightly', 'kind' => 'schedule', 'schedule' => ['type' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin']],
                    ['key' => 'job:build-report', 'kind' => 'job', 'schedule' => ['type' => 'cron', 'expression' => '0 * * * *', 'timezone' => 'UTC'], 'max_duration_seconds' => 900],
                    ['key' => 'job:sync-orders', 'kind' => 'job', 'schedule' => ['type' => 'cron', 'expression' => '*/5 * * * *', 'timezone' => 'UTC']],
                    ['key' => 'report:build', 'kind' => 'schedule', 'schedule' => ['type' => 'cron', 'expression' => '0 * * * *', 'timezone' => 'UTC'], 'grace_seconds' => 120],
                ],
            ]);
    });

    it('reads cron and timezone when the list is built, so the order of the chain does not matter', function (): void {
        $after = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly')->heartbeat()->dailyAt('03:00')->timezone('Europe/Berlin'));
        $before = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly')->dailyAt('03:00')->timezone('Europe/Berlin')->heartbeat());

        expect($after->get('export:nightly')?->schedule?->toArray())->toBe(['type' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin'])
            ->and($after->toArray())->toBe($before->toArray())
            ->and($after->hash())->toBe($before->hash());
    });

    it('ignores events nobody marked', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->command('export:nightly')->daily();
            $schedule->command('feed:pull')->everyMinute()->heartbeat();
        });

        expect($list->keys())->toBe(['feed:pull']);
    });

    it('recognises the closure Schedule::job() builds on this framework version', function (): void {
        $schedule = new Schedule('UTC');

        $queued = ScheduledTask::of($schedule->job(new SyncOrders()));
        $byName = ScheduledTask::of($schedule->job(SyncOrders::class, 'reports', 'database'));
        $sync = ScheduledTask::of($schedule->job(new BuildReport()));
        $closure = ScheduledTask::of($schedule->call(static fn(): null => null));

        expect([$queued->kind, $queued->queued, $queued->defaultKey])->toBe([HeartbeatKind::Job, true, 'job:sync-orders'])
            ->and([$byName->kind, $byName->queued, $byName->defaultKey])->toBe([HeartbeatKind::Job, true, 'job:sync-orders'])
            ->and([$sync->kind, $sync->queued, $sync->defaultKey])->toBe([HeartbeatKind::Job, false, 'job:build-report'])
            ->and([$closure->kind, $closure->queued, $closure->defaultKey])->toBe([HeartbeatKind::Schedule, false, null])
            ->and($schedule->events()[0])->toBeInstanceOf(CallbackEvent::class);
    });
});

describe('keys', function (): void {
    it('names a command by its name without arguments', function (): void {
        $list = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly --force --chunk=500')->daily()->heartbeat());

        expect($list->keys())->toBe(['export:nightly']);
    });

    it('names a job by its class in lower case with hyphens', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->job(new SyncOrders())->everyMinute()->heartbeat();
            $schedule->job(new ImportCSVFile())->hourly()->heartbeat();
        });

        expect($list->keys())->toBe(['job:import-csv-file', 'job:sync-orders']);
    });

    it('names a queue by its name, and by its connection too when that is not the default', function (): void {
        config()->set('queue.default', 'redis');
        config()->set('livck-cloud.heartbeats.queues', ['default', 'mails', 'sqs' => ['reports'], 'redis' => 'high']);

        $list = listOf(static fn(): null => null);

        expect($list->keys())->toBe(['queue:default', 'queue:high', 'queue:mails', 'queue:sqs:reports'])
            ->and($list->get('queue:sqs:reports')?->kind)->toBe(HeartbeatKind::Queue)
            ->and($list->get('queue:default')?->schedule?->toArray())->toBe(['type' => 'simple', 'period_seconds' => 60]);
    });

    it('gives each queue the grace of heartbeats.queue_grace, 120 seconds by default', function (): void {
        config()->set('livck-cloud.heartbeats.queues', ['default']);

        expect(listOf(static fn(): null => null)->toArray()['heartbeats'])->toBe([
            ['key' => 'queue:default', 'kind' => 'queue', 'schedule' => ['type' => 'simple', 'period_seconds' => 60], 'grace_seconds' => 120],
        ]);

        config()->set('livck-cloud.heartbeats.queue_grace', '300');

        expect(app(HeartbeatMonitor::class)->list()->get('queue:default')?->graceSeconds)->toBe(300);

        config()->set('livck-cloud.heartbeats.queue_grace', null);

        expect(app(HeartbeatMonitor::class)->list()->get('queue:default')?->graceSeconds)->toBe(120);
    });

    it('leaves a queue out when heartbeats.queue_grace is below what LIVCK takes', function (): void {
        config()->set('livck-cloud.heartbeats.queues', ['default']);
        config()->set('livck-cloud.heartbeats.queue_grace', 10);

        $list = listOf(static fn(): null => null);

        expect($list->keys())->toBe([])
            ->and(problemReasons($list))->toBe(['invalid_option queue:default'])
            ->and($list->problems()[0]->message)->toBe('livck-cloud.heartbeats.queue_grace is 10; LIVCK takes at least 30 seconds.');
    });

    it('watches a queued job without the maxDuration it cannot measure, and says so', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->job(new SyncOrders())->everyFiveMinutes()->heartbeat(grace: 120, maxDuration: 600);
            $schedule->job(BuildReport::class)->hourly()->heartbeat(maxDuration: 900);
        });

        expect($list->toArray()['heartbeats'])->toBe([
            ['key' => 'job:build-report', 'kind' => 'job', 'schedule' => ['type' => 'cron', 'expression' => '0 * * * *', 'timezone' => 'UTC'], 'max_duration_seconds' => 900],
            ['key' => 'job:sync-orders', 'kind' => 'job', 'schedule' => ['type' => 'cron', 'expression' => '*/5 * * * *', 'timezone' => 'UTC'], 'grace_seconds' => 120],
        ])
            ->and(problemReasons($list))->toBe(['ignored_option job:sync-orders'])
            ->and($list->problems()[0]->reason->leavesOut())->toBeFalse()
            ->and($list->problems()[0]->message)->toContain('is a queued job')->toContain('maxDuration');
    });

    it('takes the key given over the one the task implies', function (): void {
        $list = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly')->daily()->heartbeat('  nightly-export  '));

        expect($list->keys())->toBe(['nightly-export']);
    });

    it('needs a key for a closure and a shell command, and says so instead of throwing', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->call(static fn(): null => null)->hourly()->heartbeat();
            $schedule->call(static fn(): null => null)->name('prune caches')->hourly()->heartbeat();
            $schedule->exec('/usr/local/bin/backup.sh')->daily()->heartbeat();
            $schedule->command('export:nightly')->daily()->heartbeat('   ');
        });

        expect($list->heartbeats())->toBe([])
            ->and(problemReasons($list))->toBe(['missing_key -', 'missing_key -', 'missing_key -', 'missing_key -'])
            ->and($list->problems()[0]->message)->toBe("The task [Closure] needs a key of its own: ->heartbeat('your-key').")
            ->and($list->problems()[1]->task)->toBe('prune caches')
            ->and($list->problems()[2]->task)->toBe('/usr/local/bin/backup.sh');
    });

    it('lists a key used twice as a problem of both tasks, and watches neither', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->command('export:nightly --region=eu')->daily()->heartbeat();
            $schedule->command('export:nightly --region=us')->daily()->heartbeat();
            $schedule->command('feed:pull')->hourly()->heartbeat('queue:default');
        });

        config()->set('livck-cloud.heartbeats.queues', ['default']);
        $withQueue = app(HeartbeatMonitor::class)->list();

        expect($list->keys())->toBe(['queue:default'])
            ->and(problemReasons($list))->toBe(['duplicate_key export:nightly', 'duplicate_key export:nightly'])
            ->and($list->problems()[0]->message)->toContain('The key [export:nightly] is used 2 times.')
            ->and($withQueue->keys())->toBe([])
            ->and(problemReasons($withQueue))->toContain('duplicate_key queue:default');
    });

    it('lets two runs of one command apart with keys of their own', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->command('export:nightly --region=eu')->daily()->heartbeat('export:eu');
            $schedule->command('export:nightly --region=us')->daily()->heartbeat('export:us');
        });

        expect($list->keys())->toBe(['export:eu', 'export:us'])->and($list->problems())->toBe([]);
    });

    it('turns an option below 1 into a problem', function (): void {
        $list = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly')->daily()->heartbeat(grace: 0, every: -5));

        expect(problemReasons($list))->toBe(['invalid_option export:nightly'])
            ->and($list->problems()[0]->message)->toBe('The heartbeat [export:nightly] has grace, every below 1; they take positive numbers.');
    });
});

describe('schedules', function (): void {
    it('needs every: for a task under a condition, and then takes it as a plain period', function (Closure $condition): void {
        $under = static function (Event $event) use ($condition): Event {
            $conditioned = $condition($event);

            return $conditioned instanceof Event ? $conditioned : $event;
        };

        $without = listOf(static fn(Schedule $schedule): Event => $under($schedule->command('feed:pull')->everyMinute())->heartbeat());
        $with = listOf(static fn(Schedule $schedule): Event => $under($schedule->command('feed:pull')->everyMinute())->heartbeat(every: 3600));

        expect($without->keys())->toBe([])
            ->and(problemReasons($without))->toBe(['conditional feed:pull'])
            ->and($without->problems()[0]->message)->toContain('->heartbeat(every: 3600)')
            ->and($with->get('feed:pull')?->schedule?->toArray())->toBe(['type' => 'simple', 'period_seconds' => 3600])
            ->and($with->problems())->toBe([]);
    })->with([
        'when' => [static fn(Event $event): Event => $event->when(static fn(): bool => true)],
        'skip' => [static fn(Event $event): Event => $event->skip(static fn(): bool => false)],
        'between' => [static fn(Event $event): Event => $event->between('08:00', '18:00')],
        'unlessBetween' => [static fn(Event $event): Event => $event->unlessBetween('23:00', '04:00')],
    ]);

    it('does not take withoutOverlapping() for a condition', function (): void {
        $list = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly')->daily()->withoutOverlapping()->onOneServer()->heartbeat());

        expect($list->get('export:nightly')?->schedule?->toArray())->toBe(['type' => 'cron', 'expression' => '0 0 * * *', 'timezone' => 'UTC'])
            ->and($list->problems())->toBe([]);
    });

    it('takes every: over the cron of a task without a condition', function (): void {
        $list = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly')->daily()->heartbeat(every: 7200));

        expect($list->get('export:nightly')?->schedule?->toArray())->toBe(['type' => 'simple', 'period_seconds' => 7200]);
    });

    it('takes a task that repeats within the minute as a plain period', function (): void {
        $list = listOf(static fn(Schedule $schedule): Event => $schedule->command('feed:pull')->everyThirtySeconds()->heartbeat());

        expect($list->get('feed:pull')?->schedule?->toArray())->toBe(['type' => 'simple', 'period_seconds' => 30]);
    });

    it('takes the application timezone when the task has none', function (): void {
        config()->set('app.timezone', 'Europe/Vienna');
        $schedule = new Schedule();
        $schedule->command('export:nightly')->dailyAt('02:30')->heartbeat();
        app()->instance(Schedule::class, $schedule);

        expect(app(HeartbeatMonitor::class)->list()->get('export:nightly')?->schedule?->toArray())
            ->toBe(['type' => 'cron', 'expression' => '30 2 * * *', 'timezone' => 'Europe/Vienna']);
    });

    it('leaves out a task that does not run in this environment, silently', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->command('export:nightly')->daily()->environments(['production'])->heartbeat();
            $schedule->command('feed:pull')->hourly()->environments(['testing', 'production'])->heartbeat();
        });

        expect($list->keys())->toBe(['feed:pull'])->and($list->problems())->toBe([]);
    });
});

describe('the list', function (): void {
    it('adds a key of heartbeats.urls that nothing else has as a custom heartbeat', function (): void {
        config()->set('livck-cloud.heartbeats.urls', [
            'export:nightly' => 'https://ping.livck.test/hb_export',
            'backup-offsite' => 'https://ping.livck.test/hb_backup',
            'unset' => null,
        ]);

        $list = listOf(static fn(Schedule $schedule): Event => $schedule->command('export:nightly')->daily()->heartbeat());

        expect($list->keys())->toBe(['backup-offsite', 'export:nightly'])
            ->and($list->get('backup-offsite')?->kind)->toBe(HeartbeatKind::Custom)
            ->and($list->get('backup-offsite')?->toArray())->toBe(['key' => 'backup-offsite', 'kind' => 'custom', 'schedule' => null])
            ->and($list->get('export:nightly')?->kind)->toBe(HeartbeatKind::Schedule);
    });

    it('takes the application name as a slug when heartbeats.app is not set', function (): void {
        config()->set('livck-cloud.heartbeats.app', null);
        config()->set('app.name', 'Acme Shop');

        expect(listOf(static fn(): null => null)->app)->toBe('acme-shop');
    });

    it('keeps the same hash for the same list and changes it with a schedule, a key or an option', function (): void {
        $define = static fn(string $time, ?int $grace = null, string $key = 'export:nightly'): Closure => static function (Schedule $schedule) use ($time, $grace, $key): void {
            $schedule->command('feed:pull')->hourly()->heartbeat();
            $schedule->command('export:nightly')->dailyAt($time)->heartbeat($key, grace: $grace);
        };

        $hash = listOf($define('03:00'))->hash();

        expect(listOf($define('03:00'))->hash())->toBe($hash)
            ->and($hash)->toMatch('/\A[0-9a-f]{64}\z/')
            ->and(listOf($define('04:00'))->hash())->not->toBe($hash)
            ->and(listOf($define('03:00', 300))->hash())->not->toBe($hash)
            ->and(listOf($define('03:00', null, 'export'))->hash())->not->toBe($hash);
    });

    it('does not depend on the order the tasks were defined in', function (): void {
        $first = listOf(static function (Schedule $schedule): void {
            $schedule->command('feed:pull')->hourly()->heartbeat();
            $schedule->command('export:nightly')->daily()->heartbeat();
        });
        $second = listOf(static function (Schedule $schedule): void {
            $schedule->command('export:nightly')->daily()->heartbeat();
            $schedule->command('feed:pull')->hourly()->heartbeat();
        });

        expect($first->hash())->toBe($second->hash())->and($first->keys())->toBe(['export:nightly', 'feed:pull']);
    });

    it('filters while keeping the problems', function (): void {
        $list = listOf(static function (Schedule $schedule): void {
            $schedule->command('feed:pull')->hourly()->heartbeat();
            $schedule->command('export:nightly')->daily()->heartbeat();
            $schedule->call(static fn(): null => null)->daily()->heartbeat();
        });

        $filtered = $list->filter(static fn($heartbeat): bool => $heartbeat->key !== 'feed:pull');

        expect($filtered->keys())->toBe(['export:nightly'])
            ->and($filtered->problems())->toBe($list->problems())
            ->and(count($list))->toBe(2)
            ->and(iterator_to_array($list))->toHaveKeys(['export:nightly', 'feed:pull'])
            ->and($list->problems()[0]->reason)->toBe(HeartbeatProblemReason::MissingKey);
    });
});
