<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * What the package remembers about declaring the heartbeats of this connection, application
 * and environment. Kept in the heartbeat cache store next to the ping URLs; holds no secret.
 *
 * @internal
 */
final readonly class DeclarationState
{
    /**
     * @param string|null $hash the list LIVCK last accepted ({@see HeartbeatList::hash()})
     * @param float|null $declaredAt when that was
     * @param string|null $reportedHash the list whose answer (not created, unlisted, skipped tags) went to the log
     * @param float|null $retryAt the accepted list has heartbeats LIVCK did not create: try again from then
     * @param float|null $staleAttemptAt the last declaration a ping URL marked stale led to
     */
    public function __construct(
        public ?string $hash = null,
        public ?float $declaredAt = null,
        public ?string $reportedHash = null,
        public ?float $retryAt = null,
        public ?DeclarationFailure $failure = null,
        public ?float $staleAttemptAt = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $failure = $data['failure'] ?? null;

        return new self(
            self::string($data['hash'] ?? null),
            self::number($data['declared_at'] ?? null),
            self::string($data['reported_hash'] ?? null),
            self::number($data['retry_at'] ?? null),
            is_array($failure) ? DeclarationFailure::fromArray($failure) : null,
            self::number($data['stale_attempt_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'declared_at' => $this->declaredAt,
            'reported_hash' => $this->reportedHash,
            'retry_at' => $this->retryAt,
            'failure' => $this->failure?->toArray(),
            'stale_attempt_at' => $this->staleAttemptAt,
        ];
    }

    /** The list was accepted. */
    public function accepted(string $hash, float $now, ?float $retryAt, ?float $staleAttemptAt, bool $reported): self
    {
        return new self($hash, $now, $reported ? $hash : $this->reportedHash, $retryAt, null, $staleAttemptAt);
    }

    public function failed(DeclarationFailure $failure, ?float $staleAttemptAt): self
    {
        return new self($this->hash, $this->declaredAt, $this->reportedHash, $this->retryAt, $failure, $staleAttemptAt);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
