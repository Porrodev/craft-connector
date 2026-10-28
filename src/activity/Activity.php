<?php

namespace porro\craft\activity;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use craft\web\Request;
use Porro\Core\Activity\AbuseIpdb;
use Porro\Core\Activity\Blocklist;
use Porro\Core\Activity\Cidr;
use Porro\Core\Activity\ClientIp;
use Porro\Core\Activity\CloudflareError;
use Porro\Core\Activity\Countries;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\FlaggedIp;
use Porro\Core\Activity\IpDetails;
use Porro\Core\Activity\IpSet;
use Porro\Core\Activity\NginxBlocklist;
use Porro\Core\Activity\NginxStatus;
use Porro\Core\Activity\Probes;
use Porro\Core\Activity\ProtectedRanges;
use Porro\Core\Activity\Ranking;
use Porro\Core\Activity\Recorder;
use Porro\Core\Activity\Summary;
use Porro\Core\Health\Issue;
use Porro\Core\Health\Sources;
use porro\craft\jobs\MaintainBlocklist;
use porro\craft\jobs\SyncCloudflare;
use porro\craft\services\SiteHealth;
use Throwable;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\caching\DbCache;

/**
 * Suspicious activity and blocking for this site, on core's Activity.
 *
 * On a visitor's request this only ever reads the cache: the blocklist is
 * compiled there, and protected ranges come from the last fetch (or the
 * bundled copy). The network and the database are touched by cron and the
 * control panel, never by a page view.
 *
 * A local or staging copy (anything but production) enforces blocks but
 * doesn't record activity unless asked to: it often has the live
 * database, and its own traffic is noise.
 */
class Activity extends Component
{
    public const COMPILED = 'porro:blocklist:compiled';

    public const COMPILED_TTL = 300;

    /** Porro's own cache table, which Craft's clear-caches leaves alone. */
    public const CACHE_TABLE = '{{%porro_cache}}';

    /** Kept across deploys, so copied over from Craft's cache once. */
    private const CARRIED = [CloudflareMode::STATE, NginxServers::KEY, self::BEHIND_CLOUDFLARE];

    private static ?DbCache $store = null;

    public const BLOCKLIST_TABLE = '{{%porro_blocklist}}';

    public const SAFELIST_TABLE = '{{%porro_safelist}}';

    /** Accounts above this are too many to match hashes against. */
    private const USERS_LIMIT = 5000;

    /** The setting that switches each optional protected list off. */
    public const PROTECT_ENV = [
        ProtectedRanges::FORGE => 'PORRO_PROTECT_FORGE',
        ProtectedRanges::FORGE_HEALTH => 'PORRO_PROTECT_FORGE',
        ProtectedRanges::CRAWLERS => 'PORRO_PROTECT_CRAWLERS',
    ];

    private ?ProtectedRanges $ranges = null;

    private ?Recorder $recorder = null;

    private ?Probes $probes = null;

    private ?CloudflareMode $cloudflare = null;

    /** Set when someone opens the nginx setup page through Cloudflare. */
    public const BEHIND_CLOUDFLARE = 'porro:activity:behind-cloudflare';

    /** @var array{blocks: IpSet, safe: IpSet}|null */
    private ?array $compiled = null;

    public function isProduction(): bool
    {
        return in_array(strtolower((string) Craft::$app->env), ['production', 'prod', 'live'], true);
    }

    /**
     * Whether requests are recorded: PORRO_ACTIVITY when set, otherwise
     * only in production.
     */
    public function recording(): bool
    {
        $setting = self::flag('PORRO_ACTIVITY');

        return $setting ?? $this->isProduction();
    }

    /**
     * Whether blocked IPs get a 403: PORRO_BLOCK_APP, on unless set false.
     */
    public function enforcing(): bool
    {
        return self::flag('PORRO_BLOCK_APP') ?? true;
    }

    /**
     * Protected lists switched off with PORRO_PROTECT_FORGE or
     * PORRO_PROTECT_CRAWLERS.
     *
     * @return list<string>
     */
    public function switchedOff(): array
    {
        return array_values(array_filter(ProtectedRanges::OPTIONAL, fn (string $source) => self::flag(self::PROTECT_ENV[$source] ?? '') === false));
    }

    /**
     * Protected ranges from the cache only; cron fetches them.
     */
    public function ranges(): ProtectedRanges
    {
        return $this->ranges ??= new ProtectedRanges($this->cachedSources(), self::strings(self::config('protected')), $this->switchedOff());
    }

