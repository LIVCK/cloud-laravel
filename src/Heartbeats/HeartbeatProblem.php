<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * A task marked with `->heartbeat()` that is not watched, or is watched without an option it
 * was given ({@see HeartbeatProblemReason::leavesOut()}), and what to change.
 */
final readonly class HeartbeatProblem
{
    /**
     * @param string|null $key the key the task would have, null when it has none
     * @param string $task the task as `schedule:list` shows it, roughly
     * @param string $message what is wrong and what to do about it
     */
    public function __construct(
        public ?string $key,
        public string $task,
        public HeartbeatProblemReason $reason,
        public string $message,
    ) {}
}
