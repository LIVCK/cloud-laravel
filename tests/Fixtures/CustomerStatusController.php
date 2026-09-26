<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use LIVCK\Cloud\CloudClientInterface;

/** A controller with the client injected into its constructor. */
final readonly class CustomerStatusController
{
    public function __construct(private CloudClientInterface $cloud) {}

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['organization' => $this->cloud->me()->organization->name]);
    }
}
