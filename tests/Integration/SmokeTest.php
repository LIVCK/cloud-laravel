<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Query\ServiceQuery;

/*
 * Read-only calls against a live API: opt-in, never part of the default suite. Set
 * LIVCK_CLOUD_TOKEN, and LIVCK_CLOUD_BASE_URI for anything but production, then run
 * `composer test:integration`. LIVCK_CLOUD_TOKEN_NO_API, a token of an organization without
 * API access, adds the check of the plan gate.
 */

/** A variable of the process environment, or null when unset or empty. */
function liveEnvironment(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

/**
 * Point the default connection at the live API.
 *
 * @param array<string, mixed> $settings
 */
function useLiveApi(string $tokenVariable = 'LIVCK_CLOUD_TOKEN', array $settings = []): void
{
    useConnection('default', [
        'token' => liveEnvironment($tokenVariable),
        'base_uri' => liveEnvironment('LIVCK_CLOUD_BASE_URI') ?? ClientOptions::DEFAULT_BASE_URI,
        'user_agent_suffix' => 'cloud-laravel-smoke-test',
        ...$settings,
    ]);
}

$skip = liveEnvironment('LIVCK_CLOUD_TOKEN') === null
    ? 'Runs against a live API only: set LIVCK_CLOUD_TOKEN (and LIVCK_CLOUD_BASE_URI for anything but production).'
    : null;

describe('live API', function () use ($skip): void {
    it('answers the discovery calls and a read-only list through the facade', function (): void {
        useLiveApi();

        $me = LivckCloud::me();
        $probes = LivckCloud::probes();
        $catalog = LivckCloud::checkTypes();
        $page = LivckCloud::services()->list(ServiceQuery::make()->withPerPage(5));

        expect($me->organization->publicId)->not->toBe('')
            ->and($me->permissions)->toContain('services.view')
            ->and($probes)->not->toBeEmpty()
            ->and($catalog->has(CheckType::Http))->toBeTrue()
            ->and($page->total)->toBeGreaterThanOrEqual(count($page->items));
    })->skip($skip !== null, $skip ?? '');

    it('caches the catalog after one request', function (): void {
        useLiveApi();

        $first = LivckCloud::catalog();
        $second = LivckCloud::catalog();

        expect($first)->toBeInstanceOf(CheckTypeCatalog::class)
            ->and($second->keys())->toBe($first->keys());
    })->skip($skip !== null, $skip ?? '');

    it('works through the Laravel HTTP client as well', function (): void {
        useLiveApi(settings: ['transport' => 'laravel']);

        expect(LivckCloud::me()->organization->publicId)->not->toBe('')
            ->and(LivckCloud::probes())->not->toBeEmpty();
    })->skip($skip !== null, $skip ?? '');

    it('passes the check command', function (): void {
        useLiveApi();

        expect(Artisan::call('livck-cloud:check'))->toBe(0)
            ->and(Artisan::output())->toContain('works');
    })->skip($skip !== null, $skip ?? '');

    it('explains the plan gate in the check command', function (): void {
        useLiveApi('LIVCK_CLOUD_TOKEN_NO_API');

        expect(Artisan::call('livck-cloud:check'))->toBe(1)
            ->and(Artisan::output())->toContain('does not include API access');
    })->skip($skip !== null || liveEnvironment('LIVCK_CLOUD_TOKEN_NO_API') === null, $skip ?? 'Set LIVCK_CLOUD_TOKEN_NO_API to check the plan gate.');
});
