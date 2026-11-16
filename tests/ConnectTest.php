<?php

use Porro\Core\ReportThrottle;
use Porro\Core\SiteKey;

/*
 * "Connect to Porro" on Site health. The stand-in Porro answers the start
 * and the finish as Porro does, checking both are signed by the key the
 * site made; these tests play the browser in between.
 */

// The stand-in's CONNECT_ID, and the site id it answers a finish with.
const REQUEST_ID = '0196f3a2-7c41-7e0b-9d55-2f6a8c1e4b70';
const SITE_ID = '01jabcdefghjkmnpqrstvwxyz0';

beforeEach(function () {
    // The live site, with no key in its environment and nothing stored.
    // These tests sign in more often than Craft 5 lets one IP in a minute.
    $this->env = (string) file_get_contents(craftPath().'/.env');
    file_put_contents(craftPath().'/.env', envWith($this->env, ['PORRO_KEY' => null, 'PORRO_ENABLED' => null, 'PORRO_URL' => porro()['url'], 'CRAFT_DEFAULT_RATE_LIMIT' => '0']));
    connectStore('forget');
    cache('delete', 'porro:health:panel');
    // As if a report had just gone, so no backup report rides on these
    // pages; the one connecting sends is sent whatever this says.
    cache('set', ReportThrottle::KEY, (string) time());
});

afterEach(function () {
    if (! isset($this->env)) {
        return;
    }

    file_put_contents(craftPath().'/.env', $this->env);
    cache('delete', ReportThrottle::KEY);
    connectStore('create-table');
    connectStore('forget');
});

/**
 * Presses "Connect to Porro". The token comes from the dashboard, which
 * everyone signed in can open, button or no button.
 *
 * @param  list<string>  $headers
 * @return array{status: int, location: string, body: string}
 */
function pressConnect(string $site, string $jar, array $headers = []): array
{
    $token = csrf(browse("{$site}/index.php?p=admin/dashboard", $jar)['body']);

    expect($token)->not->toBe('');

    return browse("{$site}/index.php?p=admin/actions/porro/connect/start", $jar, ['CRAFT_CSRF_TOKEN' => $token], $headers);
}

/**
 * The browser coming back from Porro with its approval.
 *
 * @param  array<string, string>  $query
 * @return array{status: int, location: string, body: string}
 */
function comeBack(string $site, string $jar, array $query = []): array
{
    $query += ['porro_request' => REQUEST_ID, 'porro_grant' => 'the-grant', 'porro_next' => approvePage()];

    return browse("{$site}/admin/porro/connect?".http_build_query($query), $jar);
}

/**
 * The page on the stand-in Porro the browser is sent to, and on to.
 */
function approvePage(): string
{
    return porro()['url'].'/connect/'.REQUEST_ID;
}

/**
 * What the stand-in Porro answers a start or a finish with.
 *
 * @param  array<string, array{0: int, 1: array<string, mixed>}>  $answers
 */
function porroAnswersConnect(array $answers): void
{
    file_put_contents(porro()['captures'].'/connect-answers', json_encode($answers));
}

/**
 * The starts or finishes the stand-in Porro was sent.
 *
 * @return list<array{body: array<string, mixed>, site: string|null}>
 */
function connectCalls(string $step): array
{
    $file = porro()['captures']."/connect-{$step}s";

    return array_map(fn ($line) => json_decode($line, true), is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : []);
}

/**
 * Signs in with a browser that may already hold a session.
 */
function signInWith(string $site, string $jar, string $username): void
{
    // Signed out first: Craft 4 answers a login from someone already signed
    // in with "you are in", and doesn't switch. Signing out keeps the
    // session and what's in it.
    http("{$site}/index.php?p=actions/users/logout", $jar);

    $info = json_decode(http("{$site}/index.php?p=actions/users/session-info", $jar)[1], true);
    [$status, $body] = http("{$site}/index.php?p=actions/users/login", $jar, [
        $info['csrfTokenName'] => $info['csrfTokenValue'],
        'loginName' => $username,
        'password' => 'ci-only-password',
    ]);

    expect($status)->toBe(200, $body);
}

/**
 * The browser's session as the server keeps it on disk.
 */
function sessionFile(string $jar): string
{
    // curl writes the jar when the handle goes; the last request's is gone.
    preg_match('/\tCraftSessionId\t(\S+)/', (string) file_get_contents($jar), $m);
    $file = (session_save_path() ?: sys_get_temp_dir()).'/sess_'.($m[1] ?? '');

    expect(is_file($file))->toBeTrue("No session file at {$file}");

    return (string) file_get_contents($file);
}

