<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Records the suspicious requests waiting in this server's buffer:
 * `php craft porro/activity/flush`. Cron can run it every minute on each
 * server, so flags never wait on the next suspicious request. The 15-minute
 * report and, in nginx mode, porro/blocklist flush too.
 */
class ActivityController extends Controller
{
    public $defaultAction = 'flush';

    public function actionFlush(): int
    {
        Plugin::getInstance()->getActivity()->flush();

        return ExitCode::OK;
    }
}
