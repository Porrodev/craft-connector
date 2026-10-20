<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use InvalidArgumentException;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Sends a report to Porro now: `php craft porro/report`. Cron runs it
 * with --scheduled every 15 minutes; it's also safe in deploy scripts.
 */
class ReportController extends Controller
{
    /** @var bool Send the full package list even if composer.lock is unchanged */
    public bool $packages = false;

    /** @var bool Set by cron, so Porro knows cron is running */
    public bool $scheduled = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'packages', 'scheduled'];
    }

    public function actionIndex(): int
    {
        if (! Plugin::enabled()) {
            $this->stdout('Porro reporting is turned off (PORRO_ENABLED=false).'.PHP_EOL);

            return ExitCode::OK;
        }

        if (! Plugin::key()) {
            $this->stderr('No PORRO_KEY set. Run `php craft porro/connect <site key>` first.'.PHP_EOL);

            return ExitCode::CONFIG;
        }

        $reporter = Plugin::getInstance()->getReporter();

        if ($this->scheduled) {
            $reporter->beat();
        }

        try {
            $result = $reporter->send(withPackages: $this->packages, trigger: $this->scheduled ? 'schedule' : 'manual');
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage().PHP_EOL);

            return ExitCode::CONFIG;
        }

        if (! $result->ok) {
            $this->stderr("Porro didn’t accept the report: {$result->error}".PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Report sent to Porro.'.PHP_EOL);

        return ExitCode::OK;
    }
}