/**
 * Everything the site has written to its logs.
 */
function siteLogs(): string
{
    return implode('', array_map(fn ($file) => (string) file_get_contents($file), glob(craftPath().'/storage/logs/*.log') ?: []));
}

describe('what Site health shows', function () {
    it('shows the button on the live site to someone who may connect', function () {
        $site = site();

        $html = siteHealth($site, logIn($site));

        expect($html)
            ->toContain('Not connected to Porro')
            ->toContain('Have this site checked every hour')
            ->toContain('The checks below ran on this site just now. Connect it to Porro for hourly advisory checks, uptime from outside, and an alert the moment something changes.')
            ->toContain('Opens 127.0.0.1 to sign in and approve. No key to copy.')
            ->toContain('Connect to Porro')
            ->toContain('name="action" value="porro/connect/start"')
            ->toContain('name="CRAFT_CSRF_TOKEN"')
            // The button replaces "run this command".
            ->not->toContain('Watch this site from outside too')
            ->not->toContain('porro/connect &lt;site key&gt;');
    });

    it('gives the button to a user with the connect permission, and only them', function () {
        cpUser('connector', ['accessCp', 'accessPlugin-porro', 'porro-connectSite']);
        cpUser('blocker', ['accessCp', 'accessPlugin-porro', 'porro-manageBlocking']);
        $site = site();

        $with = siteHealth($site, logIn($site, 'connector'));
        $without = siteHealth($site, logIn($site, 'blocker'));

        expect($with)->toContain('value="porro/connect/start"')
            ->and($without)->not->toContain('value="porro/connect/start"')
            ->not->toContain('Not connected to Porro')
            // Without it they see what they saw before.
            ->toContain('Watch this site from outside too')
            ->toContain('php craft porro/connect &lt;site key&gt;');
    });

    it('lists the permission with the plugin’s others', function () {
        expect(connectStore('permissions'))->toBe([
            'porro-manageBlocking' => 'Block, unblock and safelist IPs',
            'porro-connectSite' => 'Connect the site to Porro',
        ]);
    });

    it('shows developer copy, not the button, on a copy that isn’t the live site', function (string $environment) {
        // Even one that has been told to report.
        $html = withEnvFile(fn ($env) => envWith($env, ['CRAFT_ENVIRONMENT' => $environment, 'PORRO_ENABLED' => 'true']), function () {
            $site = site();

            return siteHealth($site, logIn($site));
        });

        expect($html)
            ->toContain('Not connected to Porro')
            ->toContain('This is a development copy')
            ->toContain("Its environment is “{$environment}”, so it does not connect and sends no reports.")
            ->toContain('Its CRAFT_ENVIRONMENT should say production. You can also set a site key.')
            ->not->toContain('value="porro/connect/start"')
            ->not->toContain('Have this site checked every hour')
            ->not->toContain('Watch this site from outside too');
    })->with(['dev', 'staging']);

    it('says so when it’s devMode that makes it a copy', function () {
        $html = withEnvFile(fn ($env) => envWith($env, ['CRAFT_DEV_MODE' => 'true']), function () {
            $site = site();

            return siteHealth($site, logIn($site));
        });

        expect($html)
            ->toContain('This is a development copy')
            ->toContain('It has devMode on, so it does not connect and sends no reports.')
            ->not->toContain('value="porro/connect/start"');
    });

    it('shows nothing new when the site key is in the environment', function (string $environment) {
        $porro = porro();
        cache('delete', findingsKey($porro['url'], $porro['key']));

        $html = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => $porro['key'], 'CRAFT_ENVIRONMENT' => $environment, 'PORRO_ENABLED' => 'true']), function () {
            $site = site();

            return siteHealth($site, logIn($site));
        });

        expect($html)
            ->toContain('From Porro')
            ->not->toContain('Connect to Porro')
            ->not->toContain('Connected to Porro')
            ->not->toContain('Not connected to Porro')
            ->not->toContain('This is a development copy')
            ->not->toContain('Watch this site from outside too');
    })->with(['production', 'staging']);

    it('says who the site is connected to once it’s connected from here', function () {
        connectedFromPanel();
        // Anyone who can see Site health sees it, connect permission or not.
        cpUser('viewer', ['accessCp', 'accessPlugin-porro']);
        $site = site();

        $html = siteHealth($site, logIn($site, 'viewer'));

        expect($html)
            ->toContain('Connected to Porro')
            ->toContain('Connected to Marlowe Studio')
            ->toContain('Approved a moment ago. The first report has been sent, and Porro now checks this site every hour.')
            ->toContain('The key was made on this site and is kept encrypted in its database.')
            ->toContain('Open in Porro')
            // Where the stand-in Porro says the site's page is.
            ->toContain('href="https://porro.test/acme/sites/craft" target="_blank" rel="noopener" class="btn"')
            ->toContain('From Porro')
            ->not->toContain('value="porro/connect/start"')
            ->not->toContain('Watch this site from outside too');
    });

    it('escapes a workspace name', function () {
        connectedFromPanel('<script>alert("Marlowe")</script>');
        $site = site();

        $html = siteHealth($site, logIn($site));

        expect($html)
            ->toContain('Connected to &lt;script&gt;alert(&quot;Marlowe&quot;)&lt;/script&gt;')
            ->not->toContain('<script>alert("Marlowe")</script>');
    });
});

