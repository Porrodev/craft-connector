<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use InvalidArgumentException;
use Porro\Core\Report;
use porro\craft\console\controllers\concerns\SetsUpCron;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Adds the cron line on its own, without the site key:
 * `php craft porro/cron`. For when connect ran somewhere else, or cron
 * was skipped.
 */
class CronController extends Controller
{
    use SetsUpCron;

    public function actionIndex(): int
    {
        if ($this->setUpCron(ask: false, force: true)) {
            $this->tellPorro();
        }

        return ExitCode::OK;
    }

    /**
     * Send a report straight away, as connect does, so Porro says
     * "checking" rather than "cron isn't set up" until the first
     * scheduled report. Never fails the command.
     */
    private function tellPorro(): void
    {
        if (! Plugin::key()) {
            $this->stdout('No PORRO_KEY set, so no report sent. Run `php craft porro/connect <site key>` to connect.'.PHP_EOL);

            return;
        }

        if (Plugin::offReason() !== null) {
            return;
        }

        $error = null;

        try {
            $result = Plugin::getInstance()->getReporter()->send(trigger: Report::TRIGGER_CONNECT, health: ['scheduler_in_crontab' => true]);
            $error = $result->ok ? null : ($result->error ?: 'no reason given');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        if ($error === null) {
            $this->stdout('Told Porro cron runs porro/report.'.PHP_EOL);

            return;
        }

        $this->stdout("Couldn’t send a report to Porro: {$error}. The first scheduled report will tell it instead.".PHP_EOL);
    }
}
