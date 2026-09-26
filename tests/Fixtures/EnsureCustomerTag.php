<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use LIVCK\Cloud\CloudClientInterface;

/** A queued job with the client injected into handle(): only the customer number is serialised. */
final class EnsureCustomerTag implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $customer) {}

    public function handle(CloudClientInterface $cloud): void
    {
        $cloud->tags()->ensure('customer', $this->customer);
    }
}