describe('connecting', function () {
    it('goes to Porro to approve, then finishes, keeps the key and sends the first report', function () {
        $site = site();
        $jar = logIn($site);

        $pressed = pressConnect($site, $jar);
        $asked = connectCalls('start');

        expect($pressed['status'])->toBe(302, $pressed['body'])
            ->and($pressed['location'])->toBe(approvePage())
            ->and($asked)->toHaveCount(1)
            // Signed as a key with no site yet.
            ->and($asked[0]['site'])->toBeNull()
            ->and($asked[0]['body']['address'])->toBe($site)
            ->and($asked[0]['body']['return_url'])->toBe("{$site}/admin/porro/connect")
            ->and($asked[0]['body']['connector']['name'])->toBe('porro/craft-connector')
            ->and($asked[0]['body']['platform'])->toMatchArray(['name' => 'craft', 'environment' => 'production'])
            ->and($asked[0]['body']['php']['version'])->toBe(PHP_VERSION)
            // Not the site's key yet: nothing is stored until Porro accepts the grant.
            ->and(connectStore('key'))->toMatchArray(['token' => null, 'stored' => null, 'row' => '']);

        $back = comeBack($site, $jar);
        $kept = connectStore('key');
        $key = SiteKey::parse((string) $kept['token']);

        expect($back['status'])->toBe(302, $back['body'])
            ->and($back['location'])->toBe(approvePage())
            ->and(connectCalls('complete'))->toHaveCount(1)
            ->and($key->publicKey())->toBe($asked[0]['body']['public_key'])
            ->and($key->siteId)->toBe(strtoupper(SITE_ID))
            ->and($kept['stored'])->toMatchArray(['workspace' => 'Marlowe Studio', 'host' => 'harbour-arts.org']);

        // The first report, signed as the site now.
        $connects = array_values(array_filter(reportsAfterwards(1), fn ($report) => $report['trigger'] === 'connect'));

        expect($connects)->toHaveCount(1)
            ->and($connects[0]['lock']['packages'])->toBeArray()
            ->and(file_get_contents(porro()['captures'].'/report-sites'))->toContain(strtoupper(SITE_ID));

        // Porro's page sends the person back; the panel now says connected.
        $done = browse("{$site}/admin/porro/connect", $jar);
        $html = siteHealth($site, $jar);
        [, $status] = craft(['porro/status']);

        expect($done['location'])->toEndWith('/admin/porro')
            ->and($html)->toContain('Connected to Marlowe Studio')->toContain('From Porro')
            ->and($status)->toContain('yes (connected from the control panel)');

        // And the link with the grant, opened again, does nothing new.
        $again = comeBack($site, $jar);

        expect($again['location'])->toBe(approvePage())
            ->and(connectCalls('complete'))->toHaveCount(1);

        // The key is nowhere a person or a log reader could come across it.
        $seed = $key->seed();

        expect($kept['row'])->not->toContain($seed)->not->toContain(SITE_ID)->not->toContain('Marlowe')
            ->and($html.$status.$back['body'].$done['body'])->not->toContain($seed)
            ->and(sessionFile($jar))->not->toContain($seed)
            ->and(siteLogs())->not->toContain($seed);
    });

    it('keeps the seed out of the session and out of anything readable while it waits', function () {
        $site = site();
        $jar = logIn($site);
        $before = connectStore('waiting');

        pressConnect($site, $jar);

        $session = sessionFile($jar);
        $waiting = array_diff_key(connectStore('waiting'), $before);

        comeBack($site, $jar);
        $seed = SiteKey::parse((string) connectStore('key')['token'])->seed();
        preg_match('/"state";s:40:"([^"]+)"/', $session, $state);

        // The session holds which request it started, and nothing secret.
        expect($session)->toContain('porro.connect')->toContain(REQUEST_ID)
            ->not->toContain($seed)
            ->not->toContain('seed')
            ->and($state)->not->toBeEmpty()
            // Waiting in Porro's own table: one row, filed under a hash
            // of the session's state, and encrypted.
            ->and($waiting)->toHaveCount(1)
            ->and(array_key_first($waiting))->not->toContain($state[1])
            ->and(base64_decode((string) reset($waiting)))->not->toContain($seed)
            ->not->toContain(REQUEST_ID)
            ->not->toContain('seed')
            // Finished, nothing is left waiting.
            ->and(array_diff_key(connectStore('waiting'), $before))->toBeEmpty()
            ->and(sessionFile($jar))->not->toContain('"state"');
    });

    it('only goes on to the Porro it’s set up for', function (string $next) {
        $site = site();
        $jar = logIn($site);
        pressConnect($site, $jar);

        $back = comeBack($site, $jar, ['porro_next' => $next]);

        expect($back['status'])->toBe(302, $back['body'])
            ->and($back['location'])->toEndWith('/admin/porro')
            ->and(connectStore('key')['token'])->not->toBeNull()
            ->and(siteHealth($site, $jar))->toContain('Connected to Porro. The first report has been sent.');
    })->with(['https://kestrel-legal.com/connect/'.REQUEST_ID, 'http://127.0.0.1.kestrel-legal.com/', 'javascript:alert(1)', '']);

    it('needs the connect permission to start or finish', function () {
        cpUser('viewer', ['accessCp', 'accessPlugin-porro']);
        cpUser('blocker', ['accessCp', 'accessPlugin-porro', 'porro-manageBlocking']);
        cpUser('connector', ['accessCp', 'accessPlugin-porro', 'porro-connectSite']);
        $site = site();

        foreach (['viewer', 'blocker'] as $username) {
            $jar = logIn($site, $username);

            expect(pressConnect($site, $jar)['status'])->toBe(403)
                ->and(comeBack($site, $jar)['status'])->toBe(403);
        }

        expect(connectCalls('start'))->toBeEmpty()
            ->and(connectCalls('complete'))->toBeEmpty();

        // Seeing Site health isn't enough; the permission is.
        $jar = logIn($site, 'connector');

        expect(pressConnect($site, $jar)['location'])->toBe(approvePage())
            ->and(comeBack($site, $jar)['location'])->toBe(approvePage())
            ->and(connectStore('key')['token'])->not->toBeNull();
    });

    it('sends guests to sign in', function () {
        $site = site();
        $jar = (string) tempnam(sys_get_temp_dir(), 'jar');
        $token = json_decode(http("{$site}/index.php?p=actions/users/session-info", $jar)[1], true)['csrfTokenValue'];

        $pressed = browse("{$site}/index.php?p=admin/actions/porro/connect/start", $jar, ['CRAFT_CSRF_TOKEN' => $token]);
        $back = comeBack($site, $jar);

        expect($pressed['location'])->toContain('login')
            ->and($back['location'])->toContain('login')
            ->and(connectCalls('start'))->toBeEmpty()
            ->and(connectCalls('complete'))->toBeEmpty();
    });

    it('starts with a POST that carries Craft’s CSRF token', function () {
        $site = site();
        $jar = logIn($site);

        $without = browse("{$site}/index.php?p=admin/actions/porro/connect/start", $jar, []);
        $get = browse("{$site}/index.php?p=admin/actions/porro/connect/start", $jar);

        expect($without['status'])->toBe(400)
            // Craft 5 says the method isn't allowed; Craft 4 calls it a bad request.
            ->and($get['status'])->toBeIn([400, 405])
            ->and(connectCalls('start'))->toBeEmpty();
    });

    it('won’t let another signed-in person finish someone’s request', function () {
        cpUser('connector', ['accessCp', 'accessPlugin-porro', 'porro-connectSite']);
        $site = site();
        $jar = logIn($site);
        pressConnect($site, $jar);

        // Someone else signs in in the same browser session.
        signInWith($site, $jar, 'connector');
        $back = comeBack($site, $jar);

        expect(sessionFile($jar))->toContain('porro.connect')
            ->and($back['location'])->toEndWith('/admin/porro')
            ->and(siteHealth($site, $jar))->toContain('That approval is for a different request from the one started here')
            ->and(connectCalls('complete'))->toBeEmpty()
            ->and(connectStore('key')['token'])->toBeNull();
    });

    it('won’t finish a request this session didn’t start', function () {
        $site = site();
        $jar = logIn($site);
        pressConnect($site, $jar);

        // The same link in another browser, even signed in as the same person.
        $other = logIn($site);
        $back = comeBack($site, $other);

        expect($back['location'])->toEndWith('/admin/porro')
            ->and(siteHealth($site, $other))->toContain('no request to connect waiting in this browser session');

        // A link for someone else's request, in the session that started one.
        comeBack($site, $jar, ['porro_request' => '0196f3a2-0000-7000-8000-000000000000']);

        expect(siteHealth($site, $jar))->toContain('That approval is for a different request')
            ->and(connectCalls('complete'))->toBeEmpty()
            ->and(connectStore('key')['token'])->toBeNull();

        // The real one still works afterwards.
        expect(comeBack($site, $jar)['location'])->toBe(approvePage());
    });

    it('lands back on Site health with the reason when it can’t connect', function (array $answers, array $query, string $says) {
        porroAnswersConnect($answers);
        $site = site();
        $jar = logIn($site);

        $response = pressConnect($site, $jar);

        if ($response['location'] === approvePage()) {
            $response = comeBack($site, $jar, $query);
        }

        $html = siteHealth($site, $jar);

        expect($response['status'])->toBe(302, $response['body'])
            ->and($response['location'])->toEndWith('/admin/porro')
            ->and($html)->toContain($says)
            // Never what the browser brought.
            ->not->toContain('someone-elses-grant')
            // Still not connected, and free to try again.
            ->toContain('value="porro/connect/start"')
            ->and(connectStore('key'))->toMatchArray(['token' => null, 'row' => ''])
            ->and(reports())->toBeEmpty()
            // Shown once.
            ->and(siteHealth($site, $jar))->not->toContain($says);
    })->with([
        'turned down' => [['complete' => [403, ['ok' => false, 'error' => 'The request was turned down in the browser.']]], [], 'The request was turned down in Porro'],
        'expired or used' => [['complete' => [410, ['ok' => false, 'error' => 'This request has expired or was already used.']]], [], 'This request ran out of time or was already used.'],
        'a wrong grant' => [[], ['porro_grant' => 'someone-elses-grant'], 'accept that approval for this site'],
        'no grant' => [[], ['porro_grant' => ''], 'sent the browser back without an approval'],
        'not approved yet' => [['complete' => [409, ['ok' => false, 'error' => 'This request hasn’t been approved yet.']]], [], 'Nobody has approved this request in Porro yet.'],
        'Porro down' => [['start' => [503, []]], [], 'reach Porro just now. Nothing has changed on this site.'],
        'details Porro won’t take' => [['start' => [422, ['ok' => false, 'error' => 'The return URL must be over https.']]], [], 'accept this site’s details. The return URL must be over https.'],
        'a clock that’s out' => [['start' => [401, ['ok' => false, 'error' => 'Request timestamp outside the allowed window. Check the server clock.']]], [], 'clock is more than five minutes out'],
    ]);

    it('says so when the request ran out of time here', function () {
        $site = site();
        $jar = logIn($site);
        $before = connectStore('waiting');
        pressConnect($site, $jar);

        // Ten minutes on.
        connectStore('expire', ...array_map('strval', array_keys(array_diff_key(connectStore('waiting'), $before))));
        $back = comeBack($site, $jar);

        expect($back['location'])->toEndWith('/admin/porro')
            ->and(siteHealth($site, $jar))->toContain('This request ran out of time. Press Connect to Porro again and approve it within ten minutes.')
            ->and(connectCalls('complete'))->toBeEmpty()
            ->and(connectStore('key')['token'])->toBeNull();
    });

    it('lets the same link try again when Porro can’t be reached to finish', function () {
        porroAnswersConnect(['complete' => [502, []]]);
        $site = site();
        $jar = logIn($site);
        pressConnect($site, $jar);

        comeBack($site, $jar);

        expect(siteHealth($site, $jar))->toContain('reach Porro just now')
            ->and(connectStore('key')['token'])->toBeNull();

        // Porro is back: the key pair made at the start is still waiting.
        porroAnswersConnect([]);

        expect(comeBack($site, $jar)['location'])->toBe(approvePage())
            ->and(connectCalls('complete'))->toHaveCount(2)
            ->and(SiteKey::parse((string) connectStore('key')['token'])->publicKey())->toBe(connectCalls('start')[0]['body']['public_key']);
    });

    it('doesn’t start where it shouldn’t', function (array $env, string $says) {
        $html = withEnvFile(fn ($file) => envWith($file, array_map(fn ($value) => $value === '{key}' ? porro()['key'] : $value, $env)), function () {
            $site = site();
            $jar = logIn($site);
            $pressed = pressConnect($site, $jar);

            expect($pressed['location'])->toEndWith('/admin/porro');

            return siteHealth($site, $jar);
        });

        expect($html)->toContain($says)
            ->and(connectCalls('start'))->toBeEmpty()
            ->and(connectStore('key')['row'])->toBe('');
    })->with([
        'a copy that isn’t the live site' => [['CRAFT_ENVIRONMENT' => 'staging', 'PORRO_ENABLED' => 'true'], 'environment is “staging”, so it doesn’t connect and sends no reports. Connect from the live site’s control panel.'],
        'a copy with devMode on' => [['CRAFT_DEV_MODE' => 'true'], 'This copy has devMode on'],
        'a key in the environment' => [['PORRO_KEY' => '{key}'], 'already has a site key in its environment (PORRO_KEY)'],
        'reporting switched off' => [['PORRO_ENABLED' => 'false'], '(PORRO_ENABLED=false)'],
    ]);

    it('doesn’t finish when a key was put in the environment meanwhile', function () {
        $site = site();
        $jar = logIn($site);
        pressConnect($site, $jar);

        $html = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => porro()['key']]), function () use ($site, $jar) {
            comeBack($site, $jar);

            return siteHealth($site, $jar);
        });

        expect($html)->toContain('(PORRO_KEY)')
            ->and(connectCalls('complete'))->toBeEmpty()
            ->and(connectStore('key')['row'])->toBe('');
    });

    it('asks for the migration rather than start something it can’t keep', function () {
        connectStore('drop-table');
        $site = site();
        $jar = logIn($site);

        $pressed = pressConnect($site, $jar);

        expect($pressed['location'])->toEndWith('/admin/porro')
            ->and(siteHealth($site, $jar))->toContain('Run the migrations (php craft migrate/all), then press Connect to Porro again.')
            ->and(connectCalls('start'))->toBeEmpty();
    });
});

