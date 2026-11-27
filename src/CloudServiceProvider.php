<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Laravel\Console\AboutSection;
use LIVCK\Cloud\Laravel\Console\CheckCommand;
use LIVCK\Cloud\Laravel\Heartbeats\CachedPingUrls;
use LIVCK\Cloud\Laravel\Heartbeats\ConfigPingUrls;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMacro;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatPinger;
use LIVCK\Cloud\Laravel\Heartbeats\PingClientFactory;
use LIVCK\Cloud\Laravel\Heartbeats\PingUrlResolver;
use LIVCK\Cloud\Laravel\Heartbeats\PingUrls;
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

        // Heartbeats. Like the manager, each reads the application of the moment.
        $this->app->singleton(HeartbeatMonitor::class, static fn(): HeartbeatMonitor => new HeartbeatMonitor(self::currentApplication(...)));
        $this->app->singleton(HeartbeatPinger::class, static fn(): HeartbeatPinger => new HeartbeatPinger(self::currentApplication(...)));
        $this->app->singleton(ConfigPingUrls::class, static fn(): ConfigPingUrls => new ConfigPingUrls(static fn(): Config => self::currentApplication()->make(Config::class)));
        $this->app->singleton(CachedPingUrls::class, static fn(): CachedPingUrls => new CachedPingUrls(self::currentApplication(...)));
        $this->app->singleton(PingUrlResolver::class, static fn(Application $app): PingUrlResolver => new PingUrls($app->make(ConfigPingUrls::class), $app->make(CachedPingUrls::class)));
        $this->app->singleton(PingClientFactory::class, static fn(): PingClientFactory => new PingClientFactory());
    }

    public function boot(): void
    {
        $this->registerHeartbeatMacro();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/livck-cloud.php' => $this->app->configPath('livck-cloud.php'),
            ], 'livck-cloud-config');

            $this->commands([CheckCommand::class]);

            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('LIVCK Cloud', static fn(): array => (new AboutSection(self::currentApplication()->make(Config::class)))());
            }
        }

        $this->app->make(HeartbeatMonitor::class)->boot();
    }

    /**
     * `->heartbeat()` on every scheduled event: `Schedule::command('export:nightly')->daily()->heartbeat()`.
     * The macro is bound to the event it is called on; it only marks the event.
     */
    private function registerHeartbeatMacro(): void
    {
        HeartbeatMacro::register(function (?string $key = null, ?int $grace = null, ?int $maxDuration = null, ?int $every = null, ?int $tolerance = null): Event {
            HeartbeatMacro::mark($this, $key, $grace, $maxDuration, $every, $tolerance);

            return $this;
        });
    }

    private static function currentApplication(): Application
    {
        $app = Container::getInstance();

        return $app instanceof Application ? $app : throw new LogicException('LIVCK Cloud needs a Laravel application as the current container.');
    }
}
