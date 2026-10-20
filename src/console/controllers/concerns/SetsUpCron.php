<?php

namespace porro\craft\console\controllers\concerns;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use Porro\Core\Cron;
use Porro\Core\CronResult;
use Porro\Core\CrontabIo;
use porro\craft\FileCrontab;

/**
 * Shared by porro/connect and porro/cron.
 *
 * @mixin Controller
 */
trait SetsUpCron
{
    /**
     * The crontab to read and write. PORRO_CRONTAB_FILE points it at a
     * plain file so tests never touch the real one.
     */
    protected function crontabIo(): CrontabIo
    {
        $file = App::env('PORRO_CRONTAB_FILE');

        return is_string($file) && $file !== '' ? new FileCrontab($file) : new CrontabIo;
    }

    /**
     * Reports go from cron. Add the line for the agency when the server
     * allows, otherwise show it to copy.
     */
    protected function setUpCron(bool $ask, bool $skip = false, bool $force = false): void
    {
        $command = 'craft porro/report --scheduled';
        $directory = (string) Craft::getAlias('@root');
        $line = Cron::line('*/15 * * * *', $directory, $command);
        $io = $this->crontabIo();

        if (Cron::isScheduled($directory, 'craft porro/report', $io) === true) {
            $this->stdout('Found porro/report in this server’s cron. The first scheduled report is due by '.date('H:i', (intdiv((int) floor(time() / 60), 15) + 1) * 900).', then every 15 minutes.'.PHP_EOL);

            return;
        }

        $crontab = Cron::current($io);

        $install = match (true) {
            $skip => false,
            $force => true,
            ! $ask, $crontab === null, ! $this->interactive => false,
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
}
