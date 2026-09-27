<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Testing;

use Closure;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
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

    public function __construct(private FakeHttpClient $http)
    {
        $this->senders = new WeakMap();
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
