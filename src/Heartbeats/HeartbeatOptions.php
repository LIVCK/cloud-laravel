<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * What `->heartbeat()` was given for one task. Checked when the list is built, never in the
 * macro: a task with an unusable option becomes a problem instead of an exception in the
 * schedule definition.
 *
 * @internal
 */
final readonly class HeartbeatOptions
{
    public function __construct(
        public ?string $key = null,
        public ?int $grace = null,
        public ?int $maxDuration = null,
        public ?int $every = null,
        public ?int $tolerance = null,
    ) {}

    /**
     * The options that are set but not positive, by the name `->heartbeat()` gives them.
     *
     * @return list<string>
     */
    public function invalid(): array
    {
        $invalid = [];

        foreach (['grace' => $this->grace, 'maxDuration' => $this->maxDuration, 'every' => $this->every, 'tolerance' => $this->tolerance] as $name => $value) {
            if ($value !== null && $value < 1) {
                $invalid[] = $name;
            }
        }

        return $invalid;
    }
}
