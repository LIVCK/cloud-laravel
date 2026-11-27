<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/** Puts itself back on its first attempt, as a job behind WithoutOverlapping does. */
final class ReleasingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release(0);
        }
    }
}
