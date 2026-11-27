<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use LIVCK\Cloud\Support\Secret;
use SensitiveParameter;
use Throwable;

/**
 * Ping URLs kept in the application cache, one entry per key, encrypted with the application
 * key. Entries belong to one LIVCK Cloud connection, application and environment, so that
 * staging and production sharing a cache never mix their URLs.
 *
 * The scheduler and the queue workers must share the store: a worker reads the URL of a job
 * or queue heartbeat from it. The store is `heartbeats.cache_store`, the default one when null.
 *
 * A URL LIVCK no longer knows (404) is marked stale: it is not used again, and
 * {@see hasStale()} says that the list needs declaring anew.
 *
 * Never throws. A store that cannot be reached and an entry that cannot be decrypted (after a
 * key rotation, say) count as no entry.
 *
 * @internal
 */
final readonly class CachedPingUrls implements PingUrlResolver
{
    /**
     * @param Closure(): Application $app resolves the current application
     */
    public function __construct(private Closure $app) {}

    public function resolve(string $key): ?PingUrl
    {
        try {
            $entry = $this->read($key);

            if ($entry === null || $entry['stale'] || $entry['url'] === null || $entry['url'] === '') {
                return null;
            }

            return new PingUrl(new Secret($entry['url']), PingUrlSource::Cache);
        } catch (Throwable) {
            return null;
        }
    }

    /** Keep the ping URL of a key, replacing any earlier one and its stale mark. */
    public function put(string $key, #[SensitiveParameter] Secret|string $url): bool
    {
        try {
            $url = $url instanceof Secret ? $url->reveal() : $url;

            return $url !== '' && $this->write($key, ['url' => $url, 'stale' => false]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Keep several ping URLs at once.
     *
     * @param array<array-key, Secret|string> $urls by key
     */
    public function putMany(#[SensitiveParameter] array $urls): bool
    {
        $stored = true;

        foreach ($urls as $key => $url) {
            $stored = $this->put((string) $key, $url) && $stored;
        }

        return $stored;
    }

    /** Forget the URL of a key. */
    public function forget(string $key): bool
    {
        try {
            return $this->cache()->forget($this->entryKey($key));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The URL of a key is no longer valid: drop it, and remember that the list needs
     * declaring anew.
     */
    public function markStale(string $key): bool
    {
        try {
            $cache = $this->cache();

            return $this->write($key, ['url' => null, 'stale' => true])
                && $cache->forever($this->scopeKey('stale'), true);
        } catch (Throwable) {
            return false;
        }
    }

    public function isStale(string $key): bool
    {
        try {
            return $this->read($key)['stale'] ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether a URL of this connection, application and environment was marked stale. */
    public function hasStale(): bool
    {
        try {
            return $this->cache()->get($this->scopeKey('stale')) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function clearStale(): bool
    {
        try {
            return $this->cache()->forget($this->scopeKey('stale'));
        } catch (Throwable) {
            return false;
        }
    }

    /** The cache key of a heartbeat's entry; never contains the URL. */
    public function entryKey(string $key): string
    {
        return $this->scopeKey('url:' . $key);
    }

    /**
     * @return array{url: string|null, stale: bool}|null
     */
    private function read(string $key): ?array
    {
        $stored = $this->cache()->get($this->entryKey($key));

        if (! is_string($stored)) {
            return null;
        }

        try {
            $decoded = json_decode($this->encrypter()->decryptString($stored), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $url = $decoded['url'] ?? null;

        return ['url' => is_string($url) ? $url : null, 'stale' => ($decoded['stale'] ?? false) === true];
    }

    /**
     * @param array{url: string|null, stale: bool} $entry
     */
    private function write(string $key, #[SensitiveParameter] array $entry): bool
    {
        return $this->cache()->forever(
            $this->entryKey($key),
            $this->encrypter()->encryptString((string) json_encode($entry, JSON_UNESCAPED_SLASHES)),
        );
    }

    private function scopeKey(string $suffix): string
    {
        return HeartbeatStore::key($this->app(), $suffix);
    }

    private function cache(): Cache
    {
        return HeartbeatStore::cache($this->app());
    }

    private function encrypter(): StringEncrypter
    {
        return $this->app()->make(StringEncrypter::class);
    }

    private function app(): Application
    {
        return ($this->app)();
    }
}
