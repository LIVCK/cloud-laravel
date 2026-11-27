<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** Fails its first attempt and succeeds on the second. */
final class FlakyJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $backoff = 0;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            throw new RuntimeException('first attempt fails');
        }
    }
}
