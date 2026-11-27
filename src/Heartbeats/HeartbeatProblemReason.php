<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * Why a task marked with `->heartbeat()` is left out of the list, or watched other than asked.
 */
enum HeartbeatProblemReason: string
{
    /** A closure or shell command without a key of its own. */
    case MissingKey = 'missing_key';

    /** Two heartbeats would share one key. */
    case DuplicateKey = 'duplicate_key';

    /** The task runs under a condition (`when`, `skip`, `between`) and has no `every:`. */
    case Conditional = 'conditional';

    /** An option of `->heartbeat()` is not a positive number. */
    case InvalidOption = 'invalid_option';

    /** An option the task cannot use; the task is watched without it. */
    case IgnoredOption = 'ignored_option';

    /** Whether the task is left out of the list for this reason. */
    public function leavesOut(): bool
    {
        return $this !== self::IgnoredOption;
    }
}
