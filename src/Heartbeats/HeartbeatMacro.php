<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * `->heartbeat()` on a scheduled event: marks the event and returns it. Reads nothing of the
 * event, which may not be complete yet, and never throws.
 *
 * @internal
 */
final class HeartbeatMacro
{
    public const string NAME = 'heartbeat';

    /**
     * @param-closure-this Event $macro
     */
    public static function register(Closure $macro): void
    {
        Event::macro(self::NAME, $macro);
    }

    public static function mark(Event $event, ?string $key, ?int $grace, ?int $maxDuration, ?int $every, ?int $tolerance): void
    {
        try {
            HeartbeatMonitor::current()?->mark($event, new HeartbeatOptions($key, $grace, $maxDuration, $every, $tolerance));
        } catch (Throwable) {
            // A heartbeat must never break the schedule it watches.
        }
    }
}