    /**
     * Protected ranges fetched now when the cached copy is old.
     */
    public function freshRanges(): ProtectedRanges
    {
        return $this->ranges = new ProtectedRanges(SiteHealth::sources(), self::strings(self::config('protected')), $this->switchedOff());
    }

    public function blocklist(): Blocklist
    {
        return new Blocklist(
            new DbEntryStore(self::BLOCKLIST_TABLE),
            new DbEntryStore(self::SAFELIST_TABLE),
            $this->ranges(),
            (array) self::config('safelist'),
        );
    }

    /**
     * Where activity, the Cloudflare connection and nginx check-ins live:
     * Porro's own table. Until its migration has run, Craft's cache, so
     * recording never fails on a missing table.
     */
    public static function store(): CacheInterface
    {
        if (self::$store !== null) {
            return self::$store;
        }

        $cache = Craft::$app->getCache();

        if (! $cache->getOrSet('porro:cache:ready', fn () => (int) Craft::$app->getDb()->tableExists(self::CACHE_TABLE), 600)) {
            return $cache;
        }

        return self::$store = new DbCache(['db' => Craft::$app->getDb(), 'cacheTable' => self::CACHE_TABLE]);
    }

    public function recorder(): Recorder
    {
        $cache = self::store();

        return $this->recorder ??= new Recorder(
            fn (string $key) => $cache->get($key) ?: null,
            fn (string $key, mixed $value, int $ttl) => $cache->set($key, $value, $ttl),
            function (string $key, int $ttl) use ($cache) {
                // Craft's cache has no increment: close enough for counting.
                $cache->set($key, ((int) $cache->get($key)) + 1, $ttl);
            },
            $this->ranges(),
            (string) Craft::$app->getConfig()->getGeneral()->securityKey,
            fn (string $ip) => $this->compiled()['safe']->has($ip),
            array_map('intval', (array) self::config('thresholds')),
        );
    }

    public function probes(): Probes
    {
        return $this->probes ??= new Probes('craft', self::strings(self::config('served')));
    }

    /**
     * The visitor's address. Craft trusts X-Forwarded-For from anyone
     * until trustedHosts names the site's proxies, so on that default the
     * connecting address is used and forwarded headers are ignored: a
     * visitor can't pick their own address to dodge a block or frame
     * someone. Cloudflare's own header is believed only when the request
     * really came from Cloudflare.
     */
    public function clientIp(Request $request): string
    {
        return ClientIp::resolve($this->peer($request), $request->getHeaders()->toArray(), $this->ranges());
    }

    /**
     * The visitor's country when Cloudflare sent it: free, no lookup.
     */
    public function viaCloudflare(Request $request): bool
    {
        return $this->ranges()->isCloudflare((string) $request->getRemoteIP());
    }

    /**
     * Whether $ip was flagged in the last 48 hours, so its every request
     * is counted for its page. Read with the blocklist: no extra lookup.
     */
    public function watching(string $ip): bool
    {
        return $this->compiled()['watch']->has($ip);
    }

    public function country(Request $request): ?string
    {
        return ClientIp::country($this->peer($request), $request->getHeaders()->toArray(), $this->ranges());
    }

    /**
     * Craft's view of the visitor once trustedHosts names the proxies;
     * until then, whoever connected.
     */
    private function peer(Request $request): string
    {
        $trusted = (array) Craft::$app->getConfig()->getGeneral()->trustedHosts;
        $open = $trusted === [] || in_array('any', $trusted, true);

        return (string) ($open ? $request->getRemoteIP() : $request->getUserIP());
    }

    /**
     * DB-IP's country file in Craft's storage, for IPs Cloudflare didn't place.
     */
    public function countries(): Countries
    {
        return new Countries(Craft::$app->getPath()->getStoragePath().'/porro');
    }

    /**
     * Who's behind $ip: DB-IP, the Tor and Spamhaus lists, and confirmed
     * reverse DNS. Cached a day per IP.
     *
     * @return array<string, mixed>
     */
    public function details(string $ip, ?string $userAgent = null): array
    {
        return (new IpDetails(SiteHealth::sources(), $this->countries(), $this->network(...), self::reverseDns()))->lookup($ip, $userAgent);
    }

    public function cloudflare(): CloudflareMode
    {
        return $this->cloudflare ??= new CloudflareMode($this);
    }

