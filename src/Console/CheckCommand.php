<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Console;

use Illuminate\Console\Command;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Data\MeService;
use LIVCK\Cloud\Enums\TokenType;
use LIVCK\Cloud\Exceptions\AuthenticationException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\TransportException;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan livck-cloud:check [connection]`: calls `GET /v1/me` with the connection's
 * token and shows what the API knows about it, then `GET /v1/probes` to prove API access:
 * `/v1/me` answers any token, even one of an organization whose plan has no API access.
 * A token without `services.view` cannot ask the second question; the check says so.
 *
 * Exits with 0 when the connection works, 1 when the API refuses the token or cannot be
 * reached, and 2 when the configuration is incomplete (unknown connection, missing token).
 */
#[AsCommand(name: 'livck-cloud:check')]
final class CheckCommand extends Command
{
    /** The ability `GET /v1/probes` needs, the call that proves API access. */
    private const string PROBE_ABILITY = 'services.view';

    /** @var string */
    protected $signature = 'livck-cloud:check
        {connection? : A connection of config/livck-cloud.php, the default one when omitted}';

    /** @var string */
    protected $description = 'Check a LIVCK Cloud connection: token, organization, abilities, rate limit and API access';

    public function handle(CloudManager $manager): int
    {
        $argument = $this->argument('connection');

        try {
            $name = is_string($argument) ? $argument : $manager->getDefaultConnection();
            $client = $manager->connection($name);
        } catch (ConfigurationException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        try {
            $me = $client->me();
        } catch (LivckCloudException $e) {
            return $this->failure($name, $e);
        }

        $this->report($name, $client, $me);

        if (! $me->can(self::PROBE_ABILITY)) {
            $this->components->twoColumnDetail('API access', sprintf('not checked, the token lacks %s', self::PROBE_ABILITY));
            $this->components->warn(sprintf('The token of the connection [%s] is valid. Checking API access needs a token with %s.', $name, self::PROBE_ABILITY));

            return self::SUCCESS;
        }

        try {
            $probes = $client->probes();
        } catch (LivckCloudException $e) {
            return $this->failure($name, $e);
        }

        $this->components->twoColumnDetail('API access', sprintf('yes, %d monitoring locations', count($probes)));
        $this->components->info(sprintf('The connection [%s] works.', $name));

        return self::SUCCESS;
    }

    private function report(string $name, CloudClientInterface $client, Me $me): void
    {
        $this->components->twoColumnDetail('Connection', $name);
        $this->components->twoColumnDetail('Base URI', $client->options()->baseUri);
        $this->components->twoColumnDetail('Organization', sprintf('%s (%s)', $me->organization->name, $me->organization->publicId));
        $this->components->twoColumnDetail('Token type', $this->tokenType($me));

        if ($me->service instanceof MeService) {
            $this->components->twoColumnDetail('Service', sprintf('%s (%s)', $me->service->name, $me->service->publicId));
        }

        $this->components->twoColumnDetail('Expires', $me->expiresAt?->format('Y-m-d H:i:s T') ?? 'never');
        $this->components->twoColumnDetail('Rate limit', sprintf('%d requests per minute', $me->rateLimit->requestsPerMinute));
        $this->components->twoColumnDetail('Abilities', $me->permissions === [] ? 'none' : (string) count($me->permissions));

        if ($me->permissions !== []) {
            $this->components->bulletList($me->permissions);
        }
    }

    private function tokenType(Me $me): string
    {
        if ($me->type !== TokenType::Unrecognized) {
            return $me->type->value;
        }

        $raw = $me->raw['type'] ?? null;

        return is_string($raw) ? $raw : 'unknown';
    }

    private function failure(string $name, LivckCloudException $e): int
    {
        $this->components->error($this->explain($name, $e));

        return self::FAILURE;
    }

    private function explain(string $name, LivckCloudException $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => sprintf(
                'The API does not accept the token of the connection [%s] (HTTP 401): it is unknown, revoked or expired. Create a new one under Settings > Organization > API tokens.',
                $name,
            ),
            $e instanceof FeatureNotAvailableException => sprintf(
                'The plan of the organization behind the connection [%s] does not include API access (HTTP 403, feature "%s"). API access starts with the Team plan.',
                $name,
                $e->featureKey() ?? 'api_access',
            ),
            $e instanceof PermissionDeniedException => sprintf('The API refuses the token of the connection [%s] (HTTP 403): %s', $name, $e->errorMessage()),
            $e instanceof TransportException => sprintf('The API could not be reached: %s', $e->getMessage()),
            default => sprintf('The check failed: %s', $e->getMessage()),
        };
    }
}
