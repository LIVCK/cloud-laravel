<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

use Illuminate\Console\Scheduling\Event;

/**
 * Builds the {@see HeartbeatList} from the marked tasks of the schedule and the configuration.
 *
 * - A task that does not run in this environment (`environments()`) is left out silently.
 * - A closure or shell command without a key, two heartbeats with one key, a task under a
 *   condition without `every:` and an option below 1 become problems, not exceptions.
 * - A queue of `heartbeats.queues` becomes a heartbeat with a period of one minute and the
 *   grace of `heartbeats.queue_grace`; a grace below {@see QUEUE_GRACE_MIN} is a problem.
 * - A queued job is never pinged at its start, so a `maxDuration` it is given has nothing to
 *   measure: it is left out of the heartbeat and named as a problem, and the job is watched
 *   all the same.
 * - A key of `heartbeats.custom` becomes a custom heartbeat with that period; one the schedule
 *   or a queue already has, or a period below 1, becomes a problem.
 * - A key of `heartbeats.urls` that nothing else has becomes a custom heartbeat without a
 *   schedule: LIVCK alone knows when to expect it.
 *
 * @internal
 */
final readonly class HeartbeatListBuilder
{
    /** The pulse runs every minute. */
    public const int QUEUE_PERIOD = 60;

    /**
     * The pulse's default grace: with LIVCK's default of two missed periods in a row, a queue
     * outlasts two minutes without a pulse, as a task that runs every minute does, and is
     * reported about four minutes after its last pulse.
     */
    public const int QUEUE_GRACE = 120;

    /** The shortest grace LIVCK takes. */
    public const int QUEUE_GRACE_MIN = 30;

    /**
     * @param list<array{0: Event, 1: HeartbeatOptions}> $marked the marked tasks in schedule order
     * @param list<QueueTarget> $queues
     * @param list<string> $urlKeys the keys of `heartbeats.urls`
     * @param array<string, int> $custom `heartbeats.custom`: key to seconds between two pings
     * @param int $queueGrace `heartbeats.queue_grace`: the grace of each queue's heartbeat
     */
    public function build(
        string $app,
        string $environment,
        array $marked,
        array $queues,
        array $urlKeys,
        string $timezone,
        array $custom = [],
        int $queueGrace = self::QUEUE_GRACE,
    ): HeartbeatList {
        /** @var array<string, list<array{task: string, heartbeat: Heartbeat|null, problem: HeartbeatProblem|null, notice?: HeartbeatProblem}>> $byKey */
        $byKey = [];
        $problems = [];

        foreach ($marked as [$event, $options]) {
            $task = ScheduledTask::of($event);

            if (! $task->runsIn($environment)) {
                continue;
            }

            $key = self::keyOf($task, $options);

            if ($key === null) {
                $problems[] = new HeartbeatProblem(
                    null,
                    $task->label,
                    HeartbeatProblemReason::MissingKey,
                    sprintf('The task [%s] needs a key of its own: ->heartbeat(\'your-key\').', $task->label),
                );

                continue;
            }

            $byKey[$key][] = ['task' => $task->label, ...$this->entry($key, $task, $options, $timezone)];
        }

        foreach ($queues as $queue) {
            $label = sprintf('queue [%s] on [%s]', $queue->queue, $queue->connection);

            $byKey[$queue->key][] = $queueGrace < self::QUEUE_GRACE_MIN
                ? ['task' => $label, 'heartbeat' => null, 'problem' => new HeartbeatProblem(
                    $queue->key,
                    $label,
                    HeartbeatProblemReason::InvalidOption,
                    sprintf('livck-cloud.heartbeats.queue_grace is %d; LIVCK takes at least %d seconds.', $queueGrace, self::QUEUE_GRACE_MIN),
                )]
                : ['task' => $label, 'heartbeat' => new Heartbeat($queue->key, HeartbeatKind::Queue, HeartbeatSchedule::simple(self::QUEUE_PERIOD), $queueGrace), 'problem' => null];
        }

        $heartbeats = [];

        foreach ($byKey as $key => $candidates) {
            $key = (string) $key;

            if (count($candidates) > 1) {
                foreach ($candidates as $candidate) {
                    $problems[] = new HeartbeatProblem(
                        $key,
                        $candidate['task'],
                        HeartbeatProblemReason::DuplicateKey,
                        sprintf('The key [%s] is used %d times. Give each task its own key: ->heartbeat(\'%s-…\').', $key, count($candidates), $key),
                    );
                }

                continue;
            }

            if ($candidates[0]['problem'] instanceof HeartbeatProblem) {
                $problems[] = $candidates[0]['problem'];

                continue;
            }

            if ($candidates[0]['heartbeat'] instanceof Heartbeat) {
                $heartbeats[] = $candidates[0]['heartbeat'];
            }

            if (isset($candidates[0]['notice'])) {
                $problems[] = $candidates[0]['notice'];
            }
        }

        foreach ($custom as $key => $seconds) {
            $key = (string) $key;

            if (isset($byKey[$key])) {
                $problems[] = new HeartbeatProblem(
                    $key,
                    'livck-cloud.heartbeats.custom',
                    HeartbeatProblemReason::DuplicateKey,
                    sprintf('The key [%s] of livck-cloud.heartbeats.custom is a scheduled task or a queue already. Remove it from heartbeats.custom.', $key),
                );

                continue;
            }

            if ($seconds < 1) {
                $problems[] = new HeartbeatProblem(
                    $key,
                    'livck-cloud.heartbeats.custom',
                    HeartbeatProblemReason::InvalidOption,
                    sprintf('The heartbeat [%s] of livck-cloud.heartbeats.custom needs the seconds between two pings, a positive number.', $key),
                );

                continue;
            }

            $heartbeats[] = new Heartbeat($key, HeartbeatKind::Custom, HeartbeatSchedule::simple($seconds));
        }

        foreach ($urlKeys as $key) {
            if (! isset($byKey[$key]) && ! isset($custom[$key])) {
                $heartbeats[] = new Heartbeat($key, HeartbeatKind::Custom, null);
            }
        }

        return new HeartbeatList($app, $environment, $heartbeats, $problems);
    }

    /** The key a marked task is pinged under: the one given, or the one its kind implies. */
    public static function keyOf(ScheduledTask $task, HeartbeatOptions $options): ?string
    {
        if ($options->key !== null) {
            $key = trim($options->key);

            return $key === '' ? null : $key;
        }

        return $task->defaultKey;
    }

    /**
     * @return array{heartbeat: Heartbeat|null, problem: HeartbeatProblem|null, notice?: HeartbeatProblem}
     */
    private function entry(string $key, ScheduledTask $task, HeartbeatOptions $options, string $timezone): array
    {
        $invalid = $options->invalid();

        if ($invalid !== []) {
            return ['heartbeat' => null, 'problem' => new HeartbeatProblem(
                $key,
                $task->label,
                HeartbeatProblemReason::InvalidOption,
                sprintf('The heartbeat [%s] has %s below 1; %s.', $key, implode(', ', $invalid), count($invalid) === 1 ? 'it takes a positive number' : 'they take positive numbers'),
            )];
        }

        if ($task->conditional && $options->every === null) {
            return ['heartbeat' => null, 'problem' => new HeartbeatProblem(
                $key,
                $task->label,
                HeartbeatProblemReason::Conditional,
                sprintf('The task [%s] runs under a condition (when, skip or between), so its cron does not say when it runs. Name the longest gap you expect in seconds: ->heartbeat(every: 3600).', $task->label),
            )];
        }

        $schedule = $options->every !== null ? HeartbeatSchedule::simple($options->every) : $task->schedule($timezone);

        if ($task->queued && $options->maxDuration !== null) {
            return [
                'heartbeat' => new Heartbeat($key, $task->kind, $schedule, $options->grace, null, $options->tolerance),
                'problem' => null,
                'notice' => new HeartbeatProblem(
                    $key,
                    $task->label,
                    HeartbeatProblemReason::IgnoredOption,
                    sprintf('The heartbeat [%s] is a queued job, which is pinged once it has finished and never at its start, so maxDuration has nothing to measure and is not declared. Remove it; the grace covers a run that takes longer.', $key),
                ),
            ];
        }

        return ['heartbeat' => new Heartbeat($key, $task->kind, $schedule, $options->grace, $options->maxDuration, $options->tolerance), 'problem' => null];
    }
}
