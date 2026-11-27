<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Throwable;
use WeakMap;

/**
 * Watches the tasks marked with `->heartbeat()`, the scheduled queue jobs and the queues.
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
    /** @var WeakMap<Event, HeartbeatOptions> */
    private WeakMap $marks;

    private bool $active = false;

    /**
     * @param Closure(): Application $app resolves the current application
     */
    public function __construct(private readonly Closure $app)
    {
        $this->marks = new WeakMap();
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

    private function app(): Application
    {
        return ($this->app)();
    }
}