    public function nginxServers(): NginxServers
    {
        return new NginxServers($this);
    }

    /**
     * Whether a deny file is written for nginx: PORRO_BLOCK_NGINX.
     */
    public function nginxEnabled(): bool
    {
        return self::flag('PORRO_BLOCK_NGINX') ?? false;
    }

    public function nginxPath(): string
    {
        $path = trim((string) App::env('PORRO_NGINX_PATH'));

        // On zero-downtime deploys (releases/{id}), a path that outlives the release.
        return $path !== '' ? $path : NginxBlocklist::stablePath(Craft::$app->getPath()->getStoragePath().'/porro/nginx-blocklist.conf');
    }

    /** The stamp the reload job touches; the default suits Forge. */
    public function nginxStamp(): string
    {
        $stamp = self::config('nginxStamp');

        return is_string($stamp) && $stamp !== '' ? $stamp : NginxStatus::STAMP;
    }

    /**
     * Where the setup page looks to confirm nginx includes the file.
     *
     * @return list<string>
     */
    public function nginxConfigDirs(): array
    {
        return self::strings(self::config('nginxConfigDirs')) ?: NginxStatus::CONFIG_DIRS;
    }

    /**
     * Whether the deny file carries Cloudflare's real IP lines:
     * PORRO_NGINX_REAL_IP when set, otherwise when the site is behind
     * Cloudflare (a Cloudflare token, or the setup page seen through it).
     */
    public function nginxRealIp(): bool
    {
        return self::flag('PORRO_NGINX_REAL_IP') ?? ($this->cloudflare()->configured() || (bool) self::store()->get(self::BEHIND_CLOUDFLARE));
    }

    /**
     * Writes this server's nginx deny file, in nginx mode and production
     * only, and records how far this server's setup has got.
     *
     * @return bool|null whether the file changed; null when it isn't written here
     */
    public function writeNginx(): ?bool
    {
        if (! $this->nginxEnabled() || ! $this->isProduction()) {
            return null;
        }

        $realIp = $this->nginxRealIp() ? NginxBlocklist::realIp($this->ranges()) : null;
        $changed = NginxBlocklist::write($this->nginxPath(), NginxBlocklist::render($this->blocklist()->blocks(), null, $realIp));
        $this->nginxServers()->record();

        return $changed;
    }

    /**
     * AbuseIPDB's view of $ip, when PORRO_ABUSEIPDB_KEY is set. This sends
     * the IP to AbuseIPDB, so only call it when someone asks.
     *
     * @return array<string, mixed>|null
     */
    public function reputation(string $ip): ?array
    {
        return (new AbuseIpdb(SiteHealth::sources(), $this->abuseIpdbKey()))->check($ip);
    }

    public function abuseIpdbKey(): string
    {
        return trim((string) App::env('PORRO_ABUSEIPDB_KEY'));
    }

    /**
     * Why $ip is blocked, or null. Protected ranges and the safelist win.
     */
    public function blocked(string $ip): ?string
    {
        $compiled = $this->compiled();

        if ($compiled['safe']->has($ip) || $this->ranges()->match($ip) !== null) {
            return null;
        }

        return $compiled['blocks']->match($ip);
    }

