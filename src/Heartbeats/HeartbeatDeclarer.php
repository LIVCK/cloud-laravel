<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use LIVCK\Cloud\Builders\HeartbeatBuilder;
use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\Builders\HeartbeatSchedule as SdkSchedule;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;
use LIVCK\Cloud\Enums\HeartbeatKind as SdkKind;
use LIVCK\Cloud\Enums\HeartbeatNotCreatedReason;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\AuthenticationException;
use LIVCK\Cloud\Exceptions\ConflictException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\RateLimitException;
use LIVCK\Cloud\Exceptions\RestrictedViewException;
use LIVCK\Cloud\Exceptions\ServerException;
use LIVCK\Cloud\Exceptions\TransportException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use Throwable;

/**
 * Declares the heartbeats of the code to LIVCK (`POST /v1/heartbeats/declare`), which creates
 * the missing ones and brings the others in line, and keeps the ping URLs of the answer in the
 * cache, where the scheduler and the workers find them.
 *
 * Declared are the heartbeats of the list except those with a ping URL in `heartbeats.urls`
 * (pinged at that URL, they never cost an API call) and those without a schedule.
 *
 * Only two places declare: the scheduler ({@see declareIfDue()}, once per `schedule:run`
 * before any task) and `livck-cloud:heartbeats:sync` ({@see declare()}). A worker or a web
 * process never does: it may hold the list of an earlier deploy, and would write it back.
 *
 * The scheduler declares only when the list differs from the one LIVCK last accepted, when
 * a ping URL turned out to be unknown (at most every {@see STALE_INTERVAL} seconds), or when
 * LIVCK could not create some heartbeats and the time to try again has come. A cache lock
 * keeps two processes from declaring at once. Each failure pauses the scheduler's attempts
 * for a while (see {@see failure()}); the ping URLs known so far are used meanwhile. Nothing
 * here throws into the scheduler.
 *
 * Under `LivckCloud::fake()` the fake answers the declaration and records it, with or without
 * a token.
 *
 * @internal
 */
