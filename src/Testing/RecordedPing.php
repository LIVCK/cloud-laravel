<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Testing;

use LIVCK\Cloud\Laravel\Heartbeats\PingUrlSource;
use LIVCK\Cloud\Ping\PingDetails;

/**
 * A heartbeat ping {@see CloudFake} recorded instead of sending it.
 *
 * `signal` is `start`, `success`, `fail`, or the exit code of a failed run as a string (`"3"`),
 * as the ping URL would end.
 */
final readonly class RecordedPing
{
    public const string START = 'start';

    public const string SUCCESS = 'success';

    public const string FAIL = 'fail';

    /**
     * @param PingUrlSource|null $source where the URL would have come from; null when none is known
     */
    public function __construct(
        public string $key,
        public string $signal,
        public ?PingDetails $details = null,
        public ?PingUrlSource $source = null,
    ) {}

    /** The exit code of a failed run, null for the other signals. */
    public function exitCode(): ?int
    {
        return ctype_digit($this->signal) ? (int) $this->signal : null;
    }

    /**
     * The figures sent with the ping.
     *
     * @return array<string, int|float>
     */
    public function metrics(): array
    {
        return $this->details?->metrics() ?? [];
    }
}
