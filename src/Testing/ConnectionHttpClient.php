<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Testing;

use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\RecordedRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WeakMap;

/**
 * The HTTP client of one faked connection: sends through the fake every connection shares
 * and notes which connection each recorded request came from.
 *
 * @internal
 */
final readonly class ConnectionHttpClient implements ClientInterface
{
    /**
     * @param WeakMap<RecordedRequest, string> $senders the connection per recorded request, shared by all connections
     */
    public function __construct(
        private string $connection,
        private FakeHttpClient $http,
        private WeakMap $senders,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $before = count($this->http->recorded());

        try {
            return $this->http->sendRequest($request);
        } finally {
            // The fake records a request before it answers, so this also covers the request it
            // refuses for want of a queued response.
            $recorded = $this->http->lastRequest();

            if ($recorded instanceof RecordedRequest && count($this->http->recorded()) > $before) {
                $this->senders[$recorded] = $this->connection;
            }
        }
    }
}
