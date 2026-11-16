<?php

namespace porro\craft\connect;

use Closure;
use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Request;
use Porro\Core\ConnectClient;
use Porro\Core\ConnectError;
use Porro\Core\ConnectResult;
use Porro\Core\Cron;
use Porro\Core\Report;
use Porro\Core\SiteKey;
use porro\craft\activity\Activity;
use porro\craft\FileCrontab;
use porro\craft\Plugin;
use porro\craft\SiteKeys;
use Throwable;

/**
 * "Connect to Porro" from the site's own control panel, with no key to copy.
 *
 * start(): make a key pair here, ask Porro to connect, and hand back the
 * page on Porro to send the browser to. finish(): the browser is back with
 * a grant; finish it with Porro, keep the key (SiteKeys), send the first
 * report. The key only becomes the site's when Porro accepts the grant.
 *
 * The request waits for about ten minutes, encrypted, in Porro's own
 * store. It can only be finished from the browser session that started it,
 * by the same signed-in person: the session holds a state value the
 * waiting request is filed under, so a grant for anyone else's request
 * finds nothing to finish.
 *
 * The controller owns the routes, the permission check and how a
 * ConnectFailure is shown.
 */
class PanelConnect
{
    /** PORRO_KEY is set: nothing to connect here. */
    public const ENV = 'env';

    /** Not the live site: no button, developer copy instead. */
    public const DEVELOPMENT = 'development';

    /** Connected with a key made here. */
    public const CONNECTED = 'connected';

    /** PORRO_ENABLED=false on the live site. */
    public const OFF = 'off';

    /** The live site, not connected: show the button. */
    public const READY = 'ready';

    public const MINUTES = 10;

    /** The return route in the control panel. */
    public const RETURN_PATH = 'porro/connect';

    private const SESSION = 'porro.connect';

    private const DONE = 'porro.connected';

    private SiteKeys $keys;

    public function __construct(?SiteKeys $keys = null)
    {
        $this->keys = $keys ?? Plugin::keys();
    }

    /**
     * What the panel should show.
     */
    public function state(): string
    {
        return match (true) {
            $this->keys->fromEnv() !== null => self::ENV,
            ! Plugin::production() => self::DEVELOPMENT,
            $this->keys->stored() !== null => self::CONNECTED,
            Plugin::offReason() !== null => self::OFF,
            default => self::READY,
        };
    }

    /**
     * The "Connect to Porro" panel at the top of Site health.
     *
     * The live site with no key shows the button, to whoever may connect.
     * A copy that isn't production shows why it doesn't connect instead.
     * A site connected from here says who to, with Disconnect for whoever
     * may connect. A site with PORRO_KEY in its environment shows only how
     * to disconnect: it's connected the way it always was. A site whose
     * key Porro turned away says so until it connects again.
     *
     * @param  bool  $connected  whether the checks found a working key
     * @param  string|null  $siteUrl  the site's page in Porro, when Porro said
     * @return array{panel: bool, upsell: bool, state: string, environment: string|null, porroHost: string, workspace: string|null, host: string|null, approvedAgo: string|null, openUrl: string, canConnect: bool, turnedAway: array{workspace: string|null, date: string|null, time: string|null}|null}
     */
    public function panel(bool $connected, ?string $siteUrl): array
    {
        $state = $this->state();
        $stored = $state === self::CONNECTED ? $this->keys->stored() : null;
        $canConnect = Plugin::canConnect();
        // Only where the plain "not connected" card would be, or nothing.
        $turnedAway = in_array($state, [self::READY, self::OFF], true) ? $this->turnedAway() : null;
        $panel = match ($state) {
            self::READY => $canConnect || $turnedAway !== null,
            self::OFF => $turnedAway !== null,
            self::DEVELOPMENT, self::CONNECTED => true,
            default => false,
        };

        return [
            'panel' => $panel,
            // The "run this command" copy, for whoever sees no panel and isn't connected.
            'upsell' => ! $panel && ! $connected,
            'state' => $state,
            // Null on a copy that says production but has devMode on.
            'environment' => self::copyEnvironment(),
            'porroHost' => self::porroHost(),
            'workspace' => $stored['workspace'] ?? null,
            // The site as Porro knows it, for the Disconnect dialog.
            'host' => $stored['host'] ?? null,
            'approvedAgo' => self::since($stored['connected_at'] ?? null),
            'openUrl' => $siteUrl ?? Plugin::url(),
            // Decides the Connect to Porro and Disconnect buttons.
            'canConnect' => $canConnect,
            'turnedAway' => $turnedAway,
        ];
    }

