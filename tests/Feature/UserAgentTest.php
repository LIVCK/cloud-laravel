<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

it('names the SDK first, then the package and the Laravel version', function (): void {
    $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

    LivckCloud::me();

    expect($fake->lastRequest()?->header('User-Agent'))
        ->toBe('livck-cloud-php/' . CloudClient::VERSION . ' PHP/' . PHP_VERSION . ' livck-cloud-laravel/1.1.0 Laravel/' . Application::VERSION)
        ->toBe(sprintf('livck-cloud-php/%s PHP/%s livck-cloud-laravel/%s Laravel/%s', CloudClient::VERSION, PHP_VERSION, CloudManager::VERSION, Application::VERSION));
});

it('appends the configured suffix after the package', function (): void {
    useConnection('default', ['user_agent_suffix' => 'hoster-panel/2.3']);
    $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

    LivckCloud::me();

    expect($fake->lastRequest()?->header('User-Agent'))
        ->toBe('livck-cloud-php/' . CloudClient::VERSION . ' PHP/' . PHP_VERSION . ' livck-cloud-laravel/1.1.0 Laravel/' . Application::VERSION . ' hoster-panel/2.3');
});

it('keeps the package in the User-Agent whatever the suffix says', function (mixed $suffix): void {
    useConnection('default', ['token' => TEST_TOKEN, 'user_agent_suffix' => $suffix]);

    expect(LivckCloud::connection()->options()->userAgentSuffix)
        ->toBe('livck-cloud-laravel/1.1.0 Laravel/' . Application::VERSION);
})->with(['null' => [null], 'empty' => [''], 'blank' => ['   ']]);

it('identifies clients built on demand the same way', function (): void {
    useConnection('default', ['user_agent_suffix' => 'hoster-panel/2.3']);
    $fake = LivckCloud::fake(array_fill(0, 3, MockResponse::json(mePayload())));

    LivckCloud::withToken(CUSTOMER_TOKEN)->me();
    LivckCloud::build(['token' => CUSTOMER_TOKEN, 'user_agent_suffix' => 'reseller-sync/1.4'])->me();
    LivckCloud::build(['token' => CUSTOMER_TOKEN, 'user_agent_suffix' => null])->me();

    $base = 'livck-cloud-php/' . CloudClient::VERSION . ' PHP/' . PHP_VERSION . ' livck-cloud-laravel/1.1.0 Laravel/' . Application::VERSION;

    expect(array_map(static fn(RecordedRequest $request): ?string => $request->header('User-Agent'), $fake->recorded()))->toBe([
        $base . ' hoster-panel/2.3',
        $base . ' reseller-sync/1.4',
        $base,
    ]);
});

it('identifies every connection, not only the default one', function (): void {
    useConnection('customer-b', ['user_agent_suffix' => 'reseller-sync/1.4']);
    $fake = LivckCloud::fake([MockResponse::json(mePayload())]);

    LivckCloud::connection('customer-b')->me();

    expect($fake->lastRequest()?->header('User-Agent'))->toEndWith(' livck-cloud-laravel/1.1.0 Laravel/' . Application::VERSION . ' reseller-sync/1.4');
});
