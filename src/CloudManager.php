<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\Traits\ForwardsCalls;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use LIVCK\Cloud\Laravel\Exceptions\MissingTokenException;
use LIVCK\Cloud\Laravel\Http\LaravelHttpClient;
use LIVCK\Cloud\Laravel\Support\ConfigValue;
use LIVCK\Cloud\Laravel\Support\ConnectionConfig;
use LIVCK\Cloud\Laravel\Support\Transport;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use LIVCK\Cloud\Resources\IncidentsInterface;
use LIVCK\Cloud\Resources\MaintenancesInterface;
use LIVCK\Cloud\Resources\ServicesInterface;
use LIVCK\Cloud\Resources\StatuspagesInterface;
use LIVCK\Cloud\Resources\TagsInterface;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The connections of config/livck-cloud.php, one SDK client each.
 *
 * A singleton behind the LivckCloud facade, the `livck-cloud` alias and every injected
 * CloudClientInterface. A client is built on first use and kept: it is immutable and holds
 * no request state, so one instance serves every request of an Octane worker and every job
 * of a queue worker. The exception is a connection with `locale: 'app'`, which follows the
 * application's locale: its client is derived per call with `withLocale()`, and nothing that
 * depends on the locale is kept.
 *
 * Configuration, cache, log and locale are read from the application of the moment, never
 * from the one the manager was built in; under Octane that is the request's sandbox.
 *
 * The methods of CloudClientInterface act on the default connection. Methods a newer SDK
 * adds are forwarded to it as well.
 */
class CloudManager
{
    use ForwardsCalls;

    public const string VERSION = '1.0.0';

    public const string USER_AGENT_PRODUCT = 'livck-cloud-laravel';

    /** @var array<string, array{client: CloudClientInterface, followsApplicationLocale: bool}> */
    private array $connections = [];

    private ?CloudFake $fake = null;

    /**
     * @param Closure(): Application $app resolves the current application
     */
    public function __construct(private readonly Closure $app) {}

    /**
     * The client of a connection, the default one when no name is given.
     *
     * @throws ConfigurationException for an unknown name or a configuration the SDK refuses
     * @throws MissingTokenException when the connection has no token
     */
    public function connection(?string $name = null): CloudClientInterface
    {
        $name ??= $this->getDefaultConnection();
        $connection = $this->connections[$name] ??= $this->resolve($name);

        return $connection['followsApplicationLocale']
            ? $connection['client']->withLocale($this->app()->getLocale())
            : $connection['client'];
    }

    public function getDefaultConnection(): string
    {
        $name = ConfigValue::string($this->config()->get('livck-cloud.default'), 'livck-cloud.default');

        return $name ?? throw new ConfigurationException('livck-cloud.default must name one of livck-cloud.connections.');
    }

    /**
     * Forget the client of a connection, or of every connection, so that the next call
     * builds it from the configuration as it is now (after rotating a token at runtime, say).
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->connections = [];

            return;
        }

        unset($this->connections[$name]);
    }

    /**
     * The check-type catalog through the Laravel cache: per connection, base URI and locale,
     * for `livck-cloud.catalog_cache.ttl` seconds. Hand it to `services()->create()` to
     * validate a builder without a request per service. `checkTypes()` always asks the API.
     */
    public function catalog(?string $connection = null): CheckTypeCatalog
    {
        $name = $connection ?? $this->getDefaultConnection();
        $client = $this->connection($name);
        $ttl = ConfigValue::int($this->config()->get('livck-cloud.catalog_cache.ttl'), 'livck-cloud.catalog_cache.ttl', 3600);

        if ($ttl <= 0) {
            return $client->checkTypes();
        }

        $cache = $this->catalogCache();
        $key = $this->catalogKey($name, $client->options());
        $cached = $cache->get($key);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return CheckTypeCatalog::fromArray($cached);
        }

        $catalog = $client->checkTypes();
        $cache->put($key, $catalog->raw, $ttl);

