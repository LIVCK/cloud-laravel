<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use LIVCK\Cloud\Laravel\Support\ConfigValue;

/**
 * The `heartbeats` section of config/livck-cloud.php, read and checked on every call: under
 * Octane the configuration is the request's.
 *
 * @internal
 */
final readonly class HeartbeatConfig
{
    public const float DEFAULT_TIMEOUT = 3.0;

    private const string PREFIX = 'livck-cloud.heartbeats.';

    public function __construct(private Config $config) {}

    /** `heartbeats.app`, or the application's name as a slug. */
    public function app(): string
    {
        $app = ConfigValue::string($this->config->get(self::PREFIX . 'app'), self::PREFIX . 'app');

        if ($app !== null) {
            return $app;
        }

        $name = $this->config->get('app.name');
        $slug = is_string($name) ? Str::slug($name) : '';

        return $slug === '' ? 'laravel' : $slug;
    }

    /**
     * @return list<string>
     */
    public function environments(): array
    {
        $environments = $this->config->get(self::PREFIX . 'environments', ['production']);

        if (is_string($environments)) {
            $environments = explode(',', $environments);
        }

        if (! is_array($environments)) {
            throw new ConfigurationException(sprintf('%senvironments must be a list of environment names, got %s.', self::PREFIX, get_debug_type($environments)));
        }

        $names = [];

        foreach ($environments as $environment) {
            $name = ConfigValue::string($environment, self::PREFIX . 'environments');

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function allows(string $environment): bool
    {
        return in_array($environment, $this->environments(), true);
    }

    /**
     * The queues the pulse watches. A plain entry is a queue of the default queue connection;
     * a string key names another connection: `['default', 'mails', 'sqs' => ['reports']]`.
     *
     * @return list<QueueTarget>
     */
    public function queues(): array
    {
        $configured = $this->config->get(self::PREFIX . 'queues', []);

        if ($configured === null) {
            return [];
        }

        if (! is_array($configured)) {
            throw new ConfigurationException(sprintf('%squeues must be a list of queue names, got %s.', self::PREFIX, get_debug_type($configured)));
        }

        $default = ConfigValue::string($this->config->get('queue.default'), 'queue.default') ?? 'sync';
        $targets = [];

        foreach ($configured as $connection => $queues) {
            $connection = is_string($connection) ? $connection : $default;

            foreach (is_array($queues) ? $queues : [$queues] as $queue) {
                $name = ConfigValue::string($queue, self::PREFIX . 'queues');

                if ($name !== null) {
                    $target = QueueTarget::on($connection, $name, $default);
                    $targets[$target->key] = $target;
                }
            }
        }

        return array_values($targets);
    }

    /**
     * The ping URLs written into the configuration, by key. An entry without a value (an
     * environment variable that is not set) is left out.
     *
     * @return array<string, string>
     */
    public function urls(): array
    {
        $configured = $this->config->get(self::PREFIX . 'urls', []);

        if ($configured === null) {
            return [];
        }

        if (! is_array($configured)) {
            throw new ConfigurationException(sprintf('%surls must map heartbeat keys to ping URLs, got %s.', self::PREFIX, get_debug_type($configured)));
        }

        $urls = [];

        foreach ($configured as $key => $url) {
            $url = ConfigValue::string($url, sprintf('%surls.%s', self::PREFIX, $key));

            if ($url !== null && trim((string) $key) !== '') {
                $urls[trim((string) $key)] = $url;
            }
        }

        return $urls;
    }

    /**
     * The heartbeats pinged from your own code that LIVCK should know when to expect: key to
     * the longest gap between two pings, in seconds. An entry without a value (an environment
     * variable that is not set) is left out; one that is no whole number throws.
     *
     * @return array<string, int>
     */
    public function custom(): array
    {
        $configured = $this->config->get(self::PREFIX . 'custom', []);

        if ($configured === null) {
            return [];
        }

        if (! is_array($configured)) {
            throw new ConfigurationException(sprintf('%scustom must map heartbeat keys to seconds between two pings, got %s.', self::PREFIX, get_debug_type($configured)));
        }

        $custom = [];

        foreach ($configured as $key => $seconds) {
            $name = sprintf('%scustom.%s', self::PREFIX, $key);

            if ($seconds === null || $seconds === '' || trim((string) $key) === '') {
                continue;
            }

            $custom[trim((string) $key)] = ConfigValue::int($seconds, $name, 0);
        }

        return $custom;
    }

    /** The grace of each queue's heartbeat, in seconds. */
    public function queueGrace(): int
    {
        return ConfigValue::int($this->config->get(self::PREFIX . 'queue_grace'), self::PREFIX . 'queue_grace', HeartbeatListBuilder::QUEUE_GRACE);
    }

    public function timeout(): float
    {
        return $this->seconds('timeout');
    }

    /** Seconds the scheduler gives a declaration before it carries on without one. */
    public function declareTimeout(): float
    {
        return $this->seconds('declare_timeout');
    }

    private function seconds(string $key): float
    {
        $timeout = ConfigValue::float($this->config->get(self::PREFIX . $key), self::PREFIX . $key, self::DEFAULT_TIMEOUT);

        return is_finite($timeout) && $timeout > 0.0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    /** The cache store for ping URLs and the pulse's lock, null for the default store. */
    public function cacheStore(): ?string
    {
        return ConfigValue::string($this->config->get(self::PREFIX . 'cache_store'), self::PREFIX . 'cache_store');
    }

    /** The LIVCK Cloud connection the heartbeats belong to: the default connection. */
    public function connection(): string
    {
        return ConfigValue::string($this->config->get('livck-cloud.default'), 'livck-cloud.default') ?? 'default';
    }
}
