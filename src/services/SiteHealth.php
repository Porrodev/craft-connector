<?php

namespace porro\craft\services;

use Closure;
use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use Porro\Core\Cron;
use Porro\Core\Health\Advisories;
use Porro\Core\Health\EndOfLife;
use Porro\Core\Health\Issue;
use Porro\Core\Health\PorroFindings;
use Porro\Core\Health\ReportChecks;
use Porro\Core\Health\SiteHealth as Checks;
use Porro\Core\Health\Sources;
use Porro\Core\LockFile;
use Porro\Core\SiteKey;
use porro\craft\Collector;
use porro\craft\FileCrontab;
use porro\craft\Plugin;
use Throwable;
use yii\base\Component;

/**
 * What the Site health page and widget show. Works without a Porro
 * account: PHP and Craft support dates, security advisories in
 * composer.lock, Craft and plugin updates, dev mode, migrations and the
 * queue. Once connected, whether cron runs and the site's open findings
 * in Porro too.
 */
class SiteHealth extends Component
{
    private const CACHE_KEY = 'porro:health:panel';

    private const TTL = 300;

    public const SEVERITIES = [
        Issue::CRITICAL => ['label' => 'Act now', 'status' => 'red'],
        Issue::WARNING => ['label' => 'Needs attention', 'status' => 'orange'],
        Issue::NOTICE => ['label' => 'Notice', 'status' => 'light'],
    ];

    /**
     * @param  bool  $fresh  check again now: support dates, advisories, Craft's updates and Porro's findings, rather than use what's cached
     * @return array<string, mixed>
     */
    public function get(bool $fresh = false): array
    {
        $cache = Craft::$app->getCache();

        if ($fresh) {
            $cache->delete(self::CACHE_KEY);
            $this->forget();
        }

        $health = $cache->get(self::CACHE_KEY);

        if (! is_array($health)) {
            $health = $this->run();
            $cache->set(self::CACHE_KEY, $health, self::TTL);
        }

        return $health;
    }

    /**
     * Forgets the panel's last result, so the next view checks again.
     */
    public function forgetPanel(): void
    {
        Craft::$app->getCache()->delete(self::CACHE_KEY);
    }

    /**
     * Forgets everything the checks cached, so the next run asks again.
     */
    private function forget(): void
    {
        $sources = self::sources();
        $eol = new EndOfLife($sources);
        $eol->forget('php');
        $eol->forget('craft');

        if ($lock = LockFile::read((string) Craft::getAlias('@root'))) {
            (new Advisories($sources))->forget($lock);
        }

        if ($key = $this->key()) {
            (new PorroFindings($sources, $key, Plugin::url()))->forget();
        }

        // Craft's own update check, which it otherwise caches for a day.
        try {
            Craft::$app->getUpdates()->getUpdates(true);
        } catch (Throwable $e) {
            Craft::warning('Couldn’t check for Craft updates: '.$e->getMessage(), __METHOD__);
        }
    }

    /**
     * The last result, without running any checks.
     *
     * @return array<string, mixed>|null
     */
    public function cached(): ?array
    {
        $health = Craft::$app->getCache()->get(self::CACHE_KEY);

        return is_array($health) ? $health : null;
    }

    /**
     * Template variables for the page and widget.
     *
     * @param  int|null  $limit  how many issues to list, for the widget
     * @param  bool  $cachedOnly  never run the checks, so the dashboard never waits on the network
     * @return array<string, mixed>
     */
    public function view(?int $limit = null, bool $cachedOnly = false): array
    {
        try {
            $health = $cachedOnly ? $this->cached() : $this->get();
        } catch (Throwable $e) {
            Craft::error('Porro site health checks failed: '.$e->getMessage(), __METHOD__);
            $health = null;
        }

        $porro = $health['porro'] ?? null;
        $all = Issue::sort(array_map(fn ($i) => Issue::fromArray($i), [...($porro['findings'] ?? []), ...($health['issues'] ?? [])]));
        $present = fn (array $issues) => array_map(fn (array $issue) => $issue + ['style' => self::SEVERITIES[$issue['severity']] ?? self::SEVERITIES[Issue::NOTICE]], $issues);
        $checkedAt = isset($health['checked_at']) ? strtotime($health['checked_at']) : null;

        return [
            'failed' => $health === null && ! $cachedOnly,
            'pending' => $health === null && $cachedOnly,
            'connected' => (bool) ($health['connected'] ?? false),
            'porro' => $porro,
            'porroOk' => (bool) ($porro['ok'] ?? false),
            'porroUrl' => $porro['site_url'] ?? null,
            'local' => $present($health['issues'] ?? []),
            'found' => $present($porro['findings'] ?? []),
            'top' => $present(array_map(fn (Issue $i) => $i->toArray(), $limit ? array_slice($all, 0, $limit) : $all)),
            'more' => $limit ? max(0, count($all) - $limit) : 0,
            'counts' => $health['counts'] ?? ['critical' => 0, 'warning' => 0, 'notice' => 0],
            'checked' => $health['checked'] ?? [],
            'unavailable' => $health['unavailable'] ?? [],
            'checkedAgo' => $checkedAt ? ($checkedAt > time() - 60 ? Craft::t('porro', 'just now') : Craft::$app->getFormatter()->asRelativeTime($checkedAt)) : null,
            'commands' => $health['commands'] ?? ['connect' => 'php craft porro/connect <site key>'],
            'suspicious' => is_array($health['activity']['flagged'] ?? null) ? count($health['activity']['flagged']) : 0,
        ];
    }

