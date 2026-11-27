<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued at most once until a worker has run it. */
final class UniqueReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
