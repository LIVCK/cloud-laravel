<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Console;

use Illuminate\Console\Command;
use LIVCK\Cloud\Data\DeclaredHeartbeat;
use LIVCK\Cloud\Data\DeclaredSchedule;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;
use LIVCK\Cloud\Laravel\CloudManager;
use LIVCK\Cloud\Laravel\Heartbeats\ConfigPingUrls;
use LIVCK\Cloud\Laravel\Heartbeats\DeclarationFailure;
use LIVCK\Cloud\Laravel\Heartbeats\DeclarationFailureReason;
use LIVCK\Cloud\Laravel\Heartbeats\Heartbeat;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatDeclarer;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatList;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Testing\CloudFake;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * `php artisan livck-cloud:heartbeats:sync`: declares the heartbeats of the schedule, the
 * queues and `heartbeats.custom` to LIVCK now, whether the list changed or not, and shows per
 * key what LIVCK did, what it could not create and why, and which heartbeats it has for this
 * application and environment that the code no longer names. Keeps the ping URLs in the cache
 * as the scheduler does.
 *
 * `--dry-run` shows what would be sent and sends nothing. Meant for a deploy or CI step:
 * exits with 0 only when LIVCK accepted the list and every heartbeat exists, 1 when it did not,
 * and 2 when nothing can be declared from here (no token, heartbeats off in this environment,
 * a broken configuration).
 */
#[AsCommand(name: 'livck-cloud:heartbeats:sync')]
final class SyncHeartbeatsCommand extends Command
{
    /** @var string */
    protected $signature = 'livck-cloud:heartbeats:sync
        {--dry-run : Show what would be sent, without sending anything}';

    /** @var string */
    protected $description = 'Declare the LIVCK Cloud heartbeats of the schedule and the queues now, and show what LIVCK did';

