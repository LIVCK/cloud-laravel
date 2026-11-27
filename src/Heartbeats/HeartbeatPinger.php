<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Date;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Support\ConfigValue;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use LIVCK\Cloud\Laravel\Testing\RecordedPing;
use LIVCK\Cloud\Ping\PingDetails;
use LIVCK\Cloud\Ping\PingResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Pings a heartbeat by its key: looks up the URL, sends the signal, and never throws.
 *
 * - No URL known: nothing is sent, and one warning per process and key says so. A URL comes
 *   from `heartbeats.urls` or from the declaration ({@see HeartbeatDeclarer}).
 * - LIVCK does not know the URL (404): a URL from the cache is marked stale and not used again;
 *   a URL from the configuration stays, with a warning to replace it.
 * - No answer at all (timeout, DNS, connection): further pings of this process wait
 *   {@see PAUSE} seconds, so that a scheduler does not spend three seconds on each.
 * - Under `LivckCloud::fake()` the ping is recorded and nothing is sent, URL or not.
 *
 * Held as a singleton; what it keeps (the keys already warned about, the pause) belongs to
 * the process, as the log line it saves does.
 *
 * @internal
 */
final class HeartbeatPinger
{
    public const int PAUSE = 60;

    /** @var array<string, true> */
    private array $warned = [];

    private ?float $pausedUntil = null;

    /**
     * @param Closure(): Application $app resolves the current application
     */
    public function __construct(private readonly Closure $app) {}

    public function start(string $key, ?PingDetails $details = null): PingResult
    {
        return $this->send($key, RecordedPing::START, $details);
    }

    public function success(string $key, ?PingDetails $details = null): PingResult
    {
        return $this->send($key, RecordedPing::SUCCESS, $details);
    }

    public function fail(string $key, ?PingDetails $details = null): PingResult
    {
        return $this->send($key, RecordedPing::FAIL, $details);
    }

    /** 0 is a success, anything else a failure with that code. */
    public function exitCode(string $key, int $code, ?PingDetails $details = null): PingResult
    {
        return $this->send($key, $code === 0 ? RecordedPing::SUCCESS : (string) $code, $details);
    }

    /**
     * @param string $signal `start`, `success`, `fail` or an exit code
     */
    private function send(string $key, string $signal, ?PingDetails $details): PingResult
    {
        try {
            $app = ($this->app)();
            $url = $app->make(PingUrlResolver::class)->resolve($key);
            $fake = $app->make(CloudManager::class)->currentFake();

            if ($fake instanceof CloudFake) {
                $fake->recordPing(new RecordedPing($key, $signal, $details, $url?->source));

                return PingResult::fromStatus(200, $details?->droppedMetrics() ?? []);
            }

            if (! $url instanceof PingUrl) {
                $this->warnOnce($app, $key, 'No ping URL is known for the heartbeat [{key}], so it was not pinged. Put one under livck-cloud.heartbeats.urls, or let the scheduler declare it with an API token; a heartbeat of your own needs the seconds between two pings under livck-cloud.heartbeats.custom for that.');

                return PingResult::notSent('No ping URL is known for this heartbeat.');
            }

            if ($this->pausedUntil !== null && $this->now() < $this->pausedUntil) {
                return PingResult::notSent('LIVCK could not be reached a moment ago; pings pause for a minute.');
            }

            $config = new HeartbeatConfig($app->make(Config::class));
            $ping = $app->make(PingClientFactory::class)->make($url->url, $config->timeout(), $this->sdkLogger($app), $this->userAgentSuffix($app));

            $result = match ($signal) {
                RecordedPing::START => $ping->start($details),
                RecordedPing::SUCCESS => $ping->success($details),
                RecordedPing::FAIL => $ping->fail($details),
                default => $ping->exitCode((int) $signal, $details),
            };

            $this->handle($app, $key, $url, $result);

            return $result;
        } catch (Throwable $e) {
            // Only the class: a message of an HTTP client may quote the URL.
            $this->warn(($this->app)(), 'The heartbeat [{key}] could not be pinged ({exception}).', ['key' => $key, 'exception' => $e::class]);

            return PingResult::notSent(sprintf('The ping failed with %s.', $e::class));
        }
    }

    private function handle(Application $app, string $key, PingUrl $url, PingResult $result): void
    {
        if ($result->unknownUrl) {
            if ($url->source === PingUrlSource::Cache) {
                $app->make(CachedPingUrls::class)->markStale($key);
                $this->warn($app, 'LIVCK does not know the ping URL of the heartbeat [{key}] (HTTP 404); it was dropped from the cache.', ['key' => $key]);

                return;
            }

            $this->warn($app, 'LIVCK does not know the ping URL configured for the heartbeat [{key}] (HTTP 404). Replace it under livck-cloud.heartbeats.urls.', ['key' => $key]);

            return;
        }

        // No answer came back (DNS, connection, timeout). A URL that could not be used at all is
        // not a reason to pause the others.
        if ($result->noResponse) {
            $this->pausedUntil = $this->now() + self::PAUSE;
        }

        if (! $result->ok()) {
            $this->warn($app, 'The heartbeat [{key}] could not be pinged: {error}', ['key' => $key, 'error' => $result->error ?? 'unknown error']);
        }
    }

    private function warnOnce(Application $app, string $key, string $message): void
    {
        if (isset($this->warned[$key])) {
            return;
        }

        $this->warned[$key] = true;
        $this->warn($app, $message, ['key' => $key]);
    }

    /**
     * @param array<string, string> $context
     */
    private function warn(Application $app, string $message, array $context): void
    {
        try {
            HeartbeatLog::logger($app)->warning($message, $context);
        } catch (Throwable) {
            // A log that cannot be written must not reach the task either.
        }
    }

    /** The SDK's line per ping goes where the package's request lines go, if anywhere. */
    private function sdkLogger(Application $app): LoggerInterface
    {
        $channel = ConfigValue::string($app->make(Config::class)->get('livck-cloud.log_channel'), 'livck-cloud.log_channel');

        return $channel === null ? new NullLogger() : $app->make(LogManager::class)->channel($channel);
    }

    private function userAgentSuffix(Application $app): string
    {
        return sprintf('%s/%s Laravel/%s', CloudManager::USER_AGENT_PRODUCT, CloudManager::VERSION, $app->version());
    }

    private function now(): float
    {
        return (float) Date::now()->format('U.u');
    }
}
