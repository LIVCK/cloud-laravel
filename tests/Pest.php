<?php

declare(strict_types=1);

use LIVCK\Cloud\Laravel\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

/** A token long enough to look real; tests show that it never appears where it must not. */
const TEST_TOKEN = 'lvk_Secr3tPrefixAndTheRestOfTheTokenThatMustNeverBeShown000000';

/**
 * Add or replace a connection, on top of the shipped default connection's values.
 *
 * @param array<string, mixed> $settings
 */
function useConnection(string $name, array $settings = []): void
{
    config()->set('livck-cloud.connections.' . $name, [...TestCase::DEFAULT_CONNECTION, ...$settings]);
}

/**
 * `GET /v1/me` as the API answers it.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mePayload(array $overrides = []): array
{
    return [
        'type' => 'user',
        'permissions' => ['services.view', 'services.create', 'statuspages.view'],
        'organization' => ['public_id' => 'nquKGB2qGm1X7pp60tUHZ', 'name' => 'Example Hosting'],
        'rate_limit' => ['requests_per_minute' => 120],
        'service' => null,
        'expires_at' => null,
        ...$overrides,
    ];
}

/**
 * A tag as the API returns it.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function tagPayload(array $overrides = []): array
{
    return [
        'id' => 'V1StGXR8Z5jdHi6BmyT01',
        'key' => 'customer',
        'value' => '4711',
        'color' => '#6366f1',
        'label' => 'customer:4711',
        'source' => 'user',
        'services_count' => 0,
        ...$overrides,
    ];
}

/**
 * `GET /v1/meta/check-types` with one type.
 *
 * @return array<string, mixed>
 */
function catalogPayload(string $label = 'HTTP/HTTPS'): array
{
    return [
        'data' => [
            'http' => [
                'key' => 'http',
                'label' => $label,
                'description' => 'Monitors HTTP endpoints',
                'target_required' => true,
                'fields' => [
                    ['name' => 'method', 'type' => 'select', 'label' => 'HTTP method', 'required' => false, 'secret' => false, 'options' => ['GET', 'POST'], 'default' => 'GET'],
                ],
                'conditions' => [
                    'fields' => [['field' => 'status_code', 'label' => 'Status code', 'type' => 'number', 'operators' => ['eq', 'gte']]],
                    'defaults' => [],
                ],
                'interval' => ['default' => 60, 'min' => 30, 'max' => 3600],
            ],
        ],
    ];
}

/**
 * `GET /v1/probes`.
 *
 * @return array<string, mixed>
 */
function probesPayload(): array
{
    return [
        'data' => [
            ['code' => 'ffm', 'name' => 'Frankfurt', 'location' => 'Germany', 'country_code' => 'DE'],
            ['code' => 'hel', 'name' => 'Helsinki', 'location' => 'Finland', 'country_code' => 'FI'],
        ],
    ];
}