    /**
     * Who Porro turned the stored key away for and when, in the site's
     * own time zone and language. Null when it didn't.
     *
     * @return array{workspace: string|null, date: string|null, time: string|null}|null
     */
    private function turnedAway(): ?array
    {
        $note = $this->keys->turnedAway();

        if ($note === null) {
            return null;
        }

        $at = $note['at'] !== null ? strtotime($note['at']) : false;
        $formatter = Craft::$app->getFormatter();

        return [
            'workspace' => $note['workspace'],
            'date' => $at !== false ? $formatter->asDate($at, 'php:j F') : null,
            'time' => $at !== false ? $formatter->asTime($at, 'short') : null,
        ];
    }

    /**
     * Whether the browser is coming back from Porro with a grant.
     */
    public function returning(Request $request): bool
    {
        $id = $request->getQueryParam('porro_request');

        return is_string($id) && $id !== '';
    }

    /**
     * Ask Porro to connect this site.
     *
     * @param  string  $user  whoever is signed in to the control panel
     * @return string the page on Porro to send the browser to
     *
     * @throws ConnectFailure
     */
    public function start(Request $request, string $user): string
    {
        $this->guard($request);

        // The address the browser is on: the grant comes back to this host.
        $address = rtrim((string) $request->getHostInfo(), '/');
        $key = SiteKey::generate();

        try {
            $pending = $this->client()->start($key, $address, $address.self::returnPath(), ...$this->describe());
        } catch (ConnectError $e) {
            throw ConnectFailure::from($e);
        }

        $state = Craft::$app->getSecurity()->generateRandomString(40);

        Activity::store()->set($this->pendingKey($state), SiteKeys::seal((string) json_encode([
            'seed' => $key->seed(),
            'id' => $pending->id,
            'user' => $user,
        ])), self::MINUTES * 60);

        Craft::$app->getSession()->set(self::SESSION, ['state' => $state, 'id' => $pending->id]);

        return $pending->approveUrl;
    }

    /**
     * Finish it with the grant the browser brought back.
     *
     * @return string|null the page on Porro to send the browser on to, when there is one
     *
     * @throws ConnectFailure
     */
    public function finish(Request $request, string $user): ?string
    {
        $id = (string) $request->getQueryParam('porro_request');
        $grant = $request->getQueryParam('porro_grant');
        $next = $request->getQueryParam('porro_next');
        $session = Craft::$app->getSession();

        // The same link opened again once it's done: nothing left to do.
        if ($session->get(self::DONE) === $id && $this->keys->stored() !== null) {
            return $this->next($next);
        }

        $started = $session->get(self::SESSION);

        if (! is_array($started) || ! is_string($started['state'] ?? null) || ! is_string($started['id'] ?? null)) {
            throw new ConnectFailure(ConnectFailure::EXPIRED, Craft::t('porro', 'There’s no request to connect waiting in this browser session. It may have run out of time. Press Connect to Porro again.'));
        }

        // Only ever the request this session started, by the same person.
        $pending = $this->pending($started['state']);

        if (! hash_equals($started['id'], $id) || ($pending !== null && (! hash_equals($pending['id'], $id) || ! hash_equals($pending['user'], $user)))) {
            throw new ConnectFailure(ConnectFailure::MISMATCH, Craft::t('porro', 'That approval is for a different request from the one started here, so nothing was connected. Press Connect to Porro again.'));
        }

        if ($pending === null) {
            $session->remove(self::SESSION);

            throw new ConnectFailure(ConnectFailure::EXPIRED, Craft::t('porro', 'This request ran out of time. Press Connect to Porro again and approve it within ten minutes.'));
        }

        if (! is_string($grant) || $grant === '') {
            throw new ConnectFailure(ConnectFailure::MISMATCH, Craft::t('porro', 'Porro sent the browser back without an approval, so nothing was connected. Press Connect to Porro again.'));
        }

        $this->guard($request, returning: true);

        try {
            $result = $this->client()->complete(SiteKey::fromSeed($pending['seed']), $id, $grant);
        } catch (ConnectError $e) {
            // Porro couldn't be reached: the grant still works, so opening
            // the link again tries again. Anything else is final.
            if (! in_array($e->reason, [ConnectError::UNREACHABLE, ConnectError::RATE_LIMITED], true)) {
                $this->clear($started['state']);
            }

            throw ConnectFailure::from($e);
        }

        if (! $this->keep($result)) {
            throw new ConnectFailure(ConnectFailure::NOT_MIGRATED, Craft::t('porro', 'Porro approved this site, but the key couldn’t be saved in this site’s database, so it isn’t connected yet. Once the database is working, press Connect to Porro again.'));
        }

        $this->clear($started['state']);
        $session->set(self::DONE, $id);
        $this->firstReport($result->key);

        return $this->next($next);
    }