    /**
     * Housekeeping from cron: fetch protected ranges (so requests never
     * wait on them), drop expired and now-protected entries, and keep the
     * country file current. Never throws.
     *
     * @return list<array{list: string, cidr: string, why: string}> what was removed
     */
    public function maintain(): array
    {
        try {
            if (! self::tablesExist()) {
                return [];
            }

            Craft::$app->getCache()->set('porro:activity:maintained', true, 86400);
            $this->freshRanges()->ranges();
            $removed = $this->blocklist()->prune();

            foreach ($removed as $entry) {
                Craft::info("Porro: removed {$entry['cidr']} from the {$entry['list']}: {$entry['why']}", __METHOD__);
            }

            $this->forgetCompiled();
            $this->tidyStore();
            $this->cloudflare()->sync();

            // A few checks a day at most, so a failed download isn't retried every run.
            $cache = Craft::$app->getCache();

            if ($this->recording() && ! $cache->get('porro:countries:checked')) {
                $cache->set('porro:countries:checked', true, 6 * 3600);
                $this->countries()->update();
            }

            return $removed;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Porro's table keeps expired rows until something reads them, so
     * clear them hourly: activity is kept 48 hours, not until the next
     * visit. Also carries state saved in Craft's cache by older versions
     * across, once.
     */
    private function tidyStore(): void
    {
        $store = self::store();
        $cache = Craft::$app->getCache();

        if (! $store instanceof DbCache) {
            return;
        }

        foreach (self::CARRIED as $key) {
            if (($value = $cache->get($key)) !== false && $store->get($key) === false) {
                $store->set($key, $value, 0);
                $cache->delete($key);
            }
        }

        if (! $cache->get('porro:cache:pruned')) {
            $cache->set('porro:cache:pruned', true, 3600);
            $store->gc(true);
        }
    }

    /**
     * maintain(), at most once a day, for sites without cron: Porro's cron
     * line only exists once a site is connected. Queued, since it downloads
     * lists and the country file, and the page that asked shouldn't wait.
     */
    public function maintainDaily(): void
    {
        $cache = Craft::$app->getCache();

        if (! $cache->get('porro:activity:maintained')) {
            $cache->set('porro:activity:maintained', true, 86400);
            Craft::$app->getQueue()->push(new MaintainBlocklist);
        }
    }

    /**
     * Call after any change to either list.
     */
    public function changed(): void
    {
        $this->forgetCompiled();

        // This server's nginx file now; the others catch up within a minute.
        try {
            $this->writeNginx();
        } catch (Throwable $e) {
            Craft::warning('Porro couldn’t write the nginx file: '.$e->getMessage(), __METHOD__);
        }

        // Cloudflare from the queue, so whoever blocked doesn't wait.
        if ($this->cloudflare()->configured() && $this->isProduction()) {
            Craft::$app->getQueue()->push(new SyncCloudflare);
        }
    }

    private function forgetCompiled(): void
    {
        Craft::$app->getCache()->delete(self::COMPILED);
        $this->compiled = null;
    }

    public function summary(int $hours = 24): Summary
    {
        return $this->recorder()->summary($hours);
    }

    /**
     * The summary's flagged IPs with a country for each, where known.
     *
     * @return list<FlaggedIp>
     */
    public function flagged(Summary $summary): array
    {
        // What's known without waiting: the Tor and Spamhaus lists, and any
        // lookup already cached from someone opening an IP's details.
        $details = new IpDetails(SiteHealth::sources(), null, null, fn () => null);

        return Ranking::apply(
            $this->countries()->fill($summary->flagged),
            function ($ip) use ($details) {
                try {
                    return $details->known($ip->ip, $ip->userAgent);
                } catch (Throwable) {
                    return ['badges' => [], 'risks' => [], 'network_type' => null];
                }
            },
        );
    }

    /**
     * The summary's issues, plus one for each account tried from many IPs,
     * named when it matches one of the site's users.
     *
     * @return list<Issue>
     */
    public function issues(Summary $summary): array
    {
        $issues = $summary->issues();
        $names = null;
        $threshold = (int) (self::config('thresholds')['login_users'] ?? 5);

        foreach ($summary->targets as $hash => $ips) {
            if ($ips < $threshold) {
                continue;
            }

            $names ??= $this->accountNames();
            $who = $names[$hash] ?? Craft::t('porro', 'An account');

            $issues[] = new Issue('suspicious_activity', Issue::WARNING, Craft::t('porro', '{who} targeted from {count} IPs', ['who' => $who, 'count' => $ips]), Craft::t('porro', 'Failed logins for the same account from many addresses · looks like password spraying · check it has two-step verification'));
        }

        return $issues;
    }

    /**
     * What the Activity page shows: totals, flagged IPs with a rough
     * country, and how many entries each list has.
     *
     * @return array<string, mixed>
     */
    public function overview(?Summary $summary = null): array
    {
        $summary ??= $this->summary();

        try {
            $list = $this->blocklist();
            [$blocked, $safelisted] = [count($list->blocks()), count($list->safe())];
        } catch (Throwable) {
            [$blocked, $safelisted] = [0, 0];
        }

        return [
            'recording' => $this->recording(),
            'hours' => $summary->hours,
            'totals' => $summary->totals,
            'flagged' => array_map(fn (FlaggedIp $ip) => $ip->toArray(), $this->flagged($summary)),
            'blocked' => $blocked,
            'safelisted' => $safelisted,
            'countries' => $this->countries()->available() ? ['attribution' => Countries::ATTRIBUTION, 'url' => Countries::ATTRIBUTION_URL] : null,
        ];
    }

    public static function tablesExist(): bool
    {
        try {
            return Craft::$app->getDb()->tableExists(self::BLOCKLIST_TABLE) && Craft::$app->getDb()->tableExists(self::SAFELIST_TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A setting from config/porro.php: safelist, protected, served or thresholds.
     */
    public static function config(string $key): mixed
    {
        try {
            $config = Craft::$app->getConfig()->getConfigFromFile('porro');
        } catch (Throwable) {
            return [];
        }

        return $config[$key] ?? [];
    }

    /**
     * A true/false environment setting, or null when unset.
     */
    public static function flag(string $name): ?bool
    {
        if ($name === '') {
            return null;
        }

        $value = App::env($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @return array{blocks: IpSet, safe: IpSet, watch: IpSet}
     */
    private function compiled(): array
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $cache = Craft::$app->getCache();
        $data = $cache->get(self::COMPILED);

        if (! is_array($data)) {
            try {
                $list = $this->blocklist();
                $data = [
                    'blocks' => array_map(fn (Entry $e) => [$e->cidr, $e->note], $list->blocks()),
                    'safe' => array_map(fn (Entry $e) => $e->cidr, $list->safe()),
                    'watch' => $this->recorder()->watched(),
                ];
                $cache->set(self::COMPILED, $data, self::COMPILED_TTL);
            } catch (Throwable) {
                // No tables yet, or no database: nothing is blocked.
                $data = ['blocks' => [], 'safe' => [], 'watch' => []];
            }
        }

        $blocks = new IpSet;

        foreach ($data['blocks'] ?? [] as [$cidr, $note]) {
            $blocks->add(Cidr::tryParse((string) $cidr), (string) $note);
        }

        return $this->compiled = ['blocks' => $blocks, 'safe' => IpSet::of($data['safe'] ?? []), 'watch' => IpSet::of($data['watch'] ?? [])];
    }

    /**
     * Username or email hash => email, for the site's users, when there
     * aren't too many.
     *
     * @return array<string, string>
     */
    private function accountNames(): array
    {
        try {
            $query = User::find()->status(null);

            if ($query->count() > self::USERS_LIMIT) {
                return [];
            }

            $names = [];

            foreach ($query->all() as $user) {
                $names[$this->recorder()->userHash((string) $user->email)] = (string) $user->email;
                $names[$this->recorder()->userHash((string) $user->username)] = (string) $user->email;
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Cloudflare's view of an IP's network, once a token is set. Intel
     * needs a permission most tokens won't have; once it's refused, Radar
     * for a day.
     *
     * @return array<string, mixed>|null
     */
    private function network(string $ip): ?array
    {
        $cloudflare = $this->cloudflare();

        if (! $cloudflare->configured()) {
            return null;
        }

        $cache = Craft::$app->getCache();
        $account = $cloudflare->state()['connection']['account'] ?? null;

        if ($account && ! $cache->get('porro:cloudflare:no-intel')) {
            try {
                return $cloudflare->client()->intel((string) $account, $ip) + ['source' => 'Cloudflare IP Intelligence'];
            } catch (CloudflareError $e) {
                if (in_array($e->status, [401, 403], true)) {
                    $cache->set('porro:cloudflare:no-intel', true, 86400);
                }
            }
        }

        return $cloudflare->client()->radar($ip) + ['network_type' => null, 'risks' => [], 'source' => 'Cloudflare Radar'];
    }

    /**
     * Sources that only read the cache, for request time: a miss falls
     * back to the bundled lists instead of waiting on the network.
     */
    private function cachedSources(): Sources
    {
        $cache = Craft::$app->getCache();

        return new Sources(
            fn (string $key) => $cache->get($key) ?: null,
            fn () => null,
            fn () => [0, ''],
        );
    }

    /**
     * Tests set PORRO_FAKE_REVERSE_DNS to "ip=host,…" so they never wait on real DNS.
     */
    private static function reverseDns(): ?\Closure
    {
        $fake = App::env('PORRO_FAKE_REVERSE_DNS');

        if (! is_string($fake) || $fake === '') {
            return null;
        }

        $map = [];

        foreach (explode(',', $fake) as $pair) {
            [$ip, $host] = array_pad(explode('=', $pair, 2), 2, '');
            $map[trim($ip)] = trim($host);
        }

        return fn (string $ip) => ($map[$ip] ?? '') ?: null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(array_map('strval', (array) $values)));
    }
}
