<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Queue;
use Throwable;
use WeakMap;

/**
 * Watches the tasks marked with `->heartbeat()`, the scheduled queue jobs and the queues.
 *
 * The macro only marks a task here. When the package is active, each marked task gets a
 * `before` and an `after` hook:
 *
 * - commands, closures, shell commands and jobs the scheduler runs itself (no ShouldQueue):
 *   `start` before, then success or the exit code. With `runInBackground()` the `after` hook
 *   runs in the `schedule:finish` process, which loads the schedule anew; nothing passes from
 *   one hook to the other.
 * - queued jobs: no ping from the scheduler. The `before` hook leaves the key for the payload
 *   of the one job the task dispatches (`Queue::createPayloadUsing`), the `after` hook clears
 *   it, so that a job dispatched anywhere else carries none. The worker sends no `start`:
 *   on `JobProcessing` nobody knows yet whether the attempt runs or releases itself
 *   (`WithoutOverlapping`, `RateLimited`), and a start would count as a run in LIVCK. It
 *   pings success on `JobProcessed` unless the job was released or failed, with the time
 *   the attempt took as `duration`, and `fail` on `JobFailed`, which comes once, after the
 *   last attempt.
 *
 * Once per `schedule:run`, before any task, the list is declared to LIVCK when that is due
 * ({@see HeartbeatDeclarer}); no other process of the application declares.
 *
 * Active means: the environment is one of `heartbeats.environments` in a console process
 * (scheduler, worker, Artisan), or `LivckCloud::fake()` was called. Elsewhere there are no
 * hooks, no listeners and no pulse.
 *
 * Nothing here throws into a task, a job or the scheduler. A singleton: the marks belong to
 * the schedule of this process, which is built once as well.
 *
 * @internal
 */
final class HeartbeatMonitor
{
    /** The key of a scheduled job in its queue payload. */
    public const string PAYLOAD_KEY = 'livck_heartbeat';

    /** @var WeakMap<Event, HeartbeatOptions> */
    private WeakMap $marks;

    /** @var WeakMap<Event, true> */
    private WeakMap $hooked;

    private bool $active = false;

    private bool $payloadHook = false;

    private ?string $pendingJob = null;

    /**
     * @param Closure(): Application $app resolves the current application
     */
    public function __construct(private readonly Closure $app)
    {
        $this->marks = new WeakMap();
        $this->hooked = new WeakMap();
    }

