<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use LIVCK\Cloud\Resources\IncidentsInterface;
use LIVCK\Cloud\Resources\MaintenancesInterface;
use LIVCK\Cloud\Resources\ServicesInterface;
use LIVCK\Cloud\Resources\StatuspagesInterface;
use LIVCK\Cloud\Resources\TagsInterface;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The default LIVCK Cloud connection; `connection('name')` reaches the others.
 *
 * @method static CloudClientInterface connection(?string $name = null)
 * @method static string getDefaultConnection()
 * @method static void purge(?string $name = null)
 * @method static CheckTypeCatalog catalog(?string $connection = null)
 * @method static ClientOptions options()
 * @method static CloudClientInterface withOptions(ClientOptions $options)
 * @method static CloudClientInterface withLocale(?string $locale)
 * @method static CloudClientInterface withHttpClient(ClientInterface $httpClient)
 * @method static TagsInterface tags()
 * @method static StatuspagesInterface statuspages()
 * @method static ServicesInterface services()
 * @method static IncidentsInterface incidents()
 * @method static MaintenancesInterface maintenances()
 * @method static Me me()
 * @method static list<Probe> probes()
 * @method static CheckTypeCatalog checkTypes()
 * @method static Response request(string $method, string $path, array<string, mixed> $query = [], array<string, mixed>|null $json = null, array<string, string> $headers = [])
 * @method static Response send(Request $request)
 * @method static CloudFake assertSent(Closure(RecordedRequest, string): bool $matcher, string $message = '')
 * @method static CloudFake assertNotSent(Closure(RecordedRequest, string): bool $matcher, string $message = '')
 * @method static CloudFake assertSentCount(int $count, string $message = '')
 * @method static CloudFake assertNothingSent(string $message = '')
 *
 * @see CloudManager
 */
final class LivckCloud extends Facade
{
    /**
     * Swap every connection for a fake that answers from one queue of MockResponses and
     * records every request; see {@see CloudFake}. Covers the facade, injected clients and
     * named connections alike.
     *
     * @param iterable<MockResponse|Closure(RequestInterface): MockResponse> $responses answered in order
     */
    public static function fake(iterable $responses = []): CloudFake
    {
        /** @var CloudManager $manager */
        $manager = self::getFacadeRoot();

        return $manager->fake($responses);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'livck-cloud';
    }
}
