<?php

namespace porro\craft\activity;

use Craft;
use Porro\Core\Activity\Cidr;
use Porro\Core\Activity\Countries;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\IpActivity;
use Porro\Core\Activity\IpDetails;
use Porro\Core\Activity\NginxBlocklist;
use Porro\Core\Activity\NginxStatus;
use Porro\Core\Activity\Probes;
use Porro\Core\Activity\ProtectedRanges;
use Porro\Core\Activity\Recorder;
use Throwable;
use yii\caching\FileCache;

/**
 * Activity and blocklist data shaped for the control panel: labels,
 * countries, "2 minutes ago". The same shapes as the Laravel connector's
 * Presenter, so every panel shows the same things. Untrusted input (the
 * cached activity) is checked field by field, so anything odd is left out
 * rather than breaking the page.
 */
class Presenter
{
    /** How each signal reads on a chip. */
    public const SIGNALS = [Recorder::PROBE => 'probe', Recorder::LOGIN_FAILED => 'login', Recorder::NOT_FOUND => '404', Recorder::POST_FLOOD => 'form'];

    /** Entries shown on the Blocking page; the full lists have their own pages. */
    public const PREVIEW = 10;

    public const PER_PAGE = 50;

    /** Lists with their own page, and the filters each offers. */
    public const LISTS = [
        'blocklist' => ['all', Entry::MANUAL, Entry::SUGGESTED, Entry::CONFIG, 'expiring'],
        'safelist' => ['all', Entry::MANUAL, Entry::CONFIG, 'expiring'],
    ];

    private const FILTER_LABELS = [
        'all' => 'All',
        Entry::MANUAL => 'Manual',
        Entry::SUGGESTED => 'Suggested',
        Entry::CONFIG => 'From config',
        'expiring' => 'Expiring within 7 days',
    ];

    /** How each badge reads on the IP page, and how worrying it is. */
    public const BADGES = [
        IpDetails::TOR => ['label' => 'Tor exit', 'tone' => 'danger'],
        IpDetails::SPAMHAUS => ['label' => 'Spamhaus DROP', 'tone' => 'danger'],
        IpDetails::FAKE_CRAWLER => ['label' => 'Fake crawler', 'tone' => 'danger'],
        IpDetails::HOSTING => ['label' => 'Hosting provider', 'tone' => 'warning'],
        IpDetails::SCANNER => ['label' => 'Research scanner', 'tone' => 'neutral'],
    ];

    /** How each crawler is named. */
    private const CRAWLER_NAMES = ['googlebot' => 'Googlebot', 'bingbot' => 'Bingbot', 'applebot' => 'Applebot', 'yandexbot' => 'YandexBot'];

    public function __construct(private Activity $activity) {}

