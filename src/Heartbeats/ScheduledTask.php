<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use DateTimeZone;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * What a scheduled event is, read from the event as it stands: its kind, the key it gets
 * without an explicit one, whether it runs under a condition, its cron and timezone.
 *
 * Read when the list is built, never when `->heartbeat()` is called: the macro sees only what
 * comes before it in the chain.
 *
 * A job is recognised by the closure `Schedule::job()` builds, whose variables hold the job.
 * That closure is not a public API of the framework; the tests pin it for every supported
 * version.
 *
 * @internal
 */
final readonly class ScheduledTask
{
    private function __construct(
        public HeartbeatKind $kind,
        public bool $queued,
        public ?string $defaultKey,
        public string $label,
        public bool $conditional,
        private Event $event,
    ) {}

    public static function of(Event $event): self
    {
        $job = self::job($event);

        if ($job !== null) {
            return new self(
                HeartbeatKind::Job,
                is_a($job, ShouldQueue::class, true),
                'job:' . self::kebab(self::basename($job)),
                $job,
                self::conditional($event),
                $event,
            );
        }

        if ($event instanceof CallbackEvent) {
            $description = is_string($event->description) && trim($event->description) !== '' ? $event->description : 'Closure';

            return new self(HeartbeatKind::Schedule, false, null, $description, self::conditional($event), $event);
        }

        $command = is_string($event->command) ? $event->command : '';
        $prefix = Artisan::formatCommandString('');

        if (str_starts_with($command, $prefix)) {
            $arguments = trim(substr($command, strlen($prefix)));
            $name = strtok($arguments, ' ');

            return new self(HeartbeatKind::Schedule, false, $name === false ? null : $name, $arguments, self::conditional($event), $event);
        }

        return new self(HeartbeatKind::Schedule, false, null, $command, self::conditional($event), $event);
    }

    public function runsIn(string $environment): bool
    {
        return $this->event->runsInEnvironment($environment);
    }

    /**
     * The cron and timezone of the event; a task that repeats within the minute
     * (`everyThirtySeconds()`) as a plain period.
     */
    public function schedule(string $fallbackTimezone): HeartbeatSchedule
    {
        $repeat = $this->event->repeatSeconds;

        if (is_int($repeat) && $repeat > 0) {
            return HeartbeatSchedule::simple($repeat);
        }

        $timezone = $this->event->timezone;
        $timezone = match (true) {
            $timezone instanceof DateTimeZone => $timezone->getName(),
            is_string($timezone) && $timezone !== '' => $timezone,
            default => $fallbackTimezone,
        };

        return HeartbeatSchedule::cron((string) $this->event->expression, $timezone);
    }

    /**
     * The class of the job when the event comes from `Schedule::job()`.
     *
     * @return class-string|null
     */
    private static function job(Event $event): ?string
    {
        if (! $event instanceof CallbackEvent) {
            return null;
        }

        try {
            $callback = Closure::bind(static fn(CallbackEvent $event): mixed => $event->callback, null, CallbackEvent::class)($event);

            if (! $callback instanceof Closure) {
                return null;
            }

            $reflection = new ReflectionFunction($callback);

            if ($reflection->getClosureScopeClass()?->getName() !== Schedule::class) {
                return null;
            }

            $used = $reflection->getClosureUsedVariables();

            if (! array_key_exists('job', $used) || ! array_key_exists('queue', $used) || ! array_key_exists('connection', $used)) {
                return null;
            }

            $job = $used['job'];

            if (is_object($job)) {
                return $job::class;
            }

            return is_string($job) && class_exists($job) ? $job : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a filter or reject decides at run time if the task runs: `when()`, `skip()`,
     * `between()`, `unlessBetween()`. The reject that `withoutOverlapping()` adds is not one.
     */
    private static function conditional(Event $event): bool
    {
        /** @var array{0: array<array-key, mixed>, 1: array<array-key, mixed>} $callbacks */
        $callbacks = Closure::bind(static fn(Event $event): array => [$event->filters, $event->rejects], null, Event::class)($event);
        [$filters, $rejects] = $callbacks;

        if ($filters !== []) {
            return true;
        }

        foreach ($rejects as $reject) {
            if (! self::isOverlapCheck($reject)) {
                return true;
            }
        }

        return false;
    }

    private static function isOverlapCheck(mixed $callback): bool
    {
        if (! $callback instanceof Closure) {
            return false;
        }

        try {
            $closure = new ReflectionFunction($callback);
            $method = new ReflectionMethod(Event::class, 'withoutOverlapping');

            return $closure->getFileName() === $method->getFileName()
                && $closure->getStartLine() >= $method->getStartLine()
                && $closure->getEndLine() <= $method->getEndLine();
        } catch (Throwable) {
            return false;
        }
    }

    private static function basename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /** `SyncOrders` → `sync-orders`, `ImportCSVFile` → `import-csv-file`. */
    private static function kebab(string $name): string
    {
        $words = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $name) ?? $name;

        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $words), '-'));
    }
}