describe('behind a proxy', function () {
    it('explains instead of asking for http when the browser is on https', function (array $headers, array $env) {
        $html = withEnvFile(fn ($file) => envWith($file, $env), function () use ($headers) {
            $site = site();
            $jar = logIn($site);
            pressConnect($site, $jar, $headers);

            return siteHealth($site, $jar);
        });

        expect($html)->toContain('Your browser is on https, but this site sees the request as plain http.')
            ->toContain('the trustedHosts config setting')
            ->and(connectCalls('start'))->toBeEmpty();
    })->with([
        'the browser’s origin' => [['Origin: https://127.0.0.1'], []],
        'the referring page' => [['Referer: https://127.0.0.1/admin/porro'], []],
        'a forwarded header from a proxy Craft doesn’t trust' => [['X-Forwarded-Proto: https'], ['CRAFT_TRUSTED_HOSTS' => '10.9.9.9']],
        'Cloudflare' => [['CF-Visitor: {"scheme":"https"}'], []],
        'the site’s own address alone' => [[], ['PRIMARY_SITE_URL' => 'https://127.0.0.1/']],
    ]);

    it('takes a trusted proxy’s word that the browser is on https', function () {
        $site = site();
        $jar = logIn($site);

        $pressed = pressConnect($site, $jar, ['X-Forwarded-Proto: https']);
        $address = str_replace('http://', 'https://', $site);

        expect($pressed['location'])->toBe(approvePage())
            ->and(connectCalls('start')[0]['body']['address'])->toBe($address)
            ->and(connectCalls('start')[0]['body']['return_url'])->toBe("{$address}/admin/porro/connect");
    });
});

it('keeps the key out of the exception when encrypting fails', function () {
    $thrown = connectStore('seal-fails', 'porro_seed_in_the_clear');

    expect($thrown['class'])->toBe(RuntimeException::class)
        ->and($thrown['previous'])->toBeFalse()
        ->and($thrown['says'])->toContain('Couldn’t encrypt the Porro site key')
        ->not->toContain('porro_seed_in_the_clear');
});
