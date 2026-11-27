<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats;

use RuntimeException;

/** A job without ShouldQueue: the scheduler runs it itself. */
final class BuildReport
{
    public function __construct(public bool $fails = false) {}

    public function handle(): void
    {
        if ($this->fails) {
            throw new RuntimeException('report failed');
        }
    }
}
