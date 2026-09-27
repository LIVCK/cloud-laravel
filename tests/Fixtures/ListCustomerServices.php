<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;

/**
 * A queued job that reads one customer's services with that customer's own token. Only the
 * model's identifier is queued; the worker reads the token from the database.
 */
final class ListCustomerServices implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Customer $customer) {}

    public function handle(): void
    {
        LivckCloud::withToken($this->customer->livck_token)->services()->list();
    }
}
