<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;

/**
 * How one declaration went: LIVCK's answer, or why there is none.
 *
 * @internal
 */
final readonly class DeclarationOutcome
{
    public function __construct(
        public HeartbeatDeclaration $declaration,
        public ?HeartbeatDeclarationResult $result,
        public ?DeclarationFailure $failure,
    ) {}

    /** LIVCK accepted the list and every heartbeat of it exists. */
    public function complete(): bool
    {
        return $this->result instanceof HeartbeatDeclarationResult && $this->result->allExist();
    }
}
