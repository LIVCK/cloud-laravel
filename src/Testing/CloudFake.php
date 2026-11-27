<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Testing;

use ArrayObject;
use Closure;
use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;
use LIVCK\Cloud\Testing\ExpectationFailedException;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use WeakMap;

/**
 * What `LivckCloud::fake()` returns. Every connection, whether reached through the facade,
 * an injected CloudClientInterface or `LivckCloud::connection('name')`, answers from one
 * queue of MockResponses, and every request is recorded with the connection that sent it.
 * Clients from `LivckCloud::build()` and `LivckCloud::withToken()` answer from the same
 * queue; their requests are recorded under {@see ON_DEMAND}.
 *
 *     $fake = LivckCloud::fake([MockResponse::json(['data' => $tag], 201)]);
 *
 *     // run the code under test, then:
 *     $fake->assertSent(fn (RecordedRequest $request, string $connection): bool =>
 *         $connection === 'customer-b' && $request->matches('POST', '/v1/tags/ensure'));
 *
 * It rests on the SDK's FakeHttpClient: nothing leaves the process, retries do not sleep,
 * and a request without a queued response throws, so a test never sends more than it
 * planned for. Each connection keeps its configured options (base URI, locale, retries);
 * no configured token is needed, every fake sends {@see TOKEN}. A token passed to
 * `build()` or `withToken()` is still checked, so a blank one throws as in production.
 *
 * Heartbeat pings are recorded apart from the requests: nothing is sent, every ping is kept
 * whether a ping URL is known or not, and `fake()` turns heartbeats on in every environment,
 * so that a test sees what the scheduler and the workers would ping:
 *
 *     $fake = LivckCloud::fake();
 *     $this->artisan('schedule:run');
 *     $fake->assertPinged('export:nightly', 'start', 'success');
 *
 * Declarations of heartbeats (the scheduler's and `livck-cloud:heartbeats:sync`) are answered
 * by the fake as LIVCK would, without a token and without a queued response: every key exists,
 * a new one as `created`, one declared before as `unchanged` or `updated`, each with a ping
 * URL of its own, and a key an earlier declaration named but this one does not as `unlisted`.
 * The pings that follow are recorded with the URL from the cache:
 *
 *     $fake = LivckCloud::fake();
 *     $this->artisan('schedule:run');
 *     $fake->assertDeclared('export:nightly', 'queue:default');
 *
 * A failed assertion throws the SDK's ExpectationFailedException, which every test runner
 * reports as a failure. A passed one counts as an assertion with PHPUnit (and Pest), as
 * with Laravel's own fakes, so a test resting on it alone is not flagged as risky.
 */
