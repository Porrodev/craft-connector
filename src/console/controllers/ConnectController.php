<?php

namespace porro\craft\console\controllers;

use Craft;
use craft\console\Controller;
use InvalidArgumentException;
use Porro\Core\Cron;
use Porro\Core\CronResult;
use Porro\Core\CrontabIo;
use Porro\Core\SiteKey;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Connects this site to Porro: `php craft porro/connect <site key>`.
 */
class ConnectController extends Controller
{
    /** @var string|null Only if you run Porro somewhere other than app.porro.dev */
    public ?string $url = null;

    /** @var bool Add the cron line to this server's crontab without asking */
    public bool $cron = false;

    /** @var bool Leave the crontab alone */
    public bool $noCron = false;

    /** @var CrontabIo|null Swapped for a fake in tests */
    public ?CrontabIo $crontab = null;

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

        $this->setUpCron();

        $this->stdout(PHP_EOL.'Add `php craft porro/report` to your deploy script so Porro hears about deploys straight away.'.PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Reports go from cron. Add the line for the agency when the server
     * allows, otherwise show it to copy.
     */
    private function setUpCron(): void
    {
        $command = 'craft porro/report --scheduled';
        $directory = (string) Craft::getAlias('@root');
        $line = Cron::line('*/15 * * * *', $directory, $command);
        $io = $this->crontab ?? new CrontabIo;

        $crontab = Cron::current($io);
        if ($crontab !== null && Cron::hasEntry($crontab, $directory, 'craft porro/report')) {
            $this->stdout('Cron is already set up on this server. Reports will go every 15 minutes.'.PHP_EOL);

            return;
        }

        $install = match (true) {
            $this->noCron => false,
            $this->cron => true,
            $crontab === null, ! $this->interactive => false,
            default => $this->confirm('Reports go every 15 minutes from cron. Add the line to this server’s crontab now?', true),
        };

        $result = $install ? Cron::install($line, $directory, 'craft porro/report', $io) : null;

        if ($result === CronResult::Installed || $result === CronResult::AlreadyInstalled) {
            $this->stdout('Added to this server’s crontab. Reports will go every 15 minutes.'.PHP_EOL);

            return;
        }

        if ($result === CronResult::Failed || $result === CronResult::Unavailable) {
            $this->stdout('Couldn’t update the crontab on this server.'.PHP_EOL);
        }

        $this->stdout(PHP_EOL.'One step left: add this cron line so reports keep coming. On Forge or Ploi add it as a scheduled job; anywhere else, run `crontab -e`.'.PHP_EOL);
        $this->stdout("  {$line}".PHP_EOL);
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
