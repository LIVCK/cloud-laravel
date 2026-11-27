<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Console;

use Illuminate\Console\Command;
use LIVCK\Cloud\Laravel\Heartbeats\ConfigPingUrls;
use LIVCK\Cloud\Laravel\Heartbeats\Heartbeat;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatDeclarer;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatMonitor;
use LIVCK\Cloud\Laravel\Heartbeats\HeartbeatSchedule;
use LIVCK\Cloud\Laravel\Heartbeats\PingUrlResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * `php artisan livck-cloud:heartbeats`: the heartbeats the package found in the schedule and
 * the configuration, whether each one is declared to LIVCK or has a ping URL in the
 * configuration, where its ping URL comes from right now, how the last declaration relates
 * to the code, the tasks it cannot watch and the options it leaves out. Sends nothing.
 */
#[AsCommand(name: 'livck-cloud:heartbeats')]
final class HeartbeatsCommand extends Command
{
    /** @var string */
    protected $signature = 'livck-cloud:heartbeats';

    /** @var string */
    protected $description = 'List the LIVCK Cloud heartbeats of the schedule and the queues, without sending anything';

    public function handle(HeartbeatMonitor $monitor, PingUrlResolver $urls, HeartbeatDeclarer $declarer, ConfigPingUrls $configured): int
    {
        try {
            $list = $monitor->list();
            $active = $monitor->isActive() || $monitor->allowsEnvironment();
            $configuredKeys = $configured->keys();
            $canDeclare = $declarer->canDeclare();
            $declaration = $declarer->describe($list, $active);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $this->components->twoColumnDetail('Application', $list->app);
        $this->components->twoColumnDetail('Environment', sprintf('%s, heartbeats %s', $list->environment, $active ? 'on' : 'off (livck-cloud.heartbeats.environments)'));

        if ($list->count() === 0) {
            $this->components->info('No heartbeats found. Mark a scheduled task with ->heartbeat() or name a queue under livck-cloud.heartbeats.queues.');
        } else {
            $this->components->twoColumnDetail('Last declaration', $declaration);

            $this->table(
                ['Key', 'Kind', 'Schedule', 'Declare', 'Ping URL'],
                array_map(static fn(Heartbeat $heartbeat): array => [
                    $heartbeat->key,
                    $heartbeat->kind->value,
                    $heartbeat->schedule?->describe() ?? '-',
                    match (true) {
                        in_array($heartbeat->key, $configuredKeys, true) => 'no, URL in config',
                        ! $heartbeat->schedule instanceof HeartbeatSchedule => 'no, no schedule',
                        ! $active => 'no, heartbeats off',
                        ! $canDeclare => 'no, no API token',
                        default => 'yes',
                    },
                    $urls->resolve($heartbeat->key)?->source->value ?? 'none',
                ], $list->heartbeats()),
            );
        }

        foreach ($list->problems() as $problem) {
            $this->components->warn($problem->message);
        }

        return self::SUCCESS;
    }
}