final readonly class CloudFake
{
    /** The token every faked connection sends, whatever is configured. */
    public const string TOKEN = 'lvk_test_token';

    /**
     * The connection recorded for the requests of clients from `LivckCloud::build()` and
     * `LivckCloud::withToken()`, whichever connection their settings come from. Laravel
     * names its own on-demand disks, caches and log channels the same way.
     */
    public const string ON_DEMAND = 'ondemand';

    /** @var WeakMap<RecordedRequest, string> */
    private WeakMap $senders;

    /** @var ArrayObject<int, RecordedPing> */
    private ArrayObject $pings;

    /** @var ArrayObject<int, RecordedDeclaration> */
    private ArrayObject $declarations;

    public function __construct(private FakeHttpClient $http)
    {
        $this->senders = new WeakMap();
        $this->pings = new ArrayObject();
        $this->declarations = new ArrayObject();
    }

    /**
     * The client of one connection: what `CloudClient::fake()` builds, with the connection's
     * options and the queue every connection shares.
     *
     * @internal
     */
    public function client(string $connection, ClientOptions $options): CloudClient
    {
        return new CloudClient(
            self::TOKEN,
            $options,
            new ConnectionHttpClient($connection, $this->http, $this->senders),
            sleeper: $this->http->sleeper(),
        );
    }

    /**
     * At least one request satisfies the matcher. It receives the request and the name of
     * the connection that sent it.
     *
     * @param Closure(RecordedRequest, string): bool $matcher
     */
    public function assertSent(Closure $matcher, string $message = ''): self
    {
        $this->http->assertSent($this->withSender($matcher), $message);
        $this->passed();

        return $this;
    }

    /**
     * No request satisfies the matcher. It receives the request and the name of the
     * connection that sent it.
     *
     * @param Closure(RecordedRequest, string): bool $matcher
     */
    public function assertNotSent(Closure $matcher, string $message = ''): self
    {
        $this->http->assertNotSent($this->withSender($matcher), $message);
        $this->passed();

        return $this;
    }

    /** Exactly this many requests went out, across all connections, retries included. */
    public function assertSentCount(int $count, string $message = ''): self
    {
        $this->http->assertSentCount($count, $message);
        $this->passed();

        return $this;
    }

    public function assertNothingSent(string $message = ''): self
    {
        $this->http->assertNothingSent($message);
        $this->passed();

        return $this;
    }

    /** Every queued response was consumed. */
    public function assertNoPendingResponses(string $message = ''): self
    {
        $this->http->assertNoPendingResponses($message);
        $this->passed();

        return $this;
    }

    /**
     * Every request received, in order and retries included; only one connection's when a
     * name is given.
     *
     * @return list<RecordedRequest>
     */
    public function recorded(?string $connection = null): array
    {
        $recorded = $this->http->recorded();

        if ($connection === null) {
            return $recorded;
        }

        return array_values(array_filter(
            $recorded,
            fn(RecordedRequest $request): bool => $this->connectionOf($request) === $connection,
        ));
    }

    public function lastRequest(): ?RecordedRequest
    {
        return $this->http->lastRequest();
    }

    /** The name of the connection a recorded request was sent through. */
    public function connectionOf(RecordedRequest $request): ?string
    {
        return $this->senders[$request] ?? null;
    }

    /**
     * Append responses to the queue.
     *
     * @param MockResponse|Closure(RequestInterface): MockResponse ...$responses
     */
    public function queue(MockResponse|Closure ...$responses): self
    {
        $this->http->queue(...$responses);

        return $this;
    }

    /** Responses still queued. */
    public function remaining(): int
    {
        return $this->http->remaining();
    }

    /**
     * Backoff waits the clients would have slept, in seconds.
     *
     * @return list<float>
     */
    public function delays(): array
    {
        return $this->http->delays();
    }

    /** The SDK's fake underneath, for anything this class does not expose. */
    public function http(): FakeHttpClient
    {
        return $this->http;
    }

    /**
     * Record a heartbeat ping instead of sending it.
     *
     * @internal
     */
    public function recordPing(RecordedPing $ping): void
    {
        $this->pings->append($ping);
    }

    /**
     * Every heartbeat ping, in order; only one key's when a key is given.
     *
     * @return list<RecordedPing>
     */
    public function pings(?string $key = null): array
    {
        $pings = array_values($this->pings->getArrayCopy());

        return $key === null ? $pings : array_values(array_filter($pings, static fn(RecordedPing $ping): bool => $ping->key === $key));
    }

    /**
     * The heartbeat was pinged. With signals, it got exactly these, in this order:
     * `assertPinged('export:nightly', 'start', 'success')`; an exit code as a string (`'3'`).
     */
    public function assertPinged(string $key, string ...$signals): self
    {
        $got = array_map(static fn(RecordedPing $ping): string => $ping->signal, $this->pings($key));

        if ($got === []) {
            throw new ExpectationFailedException(sprintf('The heartbeat [%s] was not pinged. Pinged: %s', $key, $this->describePings()));
        }

        if ($signals !== [] && $got !== $signals) {
            throw new ExpectationFailedException(sprintf('The heartbeat [%s] got [%s], expected [%s].', $key, implode(', ', $got), implode(', ', $signals)));
        }

        $this->passed();

        return $this;
    }

    public function assertNotPinged(string $key): self
    {
        $got = array_map(static fn(RecordedPing $ping): string => $ping->signal, $this->pings($key));

        if ($got !== []) {
            throw new ExpectationFailedException(sprintf('The heartbeat [%s] was pinged: [%s].', $key, implode(', ', $got)));
        }

        $this->passed();

        return $this;
    }

    public function assertNothingPinged(): self
    {
        if ($this->pings->count() > 0) {
            throw new ExpectationFailedException(sprintf('Expected no heartbeat ping. Pinged: %s', $this->describePings()));
        }

        $this->passed();

        return $this;
    }

    /**
     * Answer a declaration of heartbeats as LIVCK would, and record it.
     *
     * @internal
     */
    public function declare(HeartbeatDeclaration $declaration): HeartbeatDeclarationResult
    {
        $body = $declaration->toArray();
        $earlier = [];

        foreach ($this->declarations() as $recorded) {
            if ($recorded->app === $body['app'] && $recorded->environment === $body['environment']) {
                foreach ($recorded->heartbeats as $entry) {
                    $earlier[is_string($entry['key'] ?? null) ? $entry['key'] : ''] = $entry;
                }
            }
        }

        $heartbeats = [];
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($body['heartbeats'] as $entry) {
            $key = is_string($entry['key'] ?? null) ? $entry['key'] : '';
            $result = match (true) {
                ! isset($earlier[$key]) => 'created',
                $earlier[$key] === $entry => 'unchanged',
                default => 'updated',
            };
            $counts[$result]++;
            $schedule = is_array($entry['schedule'] ?? null) ? $entry['schedule'] : [];
            $id = hash('sha256', implode("\n", [$body['app'], $body['environment'], $key]));

            $heartbeats[] = [
                'service_id' => 'svc' . substr($id, 0, 18),
                'key' => $key,
                'name' => $key,
                'kind' => $entry['kind'] ?? 'custom',
                'schedule' => ['period_seconds' => null, 'expression' => null, 'timezone' => 'UTC', ...$schedule],
                'grace_seconds' => $entry['grace_seconds'] ?? 60,
                'schedule_tolerance' => $entry['schedule_tolerance'] ?? 1,
                'max_duration_seconds' => $entry['max_duration_seconds'] ?? null,
                'ping_url' => 'https://ping.livck.test/hb_' . substr($id, 18, 32),
                'status' => 'unknown',
                'paused' => false,
                'result' => $result,
            ];
            unset($earlier[$key]);
        }

        $unlisted = [];

        foreach (array_keys($earlier) as $key) {
            $unlisted[] = ['key' => (string) $key, 'service_id' => 'svc' . substr(hash('sha256', implode("\n", [$body['app'], $body['environment'], (string) $key])), 0, 18)];
        }

        $this->declarations->append(new RecordedDeclaration($body['app'], $body['environment'], $body['heartbeats']));

        return HeartbeatDeclarationResult::fromArray([
            'heartbeats' => $heartbeats,
            'not_created' => ['keys' => [], 'reason' => null, 'missing' => null, 'retry_after' => null],
            'unlisted' => $unlisted,
            'skipped_tags' => [],
            'counts' => $counts,
        ]);
    }

    /**
     * Every declaration of heartbeats, in order.
     *
     * @return list<RecordedDeclaration>
     */
    public function declarations(): array
    {
        return array_values($this->declarations->getArrayCopy());
    }

    /**
     * Heartbeats were declared. With keys, one declaration named each of them (and maybe
     * more): `assertDeclared('export:nightly', 'queue:default')`.
     */
    public function assertDeclared(string ...$keys): self
    {
        $declarations = $this->declarations();

        if ($declarations === []) {
            throw new ExpectationFailedException('No heartbeats were declared.');
        }

        foreach ($declarations as $declaration) {
            if (array_diff($keys, $declaration->keys()) === []) {
                $this->passed();

                return $this;
            }
        }

        throw new ExpectationFailedException(sprintf('No declaration named [%s]. Declared: %s', implode(', ', $keys), $this->describeDeclarations()));
    }

    /** No declaration named the key. */
    public function assertNotDeclared(string $key): self
    {
        foreach ($this->declarations() as $declaration) {
            if (in_array($key, $declaration->keys(), true)) {
                throw new ExpectationFailedException(sprintf('The heartbeat [%s] was declared. Declared: %s', $key, $this->describeDeclarations()));
            }
        }

        $this->passed();

        return $this;
    }

    public function assertNothingDeclared(): self
    {
        if ($this->declarations->count() > 0) {
            throw new ExpectationFailedException(sprintf('Expected no declaration of heartbeats. Declared: %s', $this->describeDeclarations()));
        }

        $this->passed();

        return $this;
    }

    private function describeDeclarations(): string
    {
        $declarations = array_map(static fn(RecordedDeclaration $declaration): string => '[' . implode(', ', $declaration->keys()) . ']', $this->declarations());

        return $declarations === [] ? 'nothing' : implode(', ', $declarations);
    }

    private function describePings(): string
    {
        $pings = array_map(static fn(RecordedPing $ping): string => $ping->key . ' ' . $ping->signal, $this->pings());

        return $pings === [] ? 'nothing' : implode(', ', $pings);
    }

    /**
     * Count a passed assertion with PHPUnit, when it runs the test. The SDK's check has held
     * by now (it throws otherwise); this only makes it visible to the runner.
     */
    private function passed(): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertThat(true, Assert::isTrue());
        }
    }

    /**
     * @param Closure(RecordedRequest, string): bool $matcher
     * @return Closure(RecordedRequest): bool
     */
    private function withSender(Closure $matcher): Closure
    {
        return fn(RecordedRequest $request): bool => $matcher($request, $this->connectionOf($request) ?? '');
    }
}
