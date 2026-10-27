<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use InvalidArgumentException;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Sends a report to Porro now: `php craft porro/report`. Cron runs it
 * with --scheduled every 15 minutes; deploy scripts run it with --deploy.
 */
class ReportController extends Controller
{
    /** @var bool Send the full package list even if composer.lock is unchanged */
    public bool $packages = false;

    /** @var bool Set by cron, so Porro knows cron is running */
    public bool $scheduled = false;

    /** @var bool Set by the deploy script: reports the deploy and never fails it */
    public bool $deploy = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'packages', 'scheduled', 'deploy'];
    }

    public function actionIndex(): int
    {
        if ($reason = Plugin::offReason()) {
            $this->stdout("Porro reporting is off: {$reason}.".PHP_EOL);

            return ExitCode::OK;
        }

        if (! Plugin::key()) {
            $this->stderr('No PORRO_KEY set. Run `php craft porro/connect <site key>` first.'.PHP_EOL);

            return $this->failed(ExitCode::CONFIG);
        }

        $reporter = Plugin::getInstance()->getReporter();

        if ($this->scheduled) {
            $reporter->beat();
        }

        try {
            $result = $reporter->send(withPackages: $this->packages, trigger: match (true) {
                $this->scheduled => 'schedule',
                $this->deploy => 'deploy',
                default => 'manual',
            });
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage().PHP_EOL);

            return $this->failed(ExitCode::CONFIG);
        }

        if (! $result->ok) {
            $this->stderr("Porro didn’t accept the report: {$result->error}".PHP_EOL);

            return $this->failed(ExitCode::UNSPECIFIED_ERROR);
        }

        $this->stdout('Report sent to Porro.'.PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * A report that can't be sent never fails a deploy.
     */
    private function failed(int $code): int
    {
        return $this->deploy ? ExitCode::OK : $code;
    }
}
