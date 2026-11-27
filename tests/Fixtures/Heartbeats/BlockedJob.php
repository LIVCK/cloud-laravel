<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Puts itself back for as long as it is blocked, as a job behind WithoutOverlapping does while
 * another run holds the lock; once free, it runs and takes {@see $takes} seconds.
 */
final class BlockedJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $blocked = true;

    public static float $takes = 0.0;

    public function handle(): void
    {
        if (self::$blocked) {
            $this->release(0);

            return;
        }

        Carbon::setTestNow(Carbon::now()->addMilliseconds((int) round(self::$takes * 1000)));
    }
}
