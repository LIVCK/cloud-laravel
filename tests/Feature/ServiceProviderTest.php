<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\CloudServiceProvider;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;

it('ships a configuration with every documented key', function (): void {
    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/../../config/livck-cloud.php';
    $connections = $config['connections'] ?? null;

    expect(array_keys($config))->toBe(['default', 'connections', 'log_channel', 'catalog_cache'])
        ->and($connections)->toBeArray()
        ->and(is_array($connections) ? array_keys($connections) : null)->toBe(['default'])
        ->and(is_array($connections) && is_array($connections['default']) ? array_keys($connections['default']) : null)
        ->toBe(['token', 'base_uri', 'timeout', 'connect_timeout', 'max_retries', 'max_retry_after', 'idempotency', 'locale', 'user_agent_suffix', 'transport'])
        ->and($config['catalog_cache'])->toBeArray()->toHaveKeys(['store', 'ttl']);
});

it('merges its configuration under what the application already has', function (): void {
    config()->set('livck-cloud', ['default' => 'mine']);

    (new CloudServiceProvider(app()))->register();

    expect(config('livck-cloud.default'))->toBe('mine')
        ->and(config('livck-cloud.connections.default'))->toBeArray()
        ->and(config('livck-cloud.catalog_cache'))->toBeArray();
});

it('publishes the configuration', function (): void {
    $target = config_path('livck-cloud.php');
    File::delete($target);

    try {
        expect(Artisan::call('vendor:publish', ['--tag' => 'livck-cloud-config']))->toBe(0)
            ->and(File::get($target))->toBe(File::get(__DIR__ . '/../../config/livck-cloud.php'));
    } finally {
        File::delete($target);
    }
});

it('registers the manager once, under its class and the livck-cloud alias', function (): void {
    $manager = app(CloudManager::class);

    expect(app()->get('livck-cloud'))->toBe($manager)
        ->and(app(CloudManager::class))->toBe($manager)
        ->and(LivckCloud::getFacadeRoot())->toBe($manager);
});

it('binds the client interface to the default connection, asking the manager each time', function (): void {
    useConnection('default', ['token' => TEST_TOKEN]);

    expect(app()->isShared(CloudClientInterface::class))->toBeFalse()
        ->and(app(CloudClientInterface::class))->toBe(LivckCloud::connection());
});

it('registers the check command', function (): void {
    expect(Artisan::all())->toHaveKey('livck-cloud:check');
});

it('declares the provider and the facade for package discovery', function (): void {
    /** @var array{extra: array{laravel: array{providers: list<string>, aliases: array<string, string>}}} $composer */
    $composer = json_decode(File::get(__DIR__ . '/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel']['providers'])->toBe([CloudServiceProvider::class])
        ->and($composer['extra']['laravel']['aliases'])->toBe(['LivckCloud' => LivckCloud::class]);
});

it('needs a Laravel application as the current container', function (): void {
    $manager = app(CloudManager::class);
    $application = Container::getInstance();
    Container::setInstance(new Container());

    try {
        expect(fn(): string => $manager->getDefaultConnection())
            ->toThrow(LogicException::class, 'LIVCK Cloud needs a Laravel application as the current container.');
    } finally {
        Container::setInstance($application);
    }
});
