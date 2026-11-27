<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * What a heartbeat watches.
 */
enum HeartbeatKind: string
{
    /** A scheduled command, closure or shell command (`command`, `call`, `exec`). */
    case Schedule = 'schedule';

    /** A scheduled job (`Schedule::job()`), queued or run by the scheduler itself. */
    case Job = 'job';

    /** A queue, watched by the queue pulse. */
    case Queue = 'queue';

    /**
     * A key your own code pings (`LivckCloud::heartbeat()`): one of `heartbeats.custom`, or one
     * with a ping URL in `heartbeats.urls` that the schedule does not have.
     */
    case Custom = 'custom';
}
