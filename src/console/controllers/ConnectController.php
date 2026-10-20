<?php

namespace porro\craft\console\controllers;

use Craft;
use craft\console\Controller;
use InvalidArgumentException;
use Porro\Core\SiteKey;
use porro\craft\console\controllers\concerns\SetsUpCron;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Connects this site to Porro: `php craft porro/connect <site key>`.
 */
class ConnectController extends Controller
{
    use SetsUpCron;

    /** @var string|null Only if you run Porro somewhere other than app.porro.dev */
    public ?string $url = null;

    /** @var bool Add the cron line to this server's crontab without asking */
    public bool $cron = false;

    /** @var bool Leave the crontab alone */
    public bool $noCron = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'url', 'cron', 'noCron'];
    }

    /**
     * Saves the site key, sends the first report and sets up cron.
     */
    public function actionIndex(string $key): int
    {
        $url = $this->url ? rtrim($this->url, '/') : null;

        try {
            SiteKey::parse($key);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage().PHP_EOL);

            return ExitCode::USAGE;
        }

        $result = Plugin::getInstance()->getReporter()->send($key, $url ?? Plugin::url(), withPackages: true, trigger: 'connect');

        if (! $result->ok) {
            $this->stderr("Porro didn’t accept the report: {$result->error}".PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $saved = $this->saveEnv(array_filter(['PORRO_KEY' => $key, 'PORRO_URL' => $url]));

        $this->stdout('Connected. Porro has the first report.'.PHP_EOL);

        if (! $saved) {
            $this->stdout('Couldn’t write .env. Add these to the site’s environment:'.PHP_EOL);
            $this->stdout("  PORRO_KEY={$key}".PHP_EOL);
            if ($url) {
                $this->stdout("  PORRO_URL={$url}".PHP_EOL);
            }
        }

        $this->setUpCron(ask: true, skip: $this->noCron, force: $this->cron);

        $this->stdout(PHP_EOL.'Add `php craft porro/report` to your deploy script so Porro hears about deploys straight away.'.PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function saveEnv(array $values): bool
    {
        try {
            foreach ($values as $name => $value) {
                Craft::$app->getConfig()->setDotEnvVar($name, $value);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