    /**
     * The Activity page, from the site health's "activity".
     *
     * @return array<string, mixed>|null
     */
    public function activity(mixed $activity): ?array
    {
        if (! is_array($activity)) {
            return null;
        }

        $flagged = [];

        foreach (is_array($activity['flagged'] ?? null) ? $activity['flagged'] : [] as $ip) {
            if (! is_array($ip) || ! is_string($ip['ip'] ?? null) || filter_var($ip['ip'], FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $signals = [];

            foreach (self::SIGNALS as $signal => $label) {
                if (($n = (int) ($ip['counts'][$signal] ?? 0)) > 0) {
                    $signals[] = ['label' => $label, 'count' => $n];
                }
            }

            usort($signals, fn ($a, $b) => $b['count'] <=> $a['count']);

            $flagged[] = [
                'ip' => $ip['ip'],
                'country' => self::country($ip['country'] ?? null),
                'signals' => $signals,
                'request' => trim(((string) ($ip['last_method'] ?? '')).' '.((string) ($ip['last_path'] ?? ''))),
                'seen' => isset($ip['last_seen']) ? self::ago((int) $ip['last_seen']) : null,
                'blocked' => $this->isBlocked($ip['ip']),
                'badges' => self::tableBadges((array) ($ip['badges'] ?? [])),
                'reason' => Craft::t('porro', 'Flagged: {signals}', ['signals' => implode(', ', array_map(fn ($s) => "{$s['label']} ×{$s['count']}", $signals))]),
            ];
        }

        $totals = is_array($activity['totals'] ?? null) ? $activity['totals'] : [];

        return [
            'recording' => (bool) ($activity['recording'] ?? false),
            'hours' => (int) ($activity['hours'] ?? 24),
            'totals' => [
                ['label' => Craft::t('porro', 'Probes for secrets and admin tools'), 'value' => (int) ($totals[Recorder::PROBE] ?? 0)],
                ['label' => Craft::t('porro', 'Failed logins'), 'value' => (int) ($totals[Recorder::LOGIN_FAILED] ?? 0)],
                ['label' => Craft::t('porro', '404s'), 'value' => (int) ($totals[Recorder::NOT_FOUND] ?? 0)],
                ['label' => Craft::t('porro', 'Form posts'), 'value' => (int) ($totals[Recorder::POST_FLOOD] ?? 0)],
            ],
            'flagged' => $flagged,
            'blocked' => (int) ($activity['blocked'] ?? 0),
            'safelisted' => (int) ($activity['safelisted'] ?? 0),
            'countries' => is_array($activity['countries'] ?? null) ? $activity['countries'] : null,
        ];
    }

    /**
     * The blocklist, safelist, enforcement and protected ranges.
     *
     * @return array<string, mixed>
     */
    public function blocking(): array
    {
        $now = time();

        try {
            $list = $this->activity->blocklist();
            $blocks = $list->blocks($now);
            $safe = $list->safe($now);
            $failed = ! Activity::tablesExist();
        } catch (Throwable $e) {
            Craft::error('Porro couldn’t read the blocklist: '.$e->getMessage(), __METHOD__);
            [$blocks, $safe, $failed] = [[], [], true];
        }

        $cloudflare = $this->activity->cloudflare()->status();
        $cloudflare['synced'] = $cloudflare['synced_at'] ? self::ago((int) $cloudflare['synced_at']) : null;
        $nginxPath = $this->activity->nginxPath();
        $servers = $this->activity->nginxServers()->present();

        return [
            'failed' => $failed,
            'cloudflare' => $cloudflare,
            'nginx' => [
                'on' => $this->activity->nginxEnabled(),
                'production' => $this->activity->isProduction(),
                'written' => is_file($nginxPath) ? self::ago((int) filemtime($nginxPath)) : null,
                'include' => "include {$nginxPath};",
                'realIp' => NginxBlocklist::realIp($this->activity->ranges()),
                'reload' => NginxStatus::reloadJob($nginxPath, $this->activity->nginxStamp()),
                'sync' => '* * * * * cd '.Craft::getAlias('@root').' && php craft porro/blocklist > /dev/null 2>&1',
                'servers' => $servers,
                'loaded' => count(array_filter($servers, fn ($s) => $s['ok'])),
            ],
            // The newest few; the rest are on the list's own page.
            'blocks' => array_map(fn (Entry $e) => $this->entry($e), array_slice(self::newestFirst($blocks), 0, self::PREVIEW)),
            'safe' => array_map(fn (Entry $e) => $this->entry($e), array_slice(self::newestFirst($safe), 0, self::PREVIEW)),
            'blockCount' => count($blocks),
            'safeCount' => count($safe),
            'enforcing' => $this->activity->enforcing(),
            'protected' => array_map(fn (array $list) => [
                'source' => $list['source'],
                'label' => Craft::t('porro', $list['label']),
                'why' => Craft::t('porro', $list['why']),
                'from' => array_map(fn (string $url) => ['url' => $url, 'text' => parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH)], $list['from']),
                'ranges' => $list['ranges'],
                'count' => count($list['ranges']),
                'on' => $list['on'],
                // How to switch it off (or back on), for the lists that can be.
                'env' => $list['optional'] ? Activity::PROTECT_ENV[$list['source']] ?? null : null,
            ], array_values(array_filter($this->activity->ranges()->about(), fn ($list) => $list['ranges'] !== [] || ! $list['on']))),
        ];
    }

    /** Where Cloudflare's API tokens are made. */
    public const CLOUDFLARE_TOKENS_URL = 'https://dash.cloudflare.com/profile/api-tokens';

    /**
     * The token's two permission policies, named as Cloudflare's dashboard
     * shows them: what to type in its search, what to tick, and under which
     * heading it appears.
     */
    public const CLOUDFLARE_POLICIES = [
        ['scope' => 'Specified Domains → this site’s domain', 'permissions' => [
            ['search' => 'zone waf', 'tick' => 'Zone WAF Rules', 'group' => 'App Security', 'access' => 'Edit'],
            ['search' => 'zone', 'tick' => 'Zone', 'group' => 'DNS & Zones', 'access' => 'Read'],
        ]],
        ['scope' => 'Entire Account', 'permissions' => [
            ['search' => 'lists', 'tick' => 'Account Rule Lists', 'group' => 'Rules & Configuration', 'access' => 'Edit'],
        ]],
    ];

    /**
     * The Cloudflare setup page: the token, where it goes, and connecting.
     *
     * @return array<string, mixed>
     */
    public function cloudflare(): array
    {
        $mode = $this->activity->cloudflare();
        $status = $mode->status();
        $status['synced'] = $status['synced_at'] ? self::ago((int) $status['synced_at']) : null;

        return [
            'cloudflare' => $status,
            'host' => $mode->host(),
            'tokensUrl' => self::CLOUDFLARE_TOKENS_URL,
            'policies' => self::CLOUDFLARE_POLICIES,
            'envLine' => 'PORRO_CLOUDFLARE_TOKEN=',
            'command' => 'php craft porro/cloudflare connect',
            'steps' => [
                'token' => $status['configured'],
                'connected' => $status['connected'],
            ],
        ];
    }

    /**
     * The nginx setup page: whether the site is behind Cloudflare (so the
     * real IP lines come first), whether the cache is shared, without which
     * only this server can be listed, and how far the servers have got.
     *
     * @return array<string, mixed>
     */
    public function nginx(?string $remoteAddr = null): array
    {
        $blocking = $this->blocking();
        $nginx = $blocking['nginx'];
        $ranges = $this->activity->ranges();

        // Seen through Cloudflare: remember it, so the deny file carries the
        // real IP lines without anyone having to set anything.
        if ($remoteAddr !== null && $ranges->isCloudflare($remoteAddr)) {
            Activity::store()->set(Activity::BEHIND_CLOUDFLARE, true, 0);
        }

        $servers = $nginx['servers'];
        $count = static fn (array $states) => count(array_filter($servers, fn ($s) => in_array($s['state'], $states, true)));

        return [
            'nginx' => $nginx,
            'behindCloudflare' => $blocking['cloudflare']['configured'] || ($remoteAddr !== null && $ranges->isCloudflare($remoteAddr)),
            'realIpInFile' => $this->activity->nginxRealIp(),
            'sharedCache' => ! (Activity::store() instanceof FileCache),
            'example' => self::nginxExample($nginx['include']),
            'progress' => [
                'servers' => count($servers),
                'included' => $count([NginxStatus::WAITING, NginxStatus::UNCONFIRMED, NginxStatus::LOADED]),
                'loaded' => $count([NginxStatus::LOADED]),
            ],
        ];
    }

    /**
     * A trimmed version of the site's nginx config, to show where the
     * include goes: inside server { }, above the first location.
     *
     * @return list<array{line: string, add: bool}>
     */
    public static function nginxExample(string $include): array
    {
        try {
            $host = parse_url((string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST) ?: 'example.com';
        } catch (Throwable) {
            $host = 'example.com';
        }

        $lines = [
            ['server {', false],
            ['    listen 443 ssl;', false],
            ["    server_name {$host};", false],
            ['    root '.NginxBlocklist::stablePath((string) Craft::getAlias('@webroot')).';', false],
            ['', false],
            ["    {$include}", true],
            ['', false],
            ['    location / {', false],
            ['        try_files $uri $uri/ /index.php?$query_string;', false],
            ['    }', false],
            ['    # … the rest of the config stays as it is', false],
            ['}', false],
        ];

        return array_map(fn ($l) => ['line' => $l[0], 'add' => $l[1]], $lines);
    }

    /**
     * The IP details page: who it is, what it did here in the last 48
     * hours, and what can be done about it.
     *
     * @param  array<string, mixed>|null  $reputation  AbuseIPDB's answer, when someone asked for it
     * @return array<string, mixed>
     */
    public function ip(string $ip, ?array $reputation = null): array
    {
        $ip = (string) Cidr::parse($ip);
        $flagged = null;

        foreach ($this->activity->summary(48)->flagged as $candidate) {
            if ($candidate->ip === $ip) {
                $flagged = $candidate;
            }
        }

        $history = $this->activity->history($ip);
        $details = $this->activity->details($ip, $flagged?->userAgent ?? ($history ? array_key_first($history->agents) : null));
        $crawler = $this->crawler($details);
        $badges = array_values(array_map(fn ($b) => (self::BADGES[$b] ?? ['label' => $b, 'tone' => 'neutral']) + ['key' => $b], $details['badges']));

        foreach ($badges as $i => $badge) {
            $badges[$i]['label'] = $badge['key'] === IpDetails::FAKE_CRAWLER && $crawler
                ? Craft::t('porro', 'Fake {crawler}', ['crawler' => $crawler['name']])
                : Craft::t('porro', $badge['label']);
        }

        foreach ($details['risks'] as $risk) {
            $badges[] = ['key' => 'risk', 'label' => "Cloudflare: {$risk}", 'tone' => 'danger'];
        }

        $range = Cidr::parse($ip)->isV4() ? (string) Cidr::parse($ip.'/24') : null;
        $network = array_filter([
            Craft::t('porro', 'Network') => trim(($details['network'] ?? '').($details['asn'] ? ' · AS'.$details['asn'] : '')) ?: null,
            Craft::t('porro', 'Type') => match ($details['network_type'] ?? null) {
                'hosting_provider' => Craft::t('porro', 'Hosting provider'),
                'isp' => Craft::t('porro', 'Internet provider'),
                'organization' => Craft::t('porro', 'Organisation'),
                default => null,
            },
            Craft::t('porro', 'Hostname') => $details['hostname'] ? $details['hostname'].' · '.Craft::t('porro', 'verified') : null,
        ]);

        try {
            $safelisted = $this->activity->blocklist()->isSafelisted($ip);
        } catch (Throwable) {
            $safelisted = false;
        }

        return [
            'ip' => $ip,
            'country' => self::country($details['country'] ?? ($flagged?->country ?? $history?->country)),
            'badges' => $badges,
            'network' => array_map(fn ($label, $value) => ['label' => $label, 'value' => $value, 'mono' => in_array($label, [Craft::t('porro', 'Hostname'), Craft::t('porro', 'User agent')], true)], array_keys($network), $network),
            'crawler' => $crawler,
            'activity' => $history ? $this->did($history) : null,
            'blocked' => $this->isBlocked($ip),
            'safelisted' => $safelisted,
            // On an always-protected list: can't be blocked, so no buttons.
            'protected' => ($source = $this->activity->ranges()->match($ip)) !== null ? Craft::t('porro', ProtectedRanges::label($source)) : null,
            // A whole /24 only for hosting networks: on an ISP it would catch neighbours.
            'range' => $range && ($details['network_type'] ?? null) === 'hosting_provider' ? $range : null,
            'reason' => $flagged
                ? Craft::t('porro', 'Flagged: {signals}', ['signals' => implode(', ', array_map(fn ($s, $n) => (self::SIGNALS[$s] ?? $s)." ×{$n}", array_keys($flagged->signals()), $flagged->signals()))])
                : Craft::t('porro', 'Blocked from IP details'),
            'reputation' => $reputation ? $reputation + ['lastAgo' => isset($reputation['last_reported']) && is_string($reputation['last_reported']) && strtotime($reputation['last_reported']) ? self::ago((int) strtotime($reputation['last_reported'])) : null] : null,
            'canCheckReputation' => $this->activity->abuseIpdbKey() !== '',
            'sources' => $details['sources'],
            'fetched' => self::ago((int) $details['fetched_at']),
            'attribution' => in_array('DB-IP', $details['sources'], true) ? ['text' => Countries::ATTRIBUTION, 'url' => Countries::ATTRIBUTION_URL] : null,
        ];
    }

    /**
     * The search, filter and page from a list page's query string, ignoring
     * anything that isn't a plain value (?q[]=… is just a search for nothing).
     *
     * @param  array<string, mixed>  $query
     * @return array{search: string, filter: string, page: int}
     */
    public static function listQuery(array $query): array
    {
        $string = fn (string $key, string $default) => is_string($query[$key] ?? null) || is_int($query[$key] ?? null) ? (string) $query[$key] : $default;

        return [
            'search' => $string('q', ''),
            'filter' => $string('filter', 'all'),
            'page' => max(1, (int) $string('page', '1')),
        ];
    }

    /**
     * One page of the blocklist or safelist, newest first: matching $search
     * (an IP finds the entries that cover it; anything else matches the
     * address, reason or who added it) and $filter (a source, or entries
     * expiring within 7 days).
     *
     * @return array<string, mixed>
     */
    public function entries(string $list, string $search = '', string $filter = 'all', int $page = 1): array
    {
        $now = time();
        $list = isset(self::LISTS[$list]) ? $list : 'blocklist';
        $filters = self::LISTS[$list];
        $filter = in_array($filter, $filters, true) ? $filter : 'all';
        $search = trim($search);

        try {
            $all = $list === 'safelist' ? $this->activity->blocklist()->safe($now) : $this->activity->blocklist()->blocks($now);
            $failed = ! Activity::tablesExist();
        } catch (Throwable $e) {
            Craft::error('Porro couldn’t read the '.$list.': '.$e->getMessage(), __METHOD__);
            [$all, $failed] = [[], true];
        }

        $matching = array_values(array_filter(self::newestFirst($all), fn (Entry $e) => self::matches($e, $search)));
        $counts = [];

        foreach ($filters as $key) {
            $counts[$key] = count(array_filter($matching, fn (Entry $e) => self::inFilter($e, $key, $now)));
        }

        $shown = array_values(array_filter($matching, fn (Entry $e) => self::inFilter($e, $filter, $now)));
        $pages = max(1, (int) ceil(count($shown) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'list' => $list,
            'failed' => $failed,
            'entries' => array_map(fn (Entry $e) => $this->entry($e), array_slice($shown, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            'total' => count($all),
            'shown' => count($shown),
            'search' => $search,
            'filter' => $filter,
            'filters' => array_map(fn (string $key) => ['key' => $key, 'label' => Craft::t('porro', self::FILTER_LABELS[$key]), 'count' => $counts[$key]], $filters),
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /**
     * "🇩🇪 DE", or "Tor" for Cloudflare's T1. Null when unknown.
     *
     * @return array{code: string, label: string}|null
     */
    public static function country(mixed $code): ?array
    {
        $code = Recorder::country(is_string($code) ? $code : null);

        if ($code === null) {
            return null;
        }

        if ($code === 'T1') {
            return ['code' => 'T1', 'label' => 'Tor'];
        }

        $flag = ctype_alpha($code) ? mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65) : '';

        return ['code' => $code, 'label' => trim("{$flag} {$code}")];
    }

    /**
     * "5 minutes ago", "in 2 days".
     */
    public static function ago(int $timestamp): string
    {
        $now = time();

        if ($timestamp > $now - 60 && $timestamp <= $now) {
            return Craft::t('porro', 'just now');
        }

        // An expiry: whole days, so 47 hours reads "in 2 days", not "in a day".
        if ($timestamp - $now >= 86400) {
            return Craft::t('porro', '{count, plural, =1{in # day} other{in # days}}', ['count' => (int) round(($timestamp - $now) / 86400)]);
        }

        return Craft::$app->getFormatter()->asRelativeTime($timestamp);
    }

    /**
     * When the IP says it's a search engine's crawler: whether that's true,
     * in words, with what blocking it would mean.
     *
     * @param  array<string, mixed>  $details
     * @return array{name: string, real: bool, title: string, body: string}|null
     */
    private function crawler(array $details): ?array
    {
        $key = $details['crawler'] ?? null;

        if (! $key) {
            return null;
        }

        $name = self::CRAWLER_NAMES[$key] ?? ucfirst($key);
        $domain = IpDetails::CRAWLERS[$key][0] ?? null;
        $real = ! in_array(IpDetails::FAKE_CRAWLER, $details['badges'], true);

        return $real ? [
            'name' => $name,
            'real' => true,
            'title' => Craft::t('porro', 'Real {crawler}', ['crawler' => $name]),
            'body' => Craft::t('porro', 'This address belongs to the search engine: it resolves to {host}. Blocking it would stop the site being crawled, so leave it unless you mean to.', ['host' => $details['hostname']]),
        ] : [
            'name' => $name,
            'real' => false,
            'title' => Craft::t('porro', 'Pretending to be {crawler}', ['crawler' => $name]),
            'body' => ($details['hostname']
                ? Craft::t('porro', 'Its user agent says {crawler}, but this address isn’t the search engine’s: real {crawler} addresses resolve to {domain}, this one is {host}.', ['crawler' => $name, 'domain' => $domain, 'host' => $details['hostname']])
                : Craft::t('porro', 'Its user agent says {crawler}, but this address isn’t the search engine’s: real {crawler} addresses resolve to {domain}, and this one has no matching name.', ['crawler' => $name, 'domain' => $domain]))
                .' '.Craft::t('porro', 'Scanners do this because many sites let crawlers through. Blocking it won’t affect search.'),
        ];
    }

    /**
     * What it did here over 48 hours: requests per hour, a summary, its
     * top paths and user agents, and the last requests.
     *
     * @return array<string, mixed>
     */
    private function did(IpActivity $ip): array
    {
        $hours = [];

        for ($i = 47; $i >= 0; $i--) {
            $hours[] = (int) ($ip->hourly[gmdate('YmdH', time() - $i * 3600)] ?? 0);
        }

        $peak = max(1, ...$hours);
        $times = fn (array $counts, array $labels = []) => implode(' · ', array_map(fn ($key, $n) => ($labels[$key] ?? $key).' ×'.number_format($n), array_keys($counts), $counts)) ?: null;
        $t = fn (string $text, array $params = []) => Craft::t('porro', $text, $params);

        return [
            'hours' => array_map(fn (int $n) => [
                'count' => $n,
                'height' => $n ? max(6, (int) round($n / $peak * 44)) : 2,
                'tone' => $n > 10 ? 'lots' : ($n > 0 ? 'some' : ''),
            ], $hours),
            'flagged' => $ip->flagged,
            'first' => self::ago($ip->firstSeen),
            'direct' => $ip->direct > 0 && ($ip->viaCloudflare > 0 || $this->activity->cloudflare()->configured()),
            'summary' => array_filter([
                $t('Requests') => ($ip->estimated ? $t('about {n}', ['n' => number_format($ip->requests)]) : number_format($ip->requests))
                    .($ip->turnedAway ? ' · '.$t('{count, plural, =1{# turned away by the block} other{# turned away by the block}}', ['count' => $ip->turnedAway]) : '')
                    .($ip->flagged ? '' : ' · '.$t('not flagged')),
                $t('Seen') => gmdate('j M H:i', $ip->firstSeen).' → '.gmdate('j M H:i', $ip->lastSeen),
                $t('Signals') => $times($ip->signals(), self::SIGNALS),
                $t('Looking for') => $times($ip->categories, array_map(fn ($label) => $t($label), Probes::LABELS)),
                $t('Responses') => $times($ip->statuses),
                $t('Route') => $times(array_filter([$t('through Cloudflare') => $ip->viaCloudflare, $t('straight to the server') => $ip->direct])),
                $t('Hosts') => $times($ip->hosts),
                $t('Methods') => $times($ip->methods),
            ]),
            'paths' => array_map(fn ($path, $row) => ['path' => (string) $path, 'count' => (int) $row[0], 'status' => (int) $row[1], 'exposed' => (int) $row[1] >= 200 && (int) $row[1] < 300], array_keys($ip->paths), $ip->paths),
            'otherPaths' => $ip->otherPaths,
            'agents' => array_map(fn ($agent, $n) => ['agent' => (string) $agent, 'count' => (int) $n], array_keys($ip->agents), $ip->agents),
            'requests' => array_map(fn ($r) => [
                'method' => (string) $r[0],
                'path' => (string) $r[1],
                'status' => (int) $r[2],
                'exposed' => (int) $r[2] >= 200 && (int) $r[2] < 300,
                'time' => gmdate('H:i', (int) $r[3]),
            ], $ip->recent),
        ];
    }

    /**
     * Short badges for the activity table. A research scanner says it's
     * low risk, which is why it sits at the bottom.
     *
     * @param  array<mixed>  $badges
     * @return list<array{label: string, tone: string}>
     */
    private static function tableBadges(array $badges): array
    {
        $labels = [
            IpDetails::SPAMHAUS => ['Spamhaus', 'danger'],
            IpDetails::TOR => ['Tor', 'danger'],
            'risk' => ['Cloudflare risk', 'danger'],
            IpDetails::FAKE_CRAWLER => ['Fake crawler', 'danger'],
            IpDetails::HOSTING => ['Hosting', 'warning'],
            IpDetails::SCANNER => ['Scanner, low risk', 'neutral'],
        ];
        $out = [];

        foreach ($labels as $key => [$label, $tone]) {
            if (in_array($key, $badges, true)) {
                $out[] = ['label' => Craft::t('porro', $label), 'tone' => $tone];
            }
        }

        return $out;
    }

    /**
     * @param  list<Entry>  $entries
     * @return list<Entry>
     */
    private static function newestFirst(array $entries): array
    {
        usort($entries, fn (Entry $a, Entry $b) => ($b->addedAt <=> $a->addedAt) ?: strnatcmp($a->cidr, $b->cidr));

        return $entries;
    }

    private static function matches(Entry $entry, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        if (Cidr::isValid($search) && ! str_contains($search, '/')) {
            return $entry->range()->contains($search);
        }

        $needle = mb_strtolower($search);

        foreach ([$entry->cidr, $entry->note, (string) $entry->addedBy] as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function inFilter(Entry $entry, string $filter, int $now): bool
    {
        return match ($filter) {
            'all' => true,
            'expiring' => $entry->expiresAt !== null && $entry->expiresAt <= $now + 7 * 86400,
            default => $entry->source === $filter,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Entry $entry): array
    {
        return [
            'cidr' => $entry->cidr,
            'note' => $entry->note,
            'source' => match ($entry->source) {
                Entry::SUGGESTED => Craft::t('porro', 'Suggested'),
                Entry::CONFIG => Craft::t('porro', 'From config'),
                default => Craft::t('porro', 'Manual'),
            },
            'by' => $entry->addedBy,
            'expires' => $entry->expiresAt ? self::ago($entry->expiresAt) : Craft::t('porro', 'never'),
            'fromConfig' => $entry->source === Entry::CONFIG,
        ];
    }

    private function isBlocked(string $ip): bool
    {
        try {
            return $this->activity->blocked($ip) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
