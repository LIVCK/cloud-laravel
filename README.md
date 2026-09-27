# LIVCK Cloud for Laravel

![Creating a monitor with LIVCK Cloud for Laravel](.github/assets/header.png)

Laravel integration for [LIVCK Cloud](https://livck.cloud): uptime monitoring and status pages
on [statuspage.de](https://statuspage.de) or your own domain. It builds on the
[LIVCK Cloud PHP SDK](https://github.com/LIVCK/cloud-php#readme) (`livck/cloud-php`) and adds
configuration, a facade, dependency injection, several connections, a fake for tests and an
Artisan check. The API itself, with its builders, queries, DTOs, retries and exceptions, is the
SDK's; its README covers all of it.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- An API token of your organization (`lvk_…`, under *Settings → Organization → API tokens*).
  API access starts with the Team plan.

## Installation

```bash
composer require livck/cloud-laravel
```

Laravel discovers the service provider and the `LivckCloud` facade. Put the token into `.env`:

```dotenv
LIVCK_CLOUD_TOKEN=lvk_…
```

and check the connection:

```bash
php artisan livck-cloud:check
```

To change more than the token, publish the configuration to `config/livck-cloud.php`:

```bash
php artisan vendor:publish --tag=livck-cloud-config
```

## Usage

The facade speaks for the default connection:

```php
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;

$tag = LivckCloud::tags()->ensure('customer', '4711')->tag;

LivckCloud::services()->create(
    ServiceBuilder::http('Shop', 'https://shop.example.com')->interval(60)->tags($tag),
);
```

Or type against the SDK's interface and let the container inject it, into a controller's
constructor or a job's `handle()`:

```php
use LIVCK\Cloud\CloudClientInterface;

final class ProvisionCustomer implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly string $customer) {}

    public function handle(CloudClientInterface $cloud): void
    {
        $cloud->tags()->ensure('customer', $this->customer);
    }
}
```

Only the job's data is queued; the worker injects the client when the job runs.

## Several organizations

A reseller serving several organizations gives each its own connection and token:

```php
// config/livck-cloud.php
'connections' => [
    'default' => [
        'token' => env('LIVCK_CLOUD_TOKEN'),
        // …
    ],
    'customer-b' => [
        'token' => env('LIVCK_CLOUD_TOKEN_CUSTOMER_B'),
        'locale' => 'de',
    ],
],
```

```php
LivckCloud::connection('customer-b')->services()->list();
```

A key a connection leaves out takes the SDK's default.

## Tokens from your database

A reseller who keeps one token per end customer in their own database needs no connection for
each. `withToken()` takes the settings of the default connection, or of the connection named
second, with another token:

```php
LivckCloud::withToken($customer->livck_token)->services()->list();
```

`build()` takes the keys of a connection. What it leaves out comes from the default connection,
the token included; a key it passes wins, even with `null`. An unknown key throws, so a typo
cannot go unnoticed:

```php
$cloud = LivckCloud::build([
    'token' => $customer->livck_token,
    'locale' => $customer->locale,
]);
```

A blank token throws `MissingTokenException` instead of falling back to a configured one, so a
customer without a token never reaches your own organization. Store the tokens with Laravel's
`encrypted` cast, which keeps them out of the database in plain text:

```php
protected function casts(): array
{
    return ['livck_token' => 'encrypted'];
}
```

Each call builds a new client and the manager keeps none; an Octane or queue worker would
otherwise hold a client for every customer's token for as long as it runs. Hold the client in
a variable for the request or job at hand rather than calling `withToken()` again for each API
call. `LivckCloud::fake()` covers both, see [Testing](#testing).

## Configuration

| Key | Default | Notes |
|---|---|---|
| `default` | `default` | The connection of the facade and of an injected client |
| `connections.*.token` | `LIVCK_CLOUD_TOKEN` | Required outside of tests |
| `connections.*.base_uri` | `https://api.livck.cloud/v1` | https; plain http only for loopback hosts |
| `connections.*.timeout`, `connect_timeout` | 30 s, 10 s | |
| `connections.*.max_retries` | 2 | Attempts after the first; 0 turns retries off |
| `connections.*.max_retry_after` | 60 s | A longer `Retry-After` fails at once instead of waiting |
| `connections.*.idempotency` | `true` | An `Idempotency-Key` on every POST |
| `connections.*.locale` | none | `Accept-Language`; `app` sends the application's locale of the moment |
| `connections.*.user_agent_suffix` | none | Appended to the User-Agent, e.g. `my-panel/2.3` |
| `connections.*.transport` | `sdk` | `laravel` sends through Laravel's HTTP client, see below |
| `log_channel` | none | One debug line per attempt; never a token, never a body |
| `catalog_cache.store`, `ttl` | default store, 3600 s | For `LivckCloud::catalog()`; a TTL of 0 turns it off |

The shipped configuration reads every key of the default connection from `LIVCK_CLOUD_` and
the key in upper case (`LIVCK_CLOUD_BASE_URI`, `LIVCK_CLOUD_TRANSPORT`, …), the others from
`LIVCK_CLOUD_LOG_CHANNEL`, `LIVCK_CLOUD_CATALOG_CACHE_STORE`, `LIVCK_CLOUD_CATALOG_CACHE_TTL`
and, for the name of the default connection, `LIVCK_CLOUD_CONNECTION`. The log channel needs
the `debug` level to show anything.

`LivckCloud::catalog()` returns the check-type catalog from the cache, fetched once per
connection, base URI and locale. Hand it to `services()->create()` to validate builders
without a request each; `LivckCloud::checkTypes()` always asks the API.

## Checking a connection

`php artisan livck-cloud:check [connection]` calls `GET /v1/me` and shows the organization,
the token's type, abilities and expiry, and the rate limit. `/v1/me` answers any valid token,
so the check then lists the monitoring locations to prove the plan includes API access (with a
token that has `services.view`). It exits with `0` when the connection works, `1` when the API
rejects the token (unknown, revoked, a plan without API access) or cannot be reached, and `2`
when the configuration is incomplete. `php artisan about` lists the connections with their
base URI and the beginning of each token, never the token itself.

## Testing

`LivckCloud::fake()` swaps every connection for the SDK's fake: the facade, injected clients,
named connections and clients from `withToken()` and `build()` all answer from one queue,
nothing leaves the process, and retries do not sleep. No configured token is needed; a blank
one passed to `withToken()` or `build()` still throws.

```php
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

it('tags a new customer in their own organization', function () {
    $fake = LivckCloud::fake([
        MockResponse::json(['data' => $tagPayload], 201),
    ]);

    ProvisionCustomer::dispatchSync('4711');

    $fake->assertSent(fn (RecordedRequest $request, string $connection) =>
        $connection === 'default' && $request->matches('POST', '/v1/tags/ensure'));
});
```

The matcher receives the request and the name of its connection: `ondemand`
(`CloudFake::ON_DEMAND`) for a client from `withToken()` or `build()`. `assertNotSent()`,
`assertSentCount()` and `assertNothingSent()` complete the set; these four work on the facade
as well (`LivckCloud::assertSent(…)`). `assertNoPendingResponses()` checks that every queued
response was used, and `recorded('customer-b')` lists one connection's requests. A request
without a queued response throws, so a test never sends more than it planned for. Call
`fake()` before the code under test resolves a client.

## Laravel's HTTP client

With `transport: 'laravel'` (`LIVCK_CLOUD_TRANSPORT=laravel`) a connection sends through
Laravel's HTTP client. `Http::fake()` then answers its requests, `Http::preventStrayRequests()`
refuses them, `Http::assertSent()` sees them, Pulse records slow ones and Telescope lists them
(with the `Authorization` header hidden, as Telescope hides it by default). Retries,
idempotency and error mapping stay with the SDK; `Http::globalOptions()` does not apply, the
connection's timeouts do. `LivckCloud::fake()` takes precedence over both transports.

## Octane and queue workers

The clients are immutable and hold no request state, so the manager builds each connection
once and every request of an Octane worker and every job of a queue worker reuses it. It reads
configuration and locale from the application of the moment, so with `locale: 'app'` each
request gets its own language. Resolve such a client where you use it (the facade, a
controller, a job) rather than into a singleton built while the application boots, and call
`LivckCloud::purge()` after changing a connection at runtime. Clients from `withToken()` and
`build()` are never kept, see [Tokens from your database](#tokens-from-your-database).

## User-Agent

Every request names the SDK, then this package and the Laravel version, then your suffix:

```text
livck-cloud-php/1.0.0 PHP/8.4.1 livck-cloud-laravel/1.1.0 Laravel/13.2.0 my-panel/2.3
```

## Links

- SDK: [livck/cloud-php](https://github.com/LIVCK/cloud-php#readme)
- Documentation: [docs.livck.cloud](https://docs.livck.cloud)
- API reference: [api.livck.cloud](https://api.livck.cloud)
- Status pages: [statuspage.de](https://statuspage.de)

## License

MIT. See [LICENSE](LICENSE).