final readonly class HeartbeatDeclarer
{
    /** Seconds without an attempt after LIVCK could not be reached or answered with a 5xx. */
    public const int PAUSE_UNREACHABLE = 60;

    /** Seconds without an attempt after LIVCK refused the token (401, 403). */
    public const int PAUSE_REFUSED = 900;

    /** At most one declaration per this many seconds because of a ping URL LIVCK did not know. */
    public const int STALE_INTERVAL = 600;

    /** Seconds until heartbeats LIVCK could not create are tried again, unless it says when. */
    public const int RETRY_NOT_CREATED = 900;

    /** Seconds the lock is held at most, should the process die while declaring. */
    public const int LOCK_SECONDS = 30;

    /** How many keys a log line names before it counts the rest. */
    private const int KEYS_IN_LOG = 10;

    /**
     * @param Closure(): Application $app resolves the current application
     */
    public function __construct(private Closure $app) {}

    /**
     * The heartbeats LIVCK is asked to keep: those without a ping URL in the configuration and
     * with a schedule.
     */
    public function declarable(HeartbeatList $list): HeartbeatList
    {
        $configured = $this->app()->make(ConfigPingUrls::class)->keys();

        return $list->filter(static fn(Heartbeat $heartbeat): bool => $heartbeat->schedule instanceof HeartbeatSchedule
            && ! in_array($heartbeat->key, $configured, true));
    }

    /**
     * The request body for the declarable heartbeats of a list.
     */
    public function declaration(HeartbeatList $list): HeartbeatDeclaration
    {
        $builders = [];

        foreach ($this->declarable($list) as $heartbeat) {
            $builders[] = $this->builder($heartbeat);
        }

        return HeartbeatDeclaration::of($list->app, $list->environment, ...$builders);
    }

    /** Whether the heartbeat connection has a token, or a fake stands in for it. */
    public function canDeclare(): bool
    {
        $manager = $this->manager();

        return $manager->currentFake() instanceof CloudFake || $manager->hasToken();
    }

    /**
     * The scheduler's declaration: only when it is due, under the lock, never throwing.
     */
    public function declareIfDue(HeartbeatList $list): void
    {
        try {
            $declarable = $this->declarable($list);

            if ($declarable->count() === 0) {
                return;
            }

            $hash = $declarable->hash();

            if (! $this->canDeclare()) {
                $this->noToken($declarable, $hash);

                return;
            }

            if (! $this->due($this->state(), $hash)) {
                return;
            }

            $lock = $this->lock();

            if ($lock === false) {
                return;
            }

            try {
                // Another process may have declared while this one waited for the lock.
                $state = $this->state();

                if ($this->due($state, $hash)) {
                    $this->send($list, $declarable, $state, true);
                }
            } finally {
                $lock?->release();
            }
        } catch (Throwable $e) {
            $this->warn('The heartbeats were not declared: {exception}', ['exception' => $e::class]);
        }
    }

    /**
     * Declare now, whatever the cache says, with the connection's own timeout and retries:
     * `livck-cloud:heartbeats:sync`. Writes the ping URLs and the state as the scheduler would,
     * logs nothing. Never throws.
     */
    public function declare(HeartbeatList $list): DeclarationOutcome
    {
        $declarable = $this->declarable($list);

        return $this->send($list, $declarable, $this->state(), false);
    }

    /**
     * How the last declaration relates to the code of now, for `livck-cloud:heartbeats`.
     *
     * @param bool $on whether heartbeats are on in this environment; off, its scheduler declares nothing
     */
    public function describe(HeartbeatList $list, bool $on = true): string
    {
        $declarable = $this->declarable($list);

        if ($declarable->count() === 0) {
            return 'nothing to declare';
        }

        if (! $on) {
            return 'not declared, heartbeats are off in this environment';
        }

        if (! $this->canDeclare()) {
            return 'not declared, no API token is configured';
        }

        $state = $this->state();
        $hash = $declarable->hash();
        $now = $this->now();

        if ($state->failure instanceof DeclarationFailure && $state->failure->pauses($now, $hash) && $state->failure->reason !== DeclarationFailureReason::NoToken) {
            return $state->failure->until === null
                ? sprintf('paused until the list changes: %s', $state->failure->reason->describe())
                : sprintf('paused until %s: %s', $this->time($state->failure->until), $state->failure->reason->describe());
        }

        if ($state->hash === null) {
            return 'never declared; the next scheduler run declares';
        }

        $at = $state->declaredAt === null ? '' : ' (' . $this->time($state->declaredAt) . ')';

        return $state->hash === $hash
            ? 'matches the code' . $at
            : 'differs from the code' . $at . '; the next scheduler run declares';
    }

    /** A moment in the application's timezone. */
    private function time(float $timestamp): string
    {
        return Date::createFromTimestamp($timestamp, date_default_timezone_get())->format('Y-m-d H:i:s T');
    }

    private function due(DeclarationState $state, string $hash): bool
    {
        $now = $this->now();
        $failure = $state->failure;

        if ($failure instanceof DeclarationFailure && $failure->reason !== DeclarationFailureReason::NoToken && $failure->pauses($now, $hash)) {
            return false;
        }

        if ($state->hash !== $hash) {
            return true;
        }

        if ($state->retryAt !== null && $now >= $state->retryAt) {
            return true;
        }

        return $this->urls()->hasStale()
            && ($state->staleAttemptAt === null || $now - $state->staleAttemptAt >= self::STALE_INTERVAL);
    }

    private function send(HeartbeatList $list, HeartbeatList $declarable, DeclarationState $state, bool $scheduler): DeclarationOutcome
    {
        $now = $this->now();
        $hash = $declarable->hash();
        $staleAttemptAt = $this->urls()->hasStale() ? $now : $state->staleAttemptAt;
        $declaration = HeartbeatDeclaration::of($list->app, $list->environment);

        try {
            $declaration = $this->declaration($list);
            $fake = $this->manager()->currentFake();

            $result = $fake instanceof CloudFake
                ? $fake->declare($declaration)
                : $this->app()->make(DeclarationClientFactory::class)
                    ->make($this->manager(), $scheduler ? $this->config()->declareTimeout() : null)
                    ->heartbeats()->declare($declaration);
        } catch (Throwable $e) {
            $failure = $this->failure($e, $now, $hash);
            $this->save($state->failed($failure, $staleAttemptAt));

            if ($scheduler) {
                $this->warn($failure->message, []);
            }

            return new DeclarationOutcome($declaration, null, $failure);
        }

        $this->urls()->putMany($result->pingUrls());
        $this->urls()->clearStale();

        $report = $scheduler && $state->reportedHash !== $hash;

        if ($report) {
            $this->report($list, $result);
        }

        $this->save($state->accepted($hash, $now, $this->retryAt($result, $now), $staleAttemptAt, $report));

        return new DeclarationOutcome($declaration, $result, null);
    }

    /**
     * What a failed declaration means, and how long the scheduler waits before the next.
     */
    private function failure(Throwable $e, float $now, string $hash): DeclarationFailure
    {
        $connection = $this->config()->connection();
        $stillPinged = 'The ping URLs known so far are used meanwhile.';

        [$reason, $pause, $message] = match (true) {
            $e instanceof ConflictException => [DeclarationFailureReason::Conflict, 0, 'Another process declared the same heartbeats at the same moment (HTTP 409). The next scheduler run declares them again.'],
            $e instanceof RateLimitException => [DeclarationFailureReason::RateLimited, $retry = max(1, $e->retryAfter() ?? self::PAUSE_UNREACHABLE), sprintf('LIVCK asked to wait before the heartbeats are declared (HTTP 429). The next try is in %d seconds. %s', $retry, $stillPinged)],
            $e instanceof AuthenticationException => [DeclarationFailureReason::InvalidToken, self::PAUSE_REFUSED, sprintf('LIVCK does not accept the API token of the connection [%s] (HTTP 401): it is unknown, revoked or expired, so the heartbeats were not declared. %s Create a token with heartbeats.declare under Settings > Organization > API tokens.', $connection, $stillPinged)],
            $e instanceof FeatureNotAvailableException => [DeclarationFailureReason::PlanWithoutApi, self::PAUSE_REFUSED, sprintf('The plan of the organization behind the connection [%s] does not include API access (HTTP 403), so the heartbeats were not declared. %s A heartbeat without one is not pinged: put its ping URL under livck-cloud.heartbeats.urls, which works on every plan, or choose a plan with API access.', $connection, $stillPinged)],
            $e instanceof RestrictedViewException => [DeclarationFailureReason::RestrictedView, self::PAUSE_REFUSED, sprintf('The API token of the connection [%s] sees only part of the organization (HTTP 403), so the heartbeats were not declared. A token sees what the member who created it sees, and this member\'s view is limited by access areas; declared heartbeats belong to the whole organization. %s More abilities do not help: create a token with heartbeats.declare as a member who sees the whole organization, and use it for the connection.', $connection, $stillPinged)],
            $e instanceof PermissionDeniedException => [DeclarationFailureReason::NotPermitted, self::PAUSE_REFUSED, sprintf('The API token of the connection [%s] may not declare heartbeats (HTTP 403: %s). %s Create a token with heartbeats.declare under Settings > Organization > API tokens.', $connection, rtrim($e->errorMessage(), '.'), $stillPinged)],
            $e instanceof ValidationException => [DeclarationFailureReason::Invalid, null, sprintf('LIVCK refused the list of heartbeats (HTTP 422): %s. It is not declared again until the list changes. %s', $this->fieldErrors($e), $stillPinged)],
            $e instanceof ServerException => [DeclarationFailureReason::ServerError, self::PAUSE_UNREACHABLE, sprintf('LIVCK could not take the declaration of the heartbeats (HTTP %d). The next try is in a minute. %s', $e->status(), $stillPinged)],
            $e instanceof TransportException => [DeclarationFailureReason::Unreachable, self::PAUSE_UNREACHABLE, sprintf('LIVCK could not be reached to declare the heartbeats (%s). The next try is in a minute. %s', $e->getMessage(), $stillPinged)],
            $e instanceof ApiException => [DeclarationFailureReason::Unexpected, self::PAUSE_UNREACHABLE, sprintf('LIVCK refused the declaration of the heartbeats (HTTP %d: %s). The next try is in a minute. %s', $e->status(), rtrim($e->errorMessage(), '.'), $stillPinged)],
            default => [DeclarationFailureReason::Unexpected, self::PAUSE_UNREACHABLE, sprintf('The heartbeats could not be declared (%s). The next try is in a minute. %s', $e::class, $stillPinged)],
        };

        return new DeclarationFailure($reason, $message, $now, $pause === null ? null : $now + $pause, $hash);
    }

    /** Once per list: no token, so nothing is declared. */
    private function noToken(HeartbeatList $declarable, string $hash): void
    {
        $state = $this->state();

        if ($state->failure?->reason === DeclarationFailureReason::NoToken && $state->failure->hash === $hash) {
            return;
        }

        $message = sprintf(
            'The heartbeats %s have no ping URL and are not declared: the connection [%s] has no API token. Set one that has heartbeats.declare (LIVCK_CLOUD_TOKEN), or put their ping URLs under livck-cloud.heartbeats.urls.',
            $this->keys($declarable->keys()),
            $this->config()->connection(),
        );

        $this->save($state->failed(new DeclarationFailure(DeclarationFailureReason::NoToken, $message, $this->now(), null, $hash), $state->staleAttemptAt));
        $this->warn($message, []);
    }

    /** What the answer holds that someone should act on: once per list. */
    private function report(HeartbeatList $list, HeartbeatDeclarationResult $result): void
    {
        if (! $result->notCreated->isEmpty()) {
            $this->warn('LIVCK did not create the heartbeats {keys}: {reason} They are not pinged until they exist; the others were declared.', [
                'keys' => $this->keys($result->notCreated->keys),
                'reason' => self::notCreatedReason($result),
            ]);
        }

        if ($result->unlisted !== []) {
            $this->warn('The heartbeats {keys} are in LIVCK for [{app}] in [{environment}] but no longer in the code. They stay in LIVCK and report an outage once their ping stays away. If you removed or renamed the task, delete the heartbeat in LIVCK.', [
                'keys' => $this->keys($result->unlistedKeys()),
                'app' => $list->app,
                'environment' => $list->environment,
            ]);
        }

        if ($result->skippedTags !== []) {
            $this->warn('LIVCK could not tag the heartbeats with {tags}: the organization has no room for more tags. The heartbeats were declared all the same.', [
                'tags' => $this->keys($result->skippedTags),
            ]);
        }
    }

    /**
     * Why LIVCK did not create some heartbeats, and what to do: a sentence.
     *
     * @internal also used by the sync command
     */
    public static function notCreatedReason(HeartbeatDeclarationResult $result): string
    {
        $notCreated = $result->notCreated;

        return match ($notCreated->reason) {
            HeartbeatNotCreatedReason::ServiceLimit => sprintf('the plan has no room for them (%s more services needed). Delete services you no longer need or choose a larger plan; the next declaration creates them.', $notCreated->missing === null ? 'more' : (string) $notCreated->missing),
            HeartbeatNotCreatedReason::CreationRate => $notCreated->retryAfter === null
                ? 'more services were created lately than the organization may create in an hour or a day, and this list is larger than that limit. Declare fewer at a time.'
                : sprintf('more services were created lately than the organization may create in an hour or a day. The next try is in %d seconds.', $notCreated->retryAfter),
            HeartbeatNotCreatedReason::Blocked => 'creating services is switched off for the organization. Contact LIVCK support.',
            default => 'LIVCK gave no reason this version knows.',
        };
    }

    private function retryAt(HeartbeatDeclarationResult $result, float $now): ?float
    {
        if ($result->notCreated->isEmpty()) {
            return null;
        }

        $retryAfter = $result->notCreated->reason === HeartbeatNotCreatedReason::CreationRate ? $result->notCreated->retryAfter : null;

        return $now + max(self::PAUSE_UNREACHABLE, $retryAfter ?? self::RETRY_NOT_CREATED);
    }

    private function builder(Heartbeat $heartbeat): HeartbeatBuilder
    {
        $schedule = $heartbeat->schedule;
        $sdkSchedule = $schedule instanceof HeartbeatSchedule && $schedule->isCron()
            ? SdkSchedule::cron((string) $schedule->expression, $schedule->timezone)
            : SdkSchedule::every((int) $schedule?->periodSeconds);

        $builder = HeartbeatBuilder::of(SdkKind::from($heartbeat->kind->value), $heartbeat->key, $sdkSchedule);

        if ($heartbeat->graceSeconds !== null) {
            $builder = $builder->grace($heartbeat->graceSeconds);
        }

        if ($heartbeat->maxDurationSeconds !== null) {
            $builder = $builder->maxDuration($heartbeat->maxDurationSeconds);
        }

        if ($heartbeat->scheduleTolerance !== null) {
            $builder = $builder->tolerance($heartbeat->scheduleTolerance);
        }

        return $builder;
    }

    private function fieldErrors(ValidationException $e): string
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            $errors[] = sprintf('%s: %s', $field, rtrim(implode(' ', $messages), '.'));
        }

        return $errors === [] ? rtrim($e->errorMessage(), '.') : implode('; ', $errors);
    }

    /**
     * `[a, b, c]`, or the first ones and how many more.
     *
     * @param list<string> $keys
     */
    private function keys(array $keys): string
    {
        $shown = array_slice($keys, 0, self::KEYS_IN_LOG);
        $more = count($keys) - count($shown);

        return '[' . implode(', ', $shown) . ']' . ($more > 0 ? sprintf(' and %d more', $more) : '');
    }

    /**
     * The lock of this connection, application and environment: null when the store has none,
     * false when another process holds it.
     */
    private function lock(): Lock|false|null
    {
        $store = HeartbeatStore::cache($this->app())->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        $lock = $store->lock(HeartbeatStore::key($this->app(), 'declaration-lock'), self::LOCK_SECONDS);

        return $lock->get() === true ? $lock : false;
    }

    private function state(): DeclarationState
    {
        try {
            $stored = HeartbeatStore::cache($this->app())->get(HeartbeatStore::key($this->app(), 'declaration'));

            return is_array($stored) ? DeclarationState::fromArray($stored) : new DeclarationState();
        } catch (Throwable) {
            return new DeclarationState();
        }
    }

    private function save(DeclarationState $state): void
    {
        try {
            HeartbeatStore::cache($this->app())->forever(HeartbeatStore::key($this->app(), 'declaration'), $state->toArray());
        } catch (Throwable) {
            // Without a cache, the next run declares again; nothing worse.
        }
    }

    /**
     * @param array<string, string> $context
     */
    private function warn(string $message, array $context): void
    {
        try {
            HeartbeatLog::logger($this->app())->warning($message, $context);
        } catch (Throwable) {
            // The log is the last resort.
        }
    }

    private function urls(): CachedPingUrls
    {
        return $this->app()->make(CachedPingUrls::class);
    }

    private function manager(): CloudManager
    {
        return $this->app()->make(CloudManager::class);
    }

    private function config(): HeartbeatConfig
    {
        return new HeartbeatConfig($this->app()->make(Config::class));
    }

    private function now(): float
    {
        return (float) Date::now()->format('U.u');
    }

    private function app(): Application
    {
        return ($this->app)();
    }
}
