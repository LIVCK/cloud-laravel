<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

/**
 * @param array<string, string> $arguments
 * @return array{0: int, 1: string}
 */
function check(array $arguments = []): array
{
    $exitCode = Artisan::call('livck-cloud:check', $arguments);

    return [$exitCode, preg_replace('/\s+/', ' ', Artisan::output()) ?? ''];
}

it('shows what the API knows about the token and proves API access', function (): void {
    $fake = LivckCloud::fake([MockResponse::json(mePayload()), MockResponse::json(probesPayload())]);

    [$exitCode, $output] = check();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Connection')->toContain('default')
        ->toContain('Base URI')->toContain('https://api.livck.cloud/v1')
        ->toContain('Organization')->toContain('Example Hosting (nquKGB2qGm1X7pp60tUHZ)')
        ->toContain('Token type')->toContain('user')
        ->toContain('Expires')->toContain('never')
        ->toContain('Rate limit')->toContain('120 requests per minute')
        ->toContain('Abilities')->toContain('3')
        ->toContain('services.view')->toContain('services.create')->toContain('statuspages.view')
        ->toContain('API access')->toContain('yes, 2 monitoring locations')
        ->toContain('The connection [default] works.')
        ->and($output)->not->toContain('Service ');

    expect(array_map(static fn(RecordedRequest $request): string => $request->path(), $fake->recorded()))->toBe(['/v1/me', '/v1/probes']);
});

it('checks a named connection', function (): void {
    useConnection('customer-b', ['base_uri' => 'https://b.example.test/v1']);
    $fake = LivckCloud::fake([MockResponse::json(mePayload()), MockResponse::json(probesPayload())]);

    [$exitCode, $output] = check(['connection' => 'customer-b']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('https://b.example.test/v1')->toContain('The connection [customer-b] works.')
        ->and($fake->recorded('customer-b'))->toHaveCount(2);
});

it('says when API access cannot be checked with the token', function (): void {
    $fake = LivckCloud::fake([MockResponse::json(mePayload([
        'type' => 'managed',
        'permissions' => [],
        'service' => ['public_id' => 'iChkaXWKTdPJxp7dJaDwn', 'name' => 'Shop'],
        'expires_at' => '2027-01-31T12:00:00+00:00',
    ]))]);

    [$exitCode, $output] = check();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('managed')
        ->toContain('Shop (iChkaXWKTdPJxp7dJaDwn)')
        ->toContain('2027-01-31 12:00:00')
        ->toContain('Abilities')->toContain('none')
        ->toContain('not checked, the token lacks services.view')
        ->toContain('The token of the connection [default] is valid. Checking API access needs a token with services.view.');

    $fake->assertSentCount(1);
});

it('shows a token type this SDK does not know as the API names it', function (): void {
    LivckCloud::fake([MockResponse::json(mePayload(['type' => 'robot'])), MockResponse::json(probesPayload())]);

    [$exitCode, $output] = check();

    expect($exitCode)->toBe(0)->and($output)->toContain('robot');
});

it('explains why the token was refused and exits with 1', function (MockResponse $response, string $message): void {
    useConnection('default', ['max_retries' => 0]);
    LivckCloud::fake([$response]);

    [$exitCode, $output] = check();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($message)
        ->and($output)->not->toContain('Rate limit');
})->with([
    '401' => [MockResponse::error('Unauthenticated.', 401), 'The API does not accept the token of the connection [default] (HTTP 401): it is unknown, revoked or expired.'],
    '403' => [MockResponse::error('This organization is suspended.', 403), 'The API refuses the token of the connection [default] (HTTP 403): This organization is suspended.'],
    'network' => [MockResponse::networkError('Could not resolve host'), 'The API could not be reached: Could not resolve host (GET https://api.livck.cloud/v1/me, 1 attempt)'],
]);

it('explains why the API cannot be used although the token is valid, and exits with 1', function (MockResponse $response, string $message): void {
    useConnection('default', ['max_retries' => 0]);
    LivckCloud::fake([MockResponse::json(mePayload()), $response]);

    [$exitCode, $output] = check();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Example Hosting')
        ->toContain($message)
        ->and($output)->not->toContain('works');
})->with([
    'plan' => [
        MockResponse::error("Feature 'api_access' is not available on your current plan.", 403, extra: ['upsell' => ['reason' => 'feature', 'key' => 'api_access']]),
        'The plan of the organization behind the connection [default] does not include API access (HTTP 403, feature "api_access"). API access starts with the Team plan.',
    ],
    'server' => [MockResponse::error('Server Error', 500), 'The check failed: Server Error (HTTP 500, GET https://api.livck.cloud/v1/probes)'],
]);

it('names the missing token and exits with 2', function (): void {
    [$exitCode, $output] = check();

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('The LIVCK Cloud connection [default] has no API token. Set LIVCK_CLOUD_TOKEN in the environment');
});

it('refuses an unknown connection with 2', function (): void {
    LivckCloud::fake();

    [$exitCode, $output] = check(['connection' => 'customer-x']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('The LIVCK Cloud connection [customer-x] is not configured.');
});
