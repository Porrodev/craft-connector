<?php

namespace porro\craft;

use Craft;
use InvalidArgumentException;
use Porro\Core\Client;
use Porro\Core\LockFile;
use Porro\Core\NpmLockFile;
use Porro\Core\Report;
use Porro\Core\Result;
use Porro\Core\SiteKey;
use yii\base\Component;

class Reporter extends Component
{
    private const SENT_LOCK_KEY = 'porro:lock-hash';

    private const SENT_NPM_LOCK_KEY = 'porro:npm-lock-hash';

    public function build(bool $withPackages = false): Report
    {
        $report = new Report('porro/craft-connector', 'craft');

        (new Collector)->collect($report);

        $lock = LockFile::read(Craft::getAlias('@root'));
        $report->lock($lock, $withPackages || ($lock && Craft::$app->getCache()->get(self::SENT_LOCK_KEY) !== $lock->hash));

        // The JavaScript the site builds with (Vite, Alpine…), same rules.
        $npm = NpmLockFile::read(Craft::getAlias('@root'));
        $report->npmLock($npm, $withPackages || ($npm && Craft::$app->getCache()->get(self::SENT_NPM_LOCK_KEY) !== $npm->hash));

        return $report;
    }

    /**
     * @param  array<string, mixed>  $health  extra health facts for this report only
     *
     * @throws InvalidArgumentException when the key isn't a Porro site key
     */
    public function send(?string $key = null, ?string $url = null, bool $withPackages = false, string $trigger = 'manual', array $health = []): Result
    {
        $client = new Client(SiteKey::parse((string) ($key ?? Plugin::key())), $url ?? Plugin::url());

        $report = $this->build($withPackages)->trigger($trigger);
        foreach ($health as $name => $value) {
            $report->health($name, $value);
        }
        $result = $client->send($report);

        if ($result->ok && (($result->needLock && ! $report->includesPackages()) || ($result->needNpmLock && ! $report->includesNpmPackages()))) {
            $report = $this->build(true)->trigger($trigger);
            foreach ($health as $name => $value) {
                $report->health($name, $value);
            }
            $result = $client->send($report);
        }

        if ($result->ok && $report->includesPackages()) {
            Craft::$app->getCache()->set(self::SENT_LOCK_KEY, $report->lockHash(), 0);
        }

        if ($result->ok && $report->includesNpmPackages()) {
            Craft::$app->getCache()->set(self::SENT_NPM_LOCK_KEY, $report->npmLockHash(), 0);
        }

        return $result;
    }

    /**
     * Records that cron ran, before building the report so it shows up in it.
     */
    public function beat(): void
    {
        Craft::$app->getCache()->set(Collector::CRON_KEY, gmdate('c'), 0);
    }
}
