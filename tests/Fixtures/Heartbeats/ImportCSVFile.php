<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures\Heartbeats;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ImportCSVFile implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
