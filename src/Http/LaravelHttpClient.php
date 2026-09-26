<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Http;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that sends through Laravel's HTTP client stack: `transport: 'laravel'`.
 *
 * Laravel then sees every request the SDK sends. `Http::fake()` answers it,
 * `Http::preventStrayRequests()` refuses it, `Http::assertSent()` finds it, global
 * middleware runs (Pulse records slow requests there), and the client events fire
 * (Telescope listens to them). Retries, idempotency and error mapping stay with the SDK.
 *
 * Laravel captures the faked responses and the stray-request switch when it builds a handler
 * stack, so a stack built once would ignore every `Http::fake()` that follows. The stack is
 * therefore built per request, from the HTTP factory of that moment. The innermost handler
 * (cURL) is kept, so connections are reused as with the SDK's own client.
 *
 * The stack itself fires only `RequestSending`; `ResponseReceived` and `ConnectionFailed`
 * come from Laravel's `PendingRequest::send()`, which the SDK does not go through, and are
 * dispatched here instead.
 *
 * `Http::globalOptions()` does not apply; the timeouts are the connection's.
 */
final class LaravelHttpClient implements ClientInterface
{
    /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface */
    private $handler;

    /**
     * @param Closure(): Factory $factory the HTTP factory at send time: the faked one in a test, the request's own under Octane
     * @param float $timeout seconds for the whole request
     * @param float $connectTimeout seconds to establish the connection
     * @param (callable(RequestInterface, array<array-key, mixed>): PromiseInterface)|null $handler the innermost Guzzle handler; cURL when null
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        ?callable $handler = null,
    ) {
        $this->handler = $handler ?? Utils::chooseHandler();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $factory = ($this->factory)();
        $transferStats = null;

        $client = new Client([
            'handler' => $factory->createPendingRequest()->pushHandlers(HandlerStack::create($this->handler)),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'allow_redirects' => false,
            'http_errors' => false,
            // Laravel's handlers read the request data from this option, which only its own
            // PendingRequest::send() sets. Laravel 12 fails without it; the SDK's requests
            // carry no form data, so it stays empty.
            'laravel_data' => [],
            'on_stats' => static function (TransferStats $stats) use (&$transferStats): void {
                $transferStats = $stats;
            },
        ]);

        try {
            $response = $client->sendRequest($request);
        } catch (ConnectException $e) {
            // Not chained: the Guzzle exception holds the request, Authorization header included.
            $factory->getDispatcher()?->dispatch(new ConnectionFailed(
                (new Request($e->getRequest()))->withData([]),
                new ConnectionException($e->getMessage()),
            ));

            throw $e;
        }

        $received = new Response($response);
        $received->transferStats = $transferStats;

        $factory->getDispatcher()?->dispatch(new ResponseReceived((new Request($request))->withData([]), $received));

        return $response;
    }
}