    /**
     * Porro has made the key the site's, and that can't be asked for
     * twice: a database that blinks gets a few goes at saving it.
     */
    private function keep(ConnectResult $result): bool
    {
        foreach ([0, 200_000, 600_000] as $wait) {
            usleep($wait);

            try {
                $this->keys->store($result->key, $result->workspace, $result->host);

                return true;
            } catch (Throwable) {
                // Try again; never the error's text, which could quote the row.
            }
        }

        return false;
    }

    /**
     * @throws ConnectFailure
     */
    private function guard(Request $request, bool $returning = false): void
    {
        if ($this->keys->fromEnv() !== null) {
            throw new ConnectFailure(ConnectFailure::ENV_KEY, Craft::t('porro', 'This site already has a site key in its environment (PORRO_KEY), and that key is the one it uses. Remove it first if you want to connect from here instead.'));
        }

        if (! Plugin::production()) {
            $environment = self::copyEnvironment();

            throw new ConnectFailure(ConnectFailure::NOT_PRODUCTION, $environment !== null
                ? Craft::t('porro', 'This copy’s environment is “{environment}”, so it doesn’t connect and sends no reports. Connect from the live site’s control panel.', ['environment' => $environment])
                : Craft::t('porro', 'This copy has devMode on, so it doesn’t connect and sends no reports. Connect from the live site’s control panel.'));
        }

        if (Plugin::offReason() !== null) {
            throw new ConnectFailure(ConnectFailure::OFF, Craft::t('porro', 'Reporting to Porro is switched off on this site (PORRO_ENABLED=false). Remove that setting, then connect.'));
        }

        if (! $this->keys->ready()) {
            throw new ConnectFailure(ConnectFailure::NOT_MIGRATED, Craft::t('porro', 'The plugin’s database tables aren’t there yet. Run the migrations (php craft migrate/all), then press Connect to Porro again.'));
        }

        if (! $returning && self::behindUntrustedProxy($request)) {
            throw new ConnectFailure(ConnectFailure::SCHEME, Craft::t('porro', 'Your browser is on https, but this site sees the request as plain http. That usually means it’s behind a load balancer or proxy it doesn’t trust yet, so Porro would be given the wrong address. Trust the proxy in Craft (the trustedHosts config setting), then press Connect to Porro again.'));
        }
    }

    /**
     * The browser says https and Craft says http: a proxy in front ends
     * TLS and Craft doesn't trust its forwarded headers. Asking Porro to
     * connect "http://…" would be the wrong site address.
     */
    public static function behindUntrustedProxy(Request $request): bool
    {
        if ($request->getIsSecureConnection()) {
            return false;
        }

        $host = strtolower((string) $request->getHostName());
        $headers = $request->getHeaders();

        foreach (['Origin', 'Referer'] as $header) {
            $from = parse_url((string) $headers->get($header));

            if (is_array($from) && strtolower($from['scheme'] ?? '') === 'https' && strtolower($from['host'] ?? '') === $host) {
                return true;
            }
        }

        // Craft drops forwarded headers from a proxy it doesn't trust
        // before anyone can read them, so these come from the server's own
        // copy of the request.
        $sent = fn (string $name): string => is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : '';
        $siteUrl = parse_url(self::siteUrl());

        return stripos($sent('HTTP_X_FORWARDED_PROTO'), 'https') !== false
            || strtolower($sent('HTTP_X_FORWARDED_SSL')) === 'on'
            || strtolower($sent('HTTP_FRONT_END_HTTPS')) === 'on'
            || stripos($sent('HTTP_CF_VISITOR'), '"https"') !== false
            || (is_array($siteUrl) && strtolower($siteUrl['scheme'] ?? '') === 'https' && strtolower($siteUrl['host'] ?? '') === $host);
    }

