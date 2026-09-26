<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Laravel\Console\AboutSection;
use LIVCK\Cloud\Laravel\Console\CheckCommand;
use LogicException;

/**
 * Registers the connection manager (`livck-cloud`), the CloudClientInterface binding, the
 * configuration, `livck-cloud:check` and the `about` section.
 */
class CloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/livck-cloud.php', 'livck-cloud');

        // The manager reads the application of the moment rather than the one it was built in:
        // a manager resolved while Octane boots serves every request's own sandbox.
        $this->app->singleton(CloudManager::class, static fn(): CloudManager => new CloudManager(self::currentApplication(...)));
        $this->app->alias(CloudManager::class, 'livck-cloud');

        // Not shared: the manager keeps the clients. Resolving anew lets an injected client follow
        // LivckCloud::fake() and, with `locale: 'app'`, the locale of the moment.
        $this->app->bind(CloudClientInterface::class, static fn(Application $app): CloudClientInterface => $app->make(CloudManager::class)->connection());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/livck-cloud.php' => $this->app->configPath('livck-cloud.php'),
            ], 'livck-cloud-config');

            $this->commands([CheckCommand::class]);

            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('LIVCK Cloud', static fn(): array => (new AboutSection(self::currentApplication()->make(Config::class)))());
            }
        }
    }

    private static function currentApplication(): Application
    {
        $app = Container::getInstance();

        return $app instanceof Application ? $app : throw new LogicException('LIVCK Cloud needs a Laravel application as the current container.');
    }
}
