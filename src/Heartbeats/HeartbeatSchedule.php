<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * When a heartbeat expects its pings: a cron expression in a timezone, or a plain period.
 */
final readonly class HeartbeatSchedule
{
    public const string CRON = 'cron';

    public const string SIMPLE = 'simple';

    private function __construct(
        public string $type,
        public ?string $expression,
        public ?string $timezone,
        public ?int $periodSeconds,
    ) {}

    public static function cron(string $expression, string $timezone): self
    {
        return new self(self::CRON, $expression, $timezone, null);
    }

    public static function simple(int $periodSeconds): self
    {
        return new self(self::SIMPLE, null, null, $periodSeconds);
    }

    public function isCron(): bool
    {
        return $this->type === self::CRON;
    }

    /**
     * As LIVCK reads a heartbeat's schedule.
     *
     * @return array{type: 'cron', expression: string, timezone: string}|array{type: 'simple', period_seconds: int}
     */
    public function toArray(): array
    {
        if ($this->type === self::CRON) {
            return ['type' => self::CRON, 'expression' => (string) $this->expression, 'timezone' => (string) $this->timezone];
        }

        return ['type' => self::SIMPLE, 'period_seconds' => (int) $this->periodSeconds];
    }

    /** `0 3 * * * (Europe/Berlin)` or `every 3600 s`. */
    public function describe(): string
    {
        return $this->type === self::CRON
            ? sprintf('%s (%s)', $this->expression, $this->timezone)
            : sprintf('every %d s', $this->periodSeconds);
    }
}