    /** The monitor of the current application, null when there is none. */
    public static function current(): ?self
    {
        try {
            $container = Container::getInstance();

            return $container->bound(self::class) ? $container->make(self::class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function mark(Event $event, HeartbeatOptions $options): void
    {
        $this->marks[$event] = $options;

        if ($this->active) {
            $this->hook($event);
        }
    }

    public function options(Event $event): ?HeartbeatOptions
    {
        return $this->marks[$event] ?? null;
    }

    /** Turn on when this console process runs in an environment of `heartbeats.environments`. */
    public function boot(): void
    {
        try {
            $app = $this->app();

            if ($app->runningInConsole() && $this->allowsEnvironment()) {
                $this->activate();
            }
        } catch (Throwable $e) {
            $this->warn('Heartbeats are off: {exception}', ['exception' => $e->getMessage()]);
        }
    }

    /** Hooks, listeners and the queue pulse, whatever the environment. Once. */
    public function activate(): void
    {
        if ($this->active) {
            return;
        }

        $this->active = true;

        foreach ($this->marks as $event => $options) {
            $this->hook($event);
        }

        $this->listen();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function allowsEnvironment(): bool
    {
        $app = $this->app();

        return (new HeartbeatConfig($app->make(Config::class)))->allows($app->environment());
    }

    /**
     * The heartbeats as the schedule and the configuration describe them right now.
     */
    public function list(): HeartbeatList
    {
        $app = $this->app();
        $config = new HeartbeatConfig($app->make(Config::class));
        $marked = [];

        foreach ($app->make(Schedule::class)->events() as $event) {
            $options = $this->marks[$event] ?? null;

            if ($options instanceof HeartbeatOptions) {
                $marked[] = [$event, $options];
            }
        }

        $timezone = $app->make(Config::class)->get('app.timezone');

        return (new HeartbeatListBuilder())->build(
            $config->app(),
            $app->environment(),
            $marked,
            $config->queues(),
            array_keys($config->urls()),
            is_string($timezone) && $timezone !== '' ? $timezone : date_default_timezone_get(),
            $config->custom(),
            $config->queueGrace(),
        );
    }

    /**
     * The heartbeat of a marked task and what the task is, or null when it is not watched
     * (not marked, or a problem of the list).
     *
     * @return array{0: ScheduledTask, 1: Heartbeat}|null
     */
    public function heartbeatOf(Event $event): ?array
    {
        $options = $this->marks[$event] ?? null;

        if (! $options instanceof HeartbeatOptions) {
            return null;
        }

        $task = ScheduledTask::of($event);
        $key = HeartbeatListBuilder::keyOf($task, $options);
        $heartbeat = $key === null ? null : $this->list()->get($key);

        return $heartbeat instanceof Heartbeat && $heartbeat->kind === $task->kind ? [$task, $heartbeat] : null;
    }

    /** The `before` hook of a marked task. */
    public function taskStarting(Event $event): void
    {
        if (! $this->active) {
            return;
        }

        $this->safely(function () use ($event): void {
            $found = $this->heartbeatOf($event);

            if ($found === null) {
                return;
            }

            [$task, $heartbeat] = $found;

            if ($task->queued) {
                $this->pendingJob = $heartbeat->key;
                $this->hookPayloads();

                return;
            }

            $this->pinger()->start($heartbeat->key);
        });
    }

    /** The `after` hook of a marked task; in `schedule:finish` for a task run in the background. */
    public function taskFinished(Event $event): void
    {
        $this->pendingJob = null;

        if (! $this->active) {
            return;
        }

        $this->safely(function () use ($event): void {
            $found = $this->heartbeatOf($event);

            if ($found === null || $found[0]->queued || ! is_int($event->exitCode)) {
                return;
            }

            $this->pinger()->exitCode($found[1]->key, $event->exitCode);
        });
    }

    /**
     * What the payload of a job gets: the key of the scheduled job being dispatched, once.
     *
     * @return array<string, string>
     */
    public function payload(): array
    {
        if ($this->pendingJob === null) {
            return [];
        }

        $key = $this->pendingJob;
        $this->pendingJob = null;

        return [self::PAYLOAD_KEY => $key];
    }

    /**
     * Once per `schedule:run`, before any task: the problems of the list go to the log, and
     * the list is declared to LIVCK when it is due ({@see HeartbeatDeclarer::declareIfDue()}).
     * The only place besides `livck-cloud:heartbeats:sync` that declares: a worker never does.
     */
    public function schedulerStarting(): void
    {
        if (! $this->active) {
            return;
        }

        $this->safely(function (): void {
            $list = $this->list();

            foreach ($list->problems() as $problem) {
                $this->warn($problem->reason->leavesOut() ? 'Heartbeat not watched: {problem}' : 'Heartbeat option ignored: {problem}', ['problem' => $problem->message]);
            }

            $this->app()->make(HeartbeatDeclarer::class)->declareIfDue($list);
        });
    }

    private function hook(Event $event): void
    {
        if (isset($this->hooked[$event])) {
            return;
        }

        $this->hooked[$event] = true;

        $event->before(static function () use ($event): void {
            self::current()?->taskStarting($event);
        });

        $event->after(static function () use ($event): void {
            self::current()?->taskFinished($event);
        });
    }

    private function listen(): void
    {
        $events = $this->app()->make(Dispatcher::class);

        $events->listen(CommandStarting::class, static function (CommandStarting $event): void {
            if ($event->command === 'schedule:run') {
                self::current()?->schedulerStarting();
            }
        });
    }

    private function hookPayloads(): void
    {
        if ($this->payloadHook) {
            return;
        }

        $this->payloadHook = true;

        Queue::createPayloadUsing(static fn(): array => self::current()?->payload() ?? []);
    }

    /**
     * @param Closure(): void $callback
     */
    private function safely(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->warn('A heartbeat hook failed and was skipped: {exception}', ['exception' => $e::class]);
        }
    }

    /**
     * @param array<string, string> $context
     */
    private function warn(string $message, array $context): void
    {
        try {
            HeartbeatLog::logger($this->app())->warning($message, $context);
        } catch (Throwable) {
            // Nothing else to do: the log is the last resort.
        }
    }

    private function pinger(): HeartbeatPinger
    {
        return $this->app()->make(HeartbeatPinger::class);
    }

    private function app(): Application
    {
        return ($this->app)();
    }
}
