<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use porro\craft\console\controllers\concerns\SetsUpCron;
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
        $this->setUpCron(ask: false, force: true);

        return ExitCode::OK;
    }
}
