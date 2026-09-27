# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-27

Clients for tokens that are not in `config/livck-cloud.php`, such as a reseller's one per end
customer.

### Added

- `LivckCloud::withToken($token, $connection = null)`: a client with the settings of a
  connection, the default one when none is named, and another token.
- `LivckCloud::build($config)`: a client from the keys of a connection. What the array leaves
  out comes from the default connection, the token included; an unknown key throws a
  `ConfigurationException` that names it.
- Both refuse a blank token with `MissingTokenException` rather than fall back to a configured
  one, and follow the rules of a connection otherwise: User-Agent, `locale: 'app'`, log
  channel, transport, timeouts and retries. The manager keeps neither client, so an Octane or
  queue worker holds no customer's token beyond the request or job that used it.
- `LivckCloud::fake()` answers both and records their requests under the connection
  `ondemand` (`CloudFake::ON_DEMAND`).

### Security

- An exception thrown while a connection's configuration is read no longer records the token
  among the arguments of its trace: the parameters that carry it are marked
  `#[SensitiveParameter]`.

## [1.0.0] - 2026-09-27

First release, for PHP 8.3 and later with Laravel 12 or 13, on top of `livck/cloud-php` 1.0.

### Added

- `config/livck-cloud.php` with named connections: token, base URI, timeouts, retries,
  `Retry-After` ceiling, idempotency, locale (fixed, none, or the application's), User-Agent
  suffix and transport; plus the log channel and the catalog cache.
- `CloudManager`, registered as a singleton and as `livck-cloud`: one client per connection,
  built on first use and kept, safe under Octane and in queue workers. A connection with
  `locale: 'app'` sends the application's locale of the moment.
- `CloudClientInterface` bound to the default connection for constructor and method
  injection.
- The `LivckCloud` facade for the default connection, `connection()` for the others, with
  IDE docblocks for every method. Methods a newer SDK adds are forwarded.
- `LivckCloud::fake()`: every connection answers from one queue of `MockResponse`s, requests
  are recorded with the connection that sent them, and `assertSent()`, `assertNotSent()`,
  `assertSentCount()` and `assertNothingSent()` count as PHPUnit assertions. A request
  without a queued response throws.
- `LivckCloud::catalog()`: the check-type catalog through the Laravel cache, per connection,
  base URI and locale.
- `transport: 'laravel'`: requests go through Laravel's HTTP client, so that `Http::fake()`,
  `Http::preventStrayRequests()`, Telescope and Pulse see them.
- `php artisan livck-cloud:check [connection]`: the token's organization, abilities and rate
  limit from `/v1/me`, then proof of API access through `/v1/probes`; plain messages for a
  missing token, a rejected token, a plan without API access and an unreachable API, and exit
  codes for scripts.
- A section in `php artisan about`: versions, and per connection the base URI and the token
  prefix, never the token.
- The User-Agent names the package and the Laravel version after the SDK:
  `livck-cloud-php/1.0.0 PHP/8.4.1 livck-cloud-laravel/1.0.0 Laravel/13.2.0`.

[1.1.0]: https://github.com/LIVCK/cloud-laravel/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/LIVCK/cloud-laravel/releases/tag/v1.0.0