    /**
     * The address the site is set up with: the control panel's own
     * (baseCpUrl) when it has one, otherwise the primary site's.
     */
    private static function siteUrl(): string
    {
        try {
            $cp = Craft::$app->getConfig()->getGeneral()->baseCpUrl;

            return is_string($cp) && $cp !== '' ? $cp : (string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The return route as a path: the address comes from the host the
     * browser is on, not from the site's settings.
     */
    private static function returnPath(): string
    {
        $url = parse_url(UrlHelper::cpUrl(self::RETURN_PATH));
        $path = '/'.ltrim(is_array($url) ? ($url['path'] ?? '') : '', '/');

        // Without clean URLs, the route is in the query (index.php?p=…).
        return is_array($url) && isset($url['query']) ? $path.'?'.$url['query'] : $path;
    }

    /**
     * What the approval page shows about this site: the connector, what it
     * runs and its environment, as a report would say them.
     *
     * @return array{0: array{name: string, version: string}, 1: array{name: string|null, version: string|null, environment: string|null}, 2: string}
     */
    private function describe(): array
    {
        $name = 'porro/craft-connector';
        $connector = ['name' => $name, 'version' => Report::installedVersion($name) ?? 'dev'];
        // Only the live site gets this far, whatever it calls itself.
        $environment = Plugin::environment() ?? 'production';

        try {
            $report = Plugin::getInstance()->getReporter()->build()->toArray();

            return [$connector, [
                'name' => $report['platform']['name'] ?? 'craft',
                'version' => $report['platform']['version'] ?? null,
                'environment' => $report['platform']['environment'] ?? $environment,
            ], (string) ($report['php']['version'] ?? PHP_VERSION)];
        } catch (Throwable) {
            return [$connector, ['name' => 'craft', 'version' => Craft::$app->getVersion(), 'environment' => $environment], PHP_VERSION];
        }
    }

    /**
     * The first report, as `php craft porro/connect` sends it. The site is
     * connected whether or not it gets through: cron sends the next one.
     */
    private function firstReport(SiteKey $key): void
    {
        try {
            $plugin = Plugin::getInstance();
            $plugin->getSiteHealth()->forgetPanel();

            $file = App::env('PORRO_CRONTAB_FILE');
            $scheduled = Cron::isScheduled((string) Craft::getAlias('@root'), 'craft porro/report', is_string($file) && $file !== '' ? new FileCrontab($file) : null);

            // One try: someone is waiting on this page.
            $plugin->getReporter()->send($key->token(), withPackages: true, trigger: Report::TRIGGER_CONNECT, health: ['scheduler_in_crontab' => $scheduled], retries: 0);
        } catch (Throwable) {
            // Never the key or the error's text in a log.
        }
    }

    /**
     * Only ever on to the Porro this site is set up for.
     */
    private function next(mixed $next): ?string
    {
        return is_string($next) && $this->client()->onPorro($next) ? $next : null;
    }

    /**
     * @return array{seed: string, id: string, user: string}|null
     */
    private function pending(string $state): ?array
    {
        try {
            $value = Activity::store()->get($this->pendingKey($state));
            $plain = is_string($value) ? SiteKeys::open($value) : null;
            $data = $plain !== null ? json_decode($plain, true) : null;
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && is_string($data['seed'] ?? null) && is_string($data['id'] ?? null) && is_string($data['user'] ?? null)
            ? ['seed' => $data['seed'], 'id' => $data['id'], 'user' => $data['user']]
            : null;
    }

    private function clear(string $state): void
    {
        Activity::store()->delete($this->pendingKey($state));
        Craft::$app->getSession()->remove(self::SESSION);
    }

    private function pendingKey(string $state): string
    {
        return 'porro:connect:'.hash('sha256', $state);
    }

    private function client(): ConnectClient
    {
        return new ConnectClient(Plugin::url(), self::transport());
    }

    /**
     * Craft's Guzzle client, so the site's proxy settings apply. No
     * redirects, so the request only ever goes to the Porro the site is
     * set up with. ConnectClient drops anything this throws.
     *
     * @return Closure(string, array<string, string>, string): array{0: int, 1: string}
     */
    private static function transport(): Closure
    {
        return function (string $url, array $headers, string $body): array {
            $response = Craft::createGuzzleClient()->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);

            return [$response->getStatusCode(), (string) $response->getBody()];
        };
    }

    /**
     * What a copy that isn't the live site calls itself, or null when it
     * says production and it's devMode that gives it away.
     */
    private static function copyEnvironment(): ?string
    {
        $environment = Plugin::environment();

        return $environment !== null && ! Plugin::isProductionName($environment) ? $environment : null;
    }

    /**
     * Where the button takes the browser, as people know it: "porro.dev".
     */
    private static function porroHost(): string
    {
        $host = (string) parse_url(Plugin::url(), PHP_URL_HOST);

        return $host === 'app.porro.dev' || $host === '' ? 'porro.dev' : $host;
    }

    private static function since(?string $at): ?string
    {
        $time = $at ? strtotime($at) : false;

        if ($time === false) {
            return null;
        }

        return $time > time() - 60 ? Craft::t('porro', 'a moment ago') : Craft::$app->getFormatter()->asRelativeTime($time);
    }
}