    /**
     * The site key, when the site is connected and the key is valid.
     */
    public function key(): ?SiteKey
    {
        $key = Plugin::key();

        if ($key === null || ! Plugin::enabled()) {
            return null;
        }

        try {
            return SiteKey::parse($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function run(): array
    {
        $health = new Checks(self::sources());

        $health->endOfLife(['php' => PHP_VERSION, 'craft' => Craft::$app->getVersion()]);

        try {
            $health->report(Plugin::getInstance()->getReporter()->build()->toArray());
        } catch (Throwable) {
            $health->unavailable(Craft::t('porro', 'Couldn’t read the site’s own health facts'));
        }

        $health->advisories(LockFile::read((string) Craft::getAlias('@root')));
        $health->add($this->updates(), Craft::t('porro', 'Craft and plugin updates'));

        // Craft needs no cron of its own; the connector does, once connected.
        if ($this->key()) {
            $health->add($this->cron(), Craft::t('porro', 'cron'));
        }

        $activity = $this->activity($health);

        return $health->toArray($this->key(), Plugin::url()) + [
            'activity' => $activity,
            'commands' => [
                'connect' => 'php craft porro/connect <site key>',
                'cron' => 'php craft porro/cron',
            ],
        ];
    }

    /**
     * Adds suspicious activity to the issues, and returns the Activity
     * page's summary.
     *
     * @return array<string, mixed>|null
     */
    private function activity(Checks $health): ?array
    {
        try {
            $activity = Plugin::getInstance()->getActivity();
            $summary = $activity->summary();

            foreach ($activity->issues($summary) as $issue) {
                $health->add($issue);
            }

            $health->add(null, Craft::t('porro', 'suspicious activity'));

            return $activity->overview($summary);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Craft's own update check, which Craft caches for a day.
     */
    private function updates(): ?Issue
    {
        try {
            $updates = Craft::$app->getUpdates();
            $count = (int) $updates->getTotalAvailableUpdates();
            $critical = (bool) $updates->getIsCriticalUpdateAvailable();
        } catch (Throwable) {
            return null;
        }

        if ($count < 1) {
            return null;
        }

        return new Issue(
            'cms_update',
            $critical ? Issue::CRITICAL : Issue::NOTICE,
            $critical
                ? Craft::t('porro', 'Critical update available')
                : Craft::t('porro', '{count, plural, =1{# update available} other{# updates available}}', ['count' => $count]),
            Craft::t('porro', 'Craft and plugin updates are listed in Utilities → Updates'),
            null,
            UrlHelper::cpUrl('utilities/updates'),
        );
    }

    /**
     * Whether cron runs porro/report. Without it, Porro only hears from the
     * site when someone uses the control panel or something updates.
     */
    private function cron(): ?Issue
    {
        $root = (string) Craft::getAlias('@root');

        try {
            $file = App::env('PORRO_CRONTAB_FILE');
            $io = is_string($file) && $file !== '' ? new FileCrontab($file) : null;
            $inCrontab = Cron::isScheduled($root, 'craft porro/report', $io);
        } catch (Throwable) {
            $inCrontab = null;
        }

        $lastRun = Craft::$app->getCache()->get(Collector::CRON_KEY);
        $issue = (new ReportChecks)->scheduler(is_string($lastRun) ? $lastRun : null, $inCrontab, 'Cron', 'php craft porro/cron', 35);

        if (! $issue || $issue->severity === Issue::NOTICE) {
            return $issue;
        }

        return new Issue(
            $issue->kind,
            $issue->severity,
            $issue->title,
            Craft::t('porro', 'Porro only hears from this site when someone uses the control panel or something updates'),
            $issue->fix,
        );
    }

    /**
     * Core's Sources on Craft's cache and HTTP client.
     */
    public static function sources(): Sources
    {
        $cache = Craft::$app->getCache();

        return new Sources(
            fn (string $key) => $cache->get($key),
            fn (string $key, mixed $value, int $ttl) => $cache->set($key, $value, $ttl),
            self::transport(),
        );
    }

    /**
     * Craft's Guzzle client, so the site's proxy settings apply. Streamed,
     * so it stops reading once a response is over the limit.
     *
     * @return Closure(string, string, array<string, string>, string|null, int, int): array{0: int, 1: string}
     */
    private static function transport(): Closure
    {
        return function (string $method, string $url, array $headers, ?string $body, int $timeout, int $maxBytes = Sources::MAX_BYTES): array {
            try {
                $response = Craft::createGuzzleClient(['timeout' => $timeout, 'http_errors' => false])
                    ->request($method, $url, array_filter(['headers' => $headers, 'body' => $body, 'stream' => true], fn ($v) => $v !== null));
                $stream = $response->getBody();
                $data = '';

                while (! $stream->eof() && strlen($data) <= $maxBytes) {
                    $data .= $stream->read(min(65536, $maxBytes + 1 - strlen($data)));
                }

                return [$response->getStatusCode(), $data];
            } catch (Throwable $e) {
                return [0, $e->getMessage()];
            }
        };
    }
}
