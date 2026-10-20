<?php

namespace porro\craft;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\Queue;
use Porro\Core\Report;
use Throwable;

/**
 * Facts about the Craft install. Versions and counts only: never
 * content, entries, users, settings values or secrets.
 */
class Collector
{
    public const CRON_KEY = 'porro:cron:last-run';

    public function collect(Report $report): void
    {
        $general = Craft::$app->getConfig()->getGeneral();

        $report->platform(Craft::$app->getVersion(), Craft::$app->env ?: null);
        $report->layer('craft', Craft::$app->getVersion(), [
            'edition' => strtolower(Craft::$app->getEditionName()),
            'plugins' => $this->plugins(),
        ]);

        $report->health('debug', (bool) $general->devMode);
        $report->health('scheduler_last_run', $this->cronLastRun());
        $report->health('pending_migrations', $this->pendingMigrations());

        foreach ($this->queue((bool) $general->runQueueAutomatically) as $key => $value) {
            $report->health($key, $value);
        }
    }

    /**
     * @return array<int, array{handle: string, package: string|null, version: string|null, enabled: bool}>
     */
    private function plugins(): array
    {
        $plugins = [];

        foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $handle => $info) {
            if (! ($info['isInstalled'] ?? false)) {
                continue;
            }

            $plugins[] = [
                'handle' => (string) $handle,
                'package' => $info['packageName'] ?? null,
                'version' => $info['version'] ?? null,
                'enabled' => (bool) ($info['isEnabled'] ?? false),
            ];
        }

        return $plugins;
    }

    /**
     * When `porro/report --scheduled` last ran: the proof cron is set up.
     */
    private function cronLastRun(): ?string
    {
        try {
            $value = Craft::$app->getCache()->get(self::CRON_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function pendingMigrations(): ?int
    {
        try {
            // Handles with migrations waiting: "craft", "content" and any plugins.
            return count(Craft::$app->getUpdates()->getPendingMigrationHandles(true));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Craft's queue: how it runs, what's waiting and what failed. Counts
     * are only available for Craft's database queue.
     *
     * @return array<string, mixed>
     */
    private function queue(bool $runsAutomatically): array
    {
        $health = ['queue_runs_automatically' => $runsAutomatically];
        $queue = Craft::$app->getQueue();

        if (! $queue instanceof Queue) {
            return $health;
        }

        try {
            $oldest = (new Query)
                ->from(Table::QUEUE)
                ->where(['fail' => false, 'timeUpdated' => null])
                ->min('[[timePushed]]');

            $failed24h = (new Query)
                ->from(Table::QUEUE)
                ->where(['fail' => true])
                ->andWhere(['>=', 'dateFailed', gmdate('Y-m-d H:i:s', time() - 86400)])
                ->count();

            return $health + [
                'queue_waiting' => $queue->getTotalWaiting(),
                'queue_failed' => $queue->getTotalFailed(),
                'queue_oldest_waiting_at' => $oldest ? gmdate('c', (int) $oldest) : null,
                'failed_jobs_24h' => (int) $failed24h,
            ];
        } catch (Throwable) {
            return $health;
        }
    }
}
