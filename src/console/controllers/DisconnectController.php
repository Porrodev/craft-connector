<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use porro\craft\connect\Disconnect;
use porro\craft\Plugin;
use porro\craft\SiteKeys;
use yii\console\ExitCode;

/**
 * Disconnects this site from Porro and deletes its key:
 * `php craft porro/disconnect`. Only for a key made by "Connect to Porro"
 * in the control panel; PORRO_KEY in the environment isn't this command's
 * to change.
 */
class DisconnectController extends Controller
{
    /** @var bool Don't ask first */
    public bool $force = false;

    /** @var bool This is the live site, whatever its environment says */
    public bool $live = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'force', 'live'];
    }

    /**
     * Tells Porro, then deletes the site key from this site's database.
     */
    public function actionIndex(): int
    {
        $keys = Plugin::keys();
        $disconnect = new Disconnect($keys);

        if ($keys->source() === SiteKeys::ENV) {
            $this->stderr('This site is connected with PORRO_KEY in its environment, which this command can’t change. Remove PORRO_KEY there and deploy.'.PHP_EOL);

            return ExitCode::CONFIG;
        }

        if (! $disconnect->available()) {
            $this->stdout('This site isn’t connected to Porro.'.PHP_EOL);

            return ExitCode::OK;
        }

        $stored = $keys->stored();
        $site = $stored['host'] ?? 'this site';
        $from = $stored['workspace'] ?? 'Porro';

        // A staging or local copy of the live database holds the live
        // site's key: disconnecting here would switch the live site off.
        if (! Plugin::production() && ! $this->live) {
            $environment = Plugin::environment();
            $this->stderr(($environment !== null && ! Plugin::isProductionName($environment)
                ? "This copy’s environment is “{$environment}”, not the live site."
                : 'This copy has devMode on, so it isn’t the live site.')
                .' Disconnecting here would switch off the live site’s key in Porro too. Run it on the live site, or add --live if this really is it.'.PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (! $this->force) {
            // Craft answers yes for a shell that can't be asked, such as a
            // deploy script. Deleting the key is never the default.
            if (! $this->interactive) {
                $this->stderr("Can’t ask here whether to disconnect {$site} from {$from}. Add --force to disconnect without being asked.".PHP_EOL);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            if (! $this->confirm("Disconnect {$site} from {$from}?")) {
                return ExitCode::OK;
            }
        }

        $result = $disconnect->disconnect();

        if ($result === null) {
            $this->stdout('This site isn’t connected to Porro.'.PHP_EOL);

            return ExitCode::OK;
        }

        $this->stdout(($result['told']
            ? 'Disconnected. Porro has been told.'
            : 'Disconnected on this site. Porro didn’t answer, so it hasn’t been told: it will show this site as not reporting.').PHP_EOL);
        $this->stdout('  The key has been deleted from this site’s database.'.PHP_EOL);
        $this->stdout('  The local checks still run. Connect again from the control panel, or with php craft porro/connect.'.PHP_EOL);

        return ExitCode::OK;
    }
}
