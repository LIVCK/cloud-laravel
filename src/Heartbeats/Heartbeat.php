<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * One heartbeat the application has: its key, what it watches, when it expects pings and the
 * options given to `->heartbeat()`. Unset options are null and leave LIVCK's value as it is.
 */
final readonly class Heartbeat
{
    /**
     * @param HeartbeatSchedule|null $schedule null for a {@see HeartbeatKind::Custom} heartbeat
     *                                         that only has a URL in `heartbeats.urls`: its
     *                                         schedule lives in LIVCK only
     */
    public function __construct(
        public string $key,
        public HeartbeatKind $kind,
        public ?HeartbeatSchedule $schedule,
        public ?int $graceSeconds = null,
        public ?int $maxDurationSeconds = null,
        public ?int $scheduleTolerance = null,
    ) {}

    /**
     * The entry as LIVCK reads it: options only when they are set.
     *
     * @return array{key: string, kind: string, schedule: array<string, int|string>|null, grace_seconds?: int, max_duration_seconds?: int, schedule_tolerance?: int}
     */
    public function toArray(): array
    {
        $entry = [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'schedule' => $this->schedule?->toArray(),
        ];

        if ($this->graceSeconds !== null) {
            $entry['grace_seconds'] = $this->graceSeconds;
        }

        if ($this->maxDurationSeconds !== null) {
            $entry['max_duration_seconds'] = $this->maxDurationSeconds;
        }

        if ($this->scheduleTolerance !== null) {
            $entry['schedule_tolerance'] = $this->scheduleTolerance;
        }

        return $entry;
    }
}
