<?php

namespace porro\craft;

use Craft;
use InvalidArgumentException;
use Porro\Core\Client;
use Porro\Core\LockFile;
use Porro\Core\NpmLockFile;
use Porro\Core\Report;
use Porro\Core\ReportThrottle;
use Porro\Core\Result;
use Porro\Core\SiteKey;
use yii\base\Component;

class Reporter extends Component
{
    private const SENT_LOCK_KEY = 'porro:lock-hash';

    private const SENT_NPM_LOCK_KEY = 'porro:npm-lock-hash';

    private const VERSIONS_KEY = 'porro:versions';

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
    public function send(?string $key = null, ?string $url = null, bool $withPackages = false, string $trigger = Report::TRIGGER_MANUAL, array $health = [], int $timeout = 15, int $retries = 2): Result
    {
        $client = new Client(SiteKey::parse((string) ($key ?? Plugin::key())), $url ?? Plugin::url(), retries: $retries, timeout: $timeout);

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

        if ($result->ok) {
            Craft::$app->getCache()->set(self::VERSIONS_KEY, $this->versions(), 0);

            // A backup report already claimed its slot.
            if ($trigger !== Report::TRIGGER_CP) {
                $this->throttle()->sent($trigger);
            }
        }

        return $result;
    }

    /**
     * The one throttle every report shares, kept in Craft's cache so
     * load-balanced servers with a shared cache share it too.
     */
    public function throttle(): ReportThrottle
    {
        $cache = Craft::$app->getCache();

        return new ReportThrottle(
            fn (string $key, int $value, int $ttl) => $cache->add($key, $value, $ttl),
            fn (string $key, int $value, int $ttl) => $cache->set($key, $value, $ttl),
        );
    }

    /**
     * True when Craft or a plugin was installed, updated or removed since
     * the last report. False when there's nothing to compare with yet,
     * e.g. after the cache was cleared.
     */
    public function versionsChanged(): bool
    {
        $sent = Craft::$app->getCache()->get(self::VERSIONS_KEY);

        return is_string($sent) && $sent !== $this->versions();
    }

    private function versions(): string
    {
        $versions = ['craft' => Craft::$app->getVersion()];

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $handle => $plugin) {
            $versions[$handle] = $plugin->getVersion();
        }

        ksort($versions);

        return hash('sha256', (string) json_encode($versions));
    }

    /**
     * Records that cron ran, before building the report so it shows up in it.
     */
    public function beat(): void
    {
        Craft::$app->getCache()->set(Collector::CRON_KEY, gmdate('c'), 0);
    }
}