        return $catalog;
    }

    /**
     * Swap every connection for a fake answering from one queue; see {@see CloudFake}.
     *
     * @param iterable<MockResponse|Closure(RequestInterface): MockResponse> $responses answered in order
     */
    public function fake(iterable $responses = []): CloudFake
    {
        $this->connections = [];

        return $this->fake = new CloudFake(new FakeHttpClient($responses));
    }

    /**
     * @param Closure(RecordedRequest, string): bool $matcher receives the request and the name of its connection
     */
    public function assertSent(Closure $matcher, string $message = ''): CloudFake
    {
        return $this->activeFake()->assertSent($matcher, $message);
    }

    /**
     * @param Closure(RecordedRequest, string): bool $matcher receives the request and the name of its connection
     */
    public function assertNotSent(Closure $matcher, string $message = ''): CloudFake
    {
        return $this->activeFake()->assertNotSent($matcher, $message);
    }

    public function assertSentCount(int $count, string $message = ''): CloudFake
    {
        return $this->activeFake()->assertSentCount($count, $message);
    }

    public function assertNothingSent(string $message = ''): CloudFake
    {
        return $this->activeFake()->assertNothingSent($message);
    }

    public function options(): ClientOptions
    {
        return $this->connection()->options();
    }

    public function withOptions(ClientOptions $options): CloudClientInterface
    {
        return $this->connection()->withOptions($options);
    }

    public function withLocale(?string $locale): CloudClientInterface
    {
        return $this->connection()->withLocale($locale);
    }

    public function withHttpClient(ClientInterface $httpClient): CloudClientInterface
    {
        return $this->connection()->withHttpClient($httpClient);
    }

    public function tags(): TagsInterface
    {
        return $this->connection()->tags();
    }

    public function statuspages(): StatuspagesInterface
    {
        return $this->connection()->statuspages();
    }

    public function services(): ServicesInterface
    {
        return $this->connection()->services();
    }

    public function incidents(): IncidentsInterface
    {
        return $this->connection()->incidents();
    }

    public function maintenances(): MaintenancesInterface
    {
        return $this->connection()->maintenances();
    }

    public function me(): Me
    {
        return $this->connection()->me();
    }

    /**
     * @return list<Probe>
     */
    public function probes(): array
    {
        return $this->connection()->probes();
    }

    public function checkTypes(): CheckTypeCatalog
    {
        return $this->connection()->checkTypes();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): Response
    {
        return $this->connection()->request($method, $path, $query, $json, $headers);
    }

    public function send(Request $request): Response
    {
        return $this->connection()->send($request);
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->connection(), $method, $parameters);
    }

    /**
     * @return array{client: CloudClientInterface, followsApplicationLocale: bool}
     */
    private function resolve(string $name): array
    {
        $config = ConnectionConfig::fromArray($name, $this->settings($name));
        $options = $config->options($this->userAgentSuffix($config->userAgentSuffix), $this->logger());

        if ($this->fake instanceof CloudFake) {
            $client = $this->fake->client($name, $options);
        } else {
            $client = new CloudClient(
                $config->token ?? throw MissingTokenException::forConnection($name),
                $options,
                $config->transport === Transport::Laravel ? $this->laravelHttpClient($options) : null,
            );
        }

        return ['client' => $client, 'followsApplicationLocale' => $config->followsApplicationLocale()];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function settings(string $name): array
    {
        $connections = $this->config()->get('livck-cloud.connections');
        $connections = is_array($connections) ? $connections : [];
        $settings = $connections[$name] ?? null;

        return is_array($settings) ? $settings : throw ConfigurationException::unknownConnection($name, array_keys($connections));
    }

    /**
     * The package's product token after the SDK's (Stripe's `setAppInfo` convention), then
     * the configured suffix: `livck-cloud-laravel/1.0.0 Laravel/13.2.0 my-panel/2.3`.
     */
    private function userAgentSuffix(?string $configured): string
    {
        $suffix = sprintf('%s/%s Laravel/%s', self::USER_AGENT_PRODUCT, self::VERSION, $this->app()->version());

        return $configured === null ? $suffix : $suffix . ' ' . $configured;
    }

    private function logger(): LoggerInterface
    {
        $channel = ConfigValue::string($this->config()->get('livck-cloud.log_channel'), 'livck-cloud.log_channel');

        return $channel === null ? new NullLogger() : $this->app()->make(LogManager::class)->channel($channel);
    }

    private function laravelHttpClient(ClientOptions $options): LaravelHttpClient
    {
        $app = $this->app;

        return new LaravelHttpClient(
            static fn(): HttpFactory => $app()->make(HttpFactory::class),
            $options->timeout,
            $options->connectTimeout,
        );
    }

    private function catalogCache(): Cache
    {
        $store = ConfigValue::string($this->config()->get('livck-cloud.catalog_cache.store'), 'livck-cloud.catalog_cache.store');

        return $this->app()->make(CacheFactory::class)->store($store);
    }

    /**
     * Per connection, base URI and locale, and per SDK version so that an upgrade never reads
     * a catalog cached by the one before. Never the token.
     */
    private function catalogKey(string $connection, ClientOptions $options): string
    {
        return 'livck-cloud:catalog:' . hash('xxh128', implode("\n", [$connection, $options->baseUri, $options->locale ?? '', CloudClient::VERSION]));
    }

    private function activeFake(): CloudFake
    {
        return $this->fake ?? throw new LogicException('LivckCloud::fake() has not been called, so no requests were recorded.');
    }

    private function app(): Application
    {
        return ($this->app)();
    }

    private function config(): Config
    {
        return $this->app()->make(Config::class);
    }
}
