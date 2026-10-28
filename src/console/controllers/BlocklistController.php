<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use porro\craft\activity\NginxServers;
use porro\craft\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Tidies the blocklist and writes the nginx deny file:
 * `php craft porro/blocklist`. In nginx mode, cron runs it every minute on
 * each server.
 */
class BlocklistController extends Controller
{
    public function actionIndex(): int
    {
        $activity = Plugin::getInstance()->getActivity();

        foreach ($activity->maintain() as $removed) {
            $this->stdout("Removed {$removed['cidr']}: {$removed['why']}".PHP_EOL);
        }

        if (! $activity->nginxEnabled()) {
            return ExitCode::OK;
        }

        if (! $activity->isProduction()) {
            $this->stdout('Not writing the nginx file outside production.'.PHP_EOL);

            return ExitCode::OK;
        }

        try {
            $changed = $activity->writeNginx();
        } catch (Throwable $e) {
            $this->stderr($e->getMessage().PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(($changed ? 'Wrote '.$activity->nginxPath() : 'The nginx file is up to date.').PHP_EOL);

        $state = $activity->nginxServers()->all()[NginxServers::name()]['state'] ?? null;
        $this->stdout(NginxServers::name().': '.(NginxServers::LABELS[$state] ?? $state ?? '—').PHP_EOL);

        return ExitCode::OK;
    }
}
