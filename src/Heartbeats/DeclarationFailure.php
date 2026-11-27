<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * A declaration that did not go through, and how long the scheduler waits before the next.
 *
 * @internal
 */
final readonly class DeclarationFailure
{
    /**
     * @param string $message what happened and what to do, as it went to the log; never a secret
     * @param float $at when it happened, as a Unix timestamp
     * @param float|null $until no new attempt before this moment; null: none until the list changes
     * @param string $hash the list that was declared
     */
    public function __construct(
        public DeclarationFailureReason $reason,
        public string $message,
        public float $at,
        public ?float $until,
        public string $hash,
    ) {}

    /** Whether a scheduler run at this moment, with this list, leaves LIVCK alone. */
    public function pauses(float $now, string $hash): bool
    {
        return $this->until === null ? $this->hash === $hash : $now < $this->until;
    }

    /**
     * @return array{reason: string, message: string, at: float, until: float|null, hash: string}
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason->value, 'message' => $this->message, 'at' => $this->at, 'until' => $this->until, 'hash' => $this->hash];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $reason = DeclarationFailureReason::tryFrom(is_string($data['reason'] ?? null) ? $data['reason'] : '');
        $until = $data['until'] ?? null;

        if (! $reason instanceof DeclarationFailureReason || ! is_string($data['hash'] ?? null) || ! is_numeric($data['at'] ?? null) || ($until !== null && ! is_numeric($until))) {
            return null;
        }

        return new self($reason, is_string($data['message'] ?? null) ? $data['message'] : '', (float) $data['at'], $until === null ? null : (float) $until, $data['hash']);
    }
}