    public function handle(HeartbeatMonitor $monitor, HeartbeatDeclarer $declarer, ConfigPingUrls $urls, CloudManager $manager): int
    {
        try {
            $list = $monitor->list();
            $declarable = $declarer->declarable($list);
            $declaration = $declarer->declaration($list);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $this->components->twoColumnDetail('Application', $list->app);
        $this->components->twoColumnDetail('Environment', $list->environment);

        foreach ($list->problems() as $problem) {
            $this->components->warn($problem->message);
        }

        $this->notDeclared($list, $declarable, $urls->keys());

        $fake = $manager->currentFake() instanceof CloudFake;
        $environmentOff = ! $fake && ! $monitor->allowsEnvironment();

        if ((bool) $this->option('dry-run')) {
            return $this->dryRun($declarable, (string) json_encode($declaration->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $environmentOff, $declarer->canDeclare(), $list->environment);
        }

        if ($environmentOff) {
            $this->components->error(sprintf(
                'Heartbeats are off in the environment [%s] (livck-cloud.heartbeats.environments), and declaring them from here would create heartbeats nothing pings. Run the command in the environment the scheduler runs in, for example with --env=production.',
                $list->environment,
            ));

            return self::INVALID;
        }

        if ($declarable->count() === 0) {
            $this->components->info('Nothing to declare: no heartbeat without a ping URL in the configuration has a schedule.');

            return self::SUCCESS;
        }

        if (! $declarer->canDeclare()) {
            $this->components->error('Declaring heartbeats needs an API token with heartbeats.declare (LIVCK_CLOUD_TOKEN). Without one, put the ping URLs under livck-cloud.heartbeats.urls.');

            return self::INVALID;
        }

        $outcome = $declarer->declare($list);

        if (! $outcome->result instanceof HeartbeatDeclarationResult) {
            $this->components->error($this->failureMessage($outcome->failure));

            return self::FAILURE;
        }

        $this->result($outcome->result, $list);

        return $outcome->complete() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * What went wrong, as the command reads: a 429 says when running it again makes sense.
     */
    private function failureMessage(?DeclarationFailure $failure): string
    {
        if (! $failure instanceof DeclarationFailure) {
            return 'The heartbeats could not be declared.';
        }

        if ($failure->reason === DeclarationFailureReason::RateLimited && $failure->until !== null) {
            $seconds = max(1, (int) ceil($failure->until - $failure->at));

            return sprintf(
                'LIVCK limits how often a token may declare heartbeats and asked to wait (HTTP 429), so nothing was declared. Run the command again in %d %s; the scheduler waits as long. The ping URLs known so far are used meanwhile.',
                $seconds,
                $seconds === 1 ? 'second' : 'seconds',
            );
        }

        return $failure->message;
    }

    private function dryRun(HeartbeatList $declarable, string $body, bool $environmentOff, bool $canDeclare, string $environment): int
    {
        if ($declarable->count() === 0) {
            $this->components->info('Nothing to declare: no heartbeat without a ping URL in the configuration has a schedule. Nothing was sent.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Kind', 'Schedule', 'Options'],
            array_map(fn(Heartbeat $heartbeat): array => [
                $heartbeat->key,
                $heartbeat->kind->value,
                $heartbeat->schedule?->describe() ?? '-',
                $this->describeOptions($heartbeat),
            ], $declarable->heartbeats()),
        );

        if ($this->output->isVerbose()) {
            $this->line($body);
        }

        $count = sprintf('%d %s', $declarable->count(), $declarable->count() === 1 ? 'heartbeat' : 'heartbeats');

        if ($environmentOff) {
            $this->components->warn(sprintf('Heartbeats are off in the environment [%s] (livck-cloud.heartbeats.environments): without --dry-run, the command declares nothing from here. Run it in the environment the scheduler runs in, for example with --env=production.', $environment));
        }

        if (! $canDeclare) {
            $this->components->warn('No API token is configured: without --dry-run, the command declares nothing, and neither does the scheduler. Set a token with heartbeats.declare (LIVCK_CLOUD_TOKEN), or put the ping URLs under livck-cloud.heartbeats.urls.');
        }

        $this->components->info($environmentOff || ! $canDeclare
            ? sprintf('%s to declare (POST /v1/heartbeats/declare), but not from here. Nothing was sent.', $count)
            : sprintf('%s would be declared (POST /v1/heartbeats/declare). Nothing was sent.', $count));

        return self::SUCCESS;
    }

    /**
     * The heartbeats of the list that are not declared, and why.
     *
     * @param list<string> $configured
     */
    private function notDeclared(HeartbeatList $list, HeartbeatList $declarable, array $configured): void
    {
        $skipped = [];

        foreach ($list as $heartbeat) {
            if ($declarable->has($heartbeat->key)) {
                continue;
            }

            $skipped[] = in_array($heartbeat->key, $configured, true)
                ? sprintf('%s: ping URL in the configuration', $heartbeat->key)
                : sprintf('%s: no schedule (give it the seconds between two pings under livck-cloud.heartbeats.custom)', $heartbeat->key);
        }

        if ($skipped !== []) {
            $this->components->twoColumnDetail('Not declared', (string) count($skipped));
            $this->components->bulletList($skipped);
        }
    }

    private function result(HeartbeatDeclarationResult $result, HeartbeatList $list): void
    {
        $this->table(
            ['Key', 'Kind', 'Schedule', 'Result', 'Status'],
            array_map(fn(DeclaredHeartbeat $heartbeat): array => [
                $heartbeat->key,
                $heartbeat->kind->value,
                $this->schedule($heartbeat->schedule),
                $heartbeat->result->value,
                $heartbeat->paused ? $heartbeat->status->value . ', paused' : $heartbeat->status->value,
            ], $result->heartbeats),
        );

        $this->components->twoColumnDetail('Created, updated, unchanged', sprintf('%d, %d, %d', $result->counts->created, $result->counts->updated, $result->counts->unchanged));

        if (! $result->notCreated->isEmpty()) {
            $this->components->error(sprintf('Not created: %s. Reason: %s', implode(', ', $result->notCreated->keys), HeartbeatDeclarer::notCreatedReason($result)));
        }

        if ($result->skippedTags !== []) {
            $this->components->warn(sprintf('Not tagged with %s: the organization has no room for more tags. The heartbeats exist all the same.', implode(', ', $result->skippedTags)));
        }

        if ($result->unlisted !== []) {
            $this->newLine();
            $this->line(sprintf('  <options=bold>In LIVCK, no longer in the code</> (%s in %s)', $list->app, $list->environment));
            $this->components->bulletList($result->unlistedKeys());
            $this->components->warn('These heartbeats stay in LIVCK and report an outage once their ping stays away. If you removed or renamed the task, delete the heartbeat in LIVCK.');
        }

        if ($result->allExist()) {
            $this->components->info(sprintf('%d %s declared.', count($result->heartbeats), count($result->heartbeats) === 1 ? 'heartbeat' : 'heartbeats'));
        }
    }

    private function schedule(DeclaredSchedule $schedule): string
    {
        return $schedule->isCron()
            ? sprintf('%s (%s)', $schedule->expression, $schedule->timezone)
            : sprintf('every %d s', $schedule->periodSeconds);
    }

    private function describeOptions(Heartbeat $heartbeat): string
    {
        $options = array_filter([
            'grace' => $heartbeat->graceSeconds,
            'max duration' => $heartbeat->maxDurationSeconds,
            'tolerance' => $heartbeat->scheduleTolerance,
        ], static fn(?int $value): bool => $value !== null);

        if ($options === []) {
            return '-';
        }

        return implode(', ', array_map(static fn(string $name, int $value): string => $name === 'tolerance' ? sprintf('%s %d missed %s', $name, $value, $value === 1 ? 'ping' : 'pings') : sprintf('%s %d s', $name, $value), array_keys($options), $options));
    }
}
