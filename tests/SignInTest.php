<?php

use Porro\Core\SiteKey;

/*
 * Sign in from Porro: the stand-in Porro answers the redeem with whatever
 * porroAnswers() set, and keeps what the site sent. Each test signs in
 * from its own IP, so the 10-a-minute limit only bites where it's tested.
 */

const TICKET = 'tkt7Hq2mV9xR4pL8sN3wK6yB1cD5fG0jZa1b2c3d4e5f6g7h';

beforeEach(function () {
    $porro = porro();
    craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);
    cpUser('editor', ['accessCp']);
    cpUser('writer', []);
});

/**
 * What the stand-in Porro answers the redeem with.
 */
function porroAnswers(int $status, array $body): void
{
    file_put_contents(porro()['captures'].'/sign-in-answer', json_encode(['status' => $status, 'body' => $body]));
}

/**
 * Redeem requests the stand-in Porro accepted.
 *
 * @return list<array{body: string, headers: array<string, string>}>
 */
function redeems(): array
{
    $file = porro()['captures'].'/sign-in-requests';
    $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];

    return array_map(fn ($line) => json_decode($line, true), $lines);
}

/**
 * An IP no other test has signed in from.
 */
function freshIp(): string
{
    // 198.18.0.0/15 is for benchmarking, so it's nobody's real visitor.
    return '198.'.random_int(18, 19).'.'.random_int(0, 255).'.'.random_int(1, 254);
}

/**
 * A request through proxiedSite()'s proxy, with the response's headers.
 *
 * @param  array<string, string>|null  $post
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function fetchPage(string $url, string $ip, ?array $post = null, ?string $jar = null, array $send = []): array
{
    $headers = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["X-Forwarded-For: {$ip}", 'Accept: text/html, application/json', ...$send],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'porro-tests',
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        },
    ] + ($jar ? [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar] : [])
      + ($post !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)] : []));
    $body = (string) curl_exec($curl);

    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
}

/**
 * The first step, as Porro's page posts the ticket from another site: the
 * browser holds the site's cookies back, so the jar isn't sent.
 */
function startFromPorro(string $site, string $ip, mixed $ticket = TICKET, ?array $send = null): array
{
    $send ??= ['Sec-Fetch-Site: cross-site', 'Origin: '.porro()['url']];

    return fetchPage("{$site}/porro/sign-in", $ip, $ticket === null ? [] : ['ticket' => $ticket], send: $send);
}

/**
 * The second step, as the site's own page posts the ticket on.
 *
 * @param  list<string>  $send
 */
function continueSignIn(string $site, string $ip, ?string $jar = null, mixed $ticket = TICKET, array $send = ['Sec-Fetch-Site: same-origin']): array
{
    return fetchPage("{$site}/porro/sign-in/continue", $ip, $ticket === null ? [] : ['ticket' => $ticket], $jar, $send);
}

/**
 * Both steps, as a browser takes them. Returns the first step's answer
 * when it doesn't hand the ticket on.
 */
function signInFromPorro(string $site, string $ip, ?string $jar = null, mixed $ticket = TICKET, ?string $porro = null): array
{
    $start = startFromPorro($site, $ip, $ticket, $porro === null ? null : ['Sec-Fetch-Site: cross-site', "Origin: {$porro}"]);

    if ($start['status'] !== 200 || ! preg_match('/name="ticket" value="([^"]+)"/', $start['body'], $m)) {
        return $start;
    }

    return continueSignIn($site, $ip, $jar, html_entity_decode($m[1]));
}

/**
 * Who the cookie jar's session is signed in as, or null.
 */
function signedInAs(string $site, string $ip, string $jar): ?string
{
    $info = json_decode(fetchPage("{$site}/index.php?p=actions/users/session-info", $ip, jar: $jar)['body'], true);

    return ($info['isGuest'] ?? true) ? null : ($info['email'] ?? null);
}

function jar(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'jar');
}

/**
 * The public half of a site key, as Porro stores it.
 */
function publicKey(string $token): string
{
    preg_match('/_([A-Za-z0-9_-]{43})$/', $token, $m);

    return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(base64_decode(strtr($m[1], '-_', '+/'))));
}

it('signs the member in to the control panel and sends them to the dashboard', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(302, $response['body'])
        ->and($response['headers']['location'])->toContain('admin')
        ->and($response['headers']['cache-control'])->toBe('no-store, private')
        ->and($response['headers']['referrer-policy'])->toBe('no-referrer')
        ->and(signedInAs($site, $ip, $jar))->toBe('editor@example.com');

    $dashboard = fetchPage("{$site}/index.php?p=admin/dashboard", $ip, jar: $jar);

    expect($dashboard['status'])->toBe(200)->and($dashboard['body'])->toContain('Dashboard');
});

it('signs in with a key made by Connect to Porro in the control panel', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    // No key in the environment: only the stored one can sign the redeem.
    $response = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => null]), function () use ($site, $ip, $jar) {
        connectedFromPanel();

        try {
            return signInFromPorro($site, $ip, $jar);
        } finally {
            connectStore('forget');
        }
    });

    expect($response['status'])->toBe(302, $response['body'])
        ->and(signedInAs($site, $ip, $jar))->toBe('editor@example.com')
        ->and(redeems())->toHaveCount(1)
        ->and(redeems()[0]['headers']['X-Porro-Site'])->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0');
});

it('starts without a session, and hands the ticket on from this site’s own page', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $site = proxiedSite();

    $start = startFromPorro($site, freshIp());

    expect($start['status'])->toBe(200, $start['body'])
        ->and($start['body'])->toContain('/porro/sign-in/continue"')->toContain('name="ticket" value="'.TICKET.'"')
        // No cookie: a new session would replace whoever is signed in.
        ->and($start['headers'])->not->toHaveKey('set-cookie')
        ->and($start['headers']['cache-control'])->toBe('no-store, private')
        // So the post this page makes names its origin, for browsers
        // that don't send Sec-Fetch-Site.
        ->and($start['headers']['referrer-policy'])->toBe('same-origin')
        ->and($start['headers']['x-frame-options'])->toBe('DENY')
        ->and($start['headers']['content-security-policy'])->toBe("frame-ancestors 'none'")
        // Porro isn't asked until the second step.
        ->and(redeems())->toBe([]);
});

it('only starts for a ticket posted from Porro’s own page', function (?string $origin) {
    // Any other site could post a ticket of its own, to have this site's
    // page sign a visitor in to its account.
    $porro = porro()['url'];
    $origin = $origin === null ? [] : ['Origin: '.str_replace(['{porro}', '{host}'], [$porro, (string) parse_url($porro, PHP_URL_HOST)], $origin)];

    $start = startFromPorro(proxiedSite(), freshIp(), send: $origin);

    expect($start['status'])->toBe(410)
        ->and($start['body'])->not->toContain(TICKET);
})->with([
    'another site' => ['https://evil.example'],
    'a page that hides its origin' => ['null'],
    'no origin at all' => [null],
    'Porro’s host over https' => ['https://{host}'],
    'Porro’s host on another port' => ['http://{host}:9'],
    'a host that starts like Porro’s' => ['{porro}.evil.example'],
]);

it('only signs in from this site’s own page', function (array $send, bool $allowed) {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();
    $port = (string) parse_url($site, PHP_URL_PORT);
    $send = str_replace(['{site}', '{port}'], [$site, $port], $send);

    $response = continueSignIn($site, $ip, $jar, send: $send);

    if ($allowed) {
        expect($response['status'])->toBe(302, $response['body'])->and(signedInAs($site, $ip, $jar))->toBe('editor@example.com');

        return;
    }

    // Turned away before Porro is asked, so the ticket isn't spent.
    expect($response['status'])->toBe(410)
        ->and(redeems())->toBe([])
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
})->with([
    'another site' => [['Sec-Fetch-Site: cross-site'], false],
    'another site, same registrable domain' => [['Sec-Fetch-Site: same-site'], false],
    'no word on where it came from' => [[], false],
    'an older browser, another origin' => [['Origin: https://evil.example'], false],
    'an older browser, this host on another port' => [['Origin: http://127.0.0.1:9'], false],
    'an older browser, this host without its port' => [['Origin: http://127.0.0.1'], false],
    'an older browser, this host over https' => [['Origin: https://127.0.0.1:{port}'], false],
    'an older browser, this origin' => [['Origin: {site}'], true],
]);

it('won’t take a ticket from the address', function () {
    $site = proxiedSite();
    $ip = freshIp();

    $start = fetchPage("{$site}/porro/sign-in?ticket=".TICKET, $ip, ['ticket' => TICKET], send: ['Origin: '.porro()['url']]);
    $continue = fetchPage("{$site}/porro/sign-in/continue?ticket=".TICKET, $ip, ['ticket' => TICKET], send: ['Sec-Fetch-Site: same-origin']);

    expect($start['status'])->toBe(410)
        ->and($start['body'])->not->toContain(TICKET)
        ->and($continue['status'])->toBe(410)
        ->and(redeems())->toBe([]);
});

it('is off on a copy of the site that isn’t live, unless the copy turns it on', function () {
    $site = proxiedSite();
    $staging = fn ($env) => preg_replace('/^CRAFT_ENVIRONMENT=.*$/m', 'CRAFT_ENVIRONMENT=staging', $env);

    $off = withEnvFile($staging, fn () => startFromPorro($site, freshIp()));
    $on = withEnvFile(fn ($env) => $staging($env)."\nPORRO_SIGN_IN=true\n", fn () => startFromPorro($site, freshIp()));

    expect($off['status'])->toBe(403)
        ->and($off['body'])->toContain('works on the live site only')
        ->and($on['status'])->toBe(200)
        ->and(redeems())->toBe([]);
});

it('matches the email whatever its case, and lets admins in', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'Admin@Example.COM', 'name' => 'Admin']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    expect(signInFromPorro($site, $ip, $jar)['status'])->toBe(302)
        ->and(signedInAs($site, $ip, $jar))->toBe('admin@example.com');
});

it('doesn’t elevate the session, so the password is still asked for', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'admin@example.com', 'name' => 'Admin']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    signInFromPorro($site, $ip, $jar);
    $timeout = json_decode(fetchPage("{$site}/index.php?p=actions/users/get-elevated-session-timeout", $ip, jar: $jar)['body'], true);

    expect(signedInAs($site, $ip, $jar))->toBe('admin@example.com')
        ->and($timeout['timeout'] ?? null)->toBe(0);
});

it('redeems the ticket with a request signed by the site key', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $key = porro()['key'];

    signInFromPorro(proxiedSite(), freshIp());

    $redeems = redeems();
    expect($redeems)->toHaveCount(1);

    ['body' => $body, 'headers' => $headers] = $redeems[0];
    $signature = base64_decode($headers['X-Porro-Signature']);
    $message = fn (string $path) => SiteKey::message('POST', $path, $headers['X-Porro-Timestamp'], $headers['X-Porro-Nonce'], $body);

    expect(json_decode($body, true))->toBe(['ticket' => TICKET])
        ->and($headers['Content-Type'])->toStartWith('application/json')
        ->and($headers['X-Porro-Site'])->toBe(SiteKey::parse($key)->siteId)
        ->and(abs((int) $headers['X-Porro-Timestamp'] - time()))->toBeLessThan(5)
        ->and($headers['X-Porro-Nonce'])->not->toBe('')
        ->and(sodium_crypto_sign_verify_detached($signature, $message('/api/v1/sign-in/redeem'), publicKey($key)))->toBeTrue()
        // Bound to this endpoint: it wouldn't pass as a report.
        ->and(sodium_crypto_sign_verify_detached($signature, $message('/api/v1/reports'), publicKey($key)))->toBeFalse();
});

it('says the link has expired when Porro has already used it, and signs no one in', function () {
    porroAnswers(410, ['ok' => false, 'error' => 'That sign-in link has expired.']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(410)
        ->and($response['body'])->toContain('Links from Porro work once and only for 60 seconds. Go back to the site in Porro and choose Sign in again.')
        ->not->toContain(TICKET)
        ->and($response['headers']['cache-control'])->toBe('no-store, private')
        ->and($response['headers']['referrer-policy'])->toBe('no-referrer')
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
});

it('never asks Porro about something that isn’t a ticket', function (mixed $ticket) {
    $response = signInFromPorro(proxiedSite(), freshIp(), ticket: $ticket);

    expect($response['status'])->toBe(410)->and(redeems())->toBe([]);
})->with([
    'missing' => [null],
    'too short' => ['abc'],
    'odd characters' => ['<script>alert(1)</script>xxxxxxxx'],
]);

it('says so when there’s no Craft user with the member’s email', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'someone@agency.test', 'name' => 'Someone']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('No control panel account for you here')->toContain('someone@agency.test')
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
});

it('won’t sign in a user who can’t use the control panel, and changes nothing', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'writer@example.com', 'name' => 'Writer']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('Your account can’t use the control panel')
        ->and(signedInAs($site, $ip, $jar))->toBeNull();

    // Still can't: the attempt gave the account nothing.
    [$status] = http("{$site}/index.php?p=admin/dashboard", logIn($site, 'writer'));
    expect($status)->not->toBe(200);
});

it('won’t sign in a suspended user', function () {
    cpUser('suspended', ['accessCp', '--suspended']);
    porroAnswers(200, ['ok' => true, 'email' => 'suspended@example.com', 'name' => 'Suspended']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('Your account can’t sign in here')
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
});

it('signs no one in when more than one account uses the email', function () {
    cpUser('twin1', ['accessCp', '--email=twin@example.com']);
    cpUser('twin2', ['accessCp', '--email=Twin@Example.com']);
    porroAnswers(200, ['ok' => true, 'email' => 'twin@example.com', 'name' => 'Twin']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('More than one account on this site uses this email, so Porro can’t tell which to sign in. Ask a site admin to fix it.')
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
});

it('signs no one in when the email’s other account couldn’t sign in anyway', function () {
    cpUser('pair1', ['accessCp', '--email=pair@example.com']);
    cpUser('pair2', ['accessCp', '--email=Pair@Example.com', '--suspended']);
    porroAnswers(200, ['ok' => true, 'email' => 'pair@example.com', 'name' => 'Pair']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('More than one account on this site uses this email')
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
});

it('says sign-in is off when Porro has it turned off for the site', function () {
    porroAnswers(403, ['ok' => false, 'error' => 'Sign-in is turned off for this site.']);

    $response = signInFromPorro(proxiedSite(), freshIp());

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('Signing in to client sites is turned off for this site in Porro.');
});

it('is turned off by PORRO_SIGN_IN=false without asking Porro', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $site = proxiedSite(['PORRO_SIGN_IN' => 'false']);
    $ip = freshIp();
    $jar = jar();

    $response = signInFromPorro($site, $ip, $jar);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('This site has turned off signing in from Porro')
        ->and(redeems())->toBe([])
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
});

it('says Porro couldn’t be reached when it can’t be', function () {
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    // Nothing listens on port 9. The site's .env wins over the server's
    // environment, so change it there for this test.
    $response = withEnvFile(
        fn ($env) => preg_replace('/^PORRO_URL=.*$/m', 'PORRO_URL=http://127.0.0.1:9', $env),
        fn () => signInFromPorro($site, $ip, $jar, porro: 'http://127.0.0.1:9'),
    );

    expect($response['status'])->toBe(502)
        ->and($response['body'])->toContain('Porro couldn’t be reached')
        ->not->toContain('cURL')
        ->not->toContain(TICKET)
        ->and(signedInAs($site, $ip, $jar))->toBeNull();
});

it('treats a Porro error or an odd answer as Porro being unreachable', function (int $status, array $body) {
    porroAnswers($status, $body);

    $response = signInFromPorro(proxiedSite(), freshIp());

    expect($response['status'])->toBe(502)->and($response['body'])->toContain('Porro couldn’t be reached');
})->with([
    'server error' => [500, ['message' => 'Server Error']],
    'ok without an email' => [200, ['ok' => true]],
    'not ok' => [200, ['ok' => false, 'email' => 'editor@example.com']],
]);

it('says the site key was refused when Porro rejects the signature', function () {
    porroAnswers(401, ['ok' => false, 'error' => 'Bad signature.']);

    $response = signInFromPorro(proxiedSite(), freshIp());

    expect($response['status'])->toBe(502)->and($response['body'])->toContain('Porro didn’t accept this site’s key');
});

it('says the site isn’t connected when it has no site key', function () {
    $site = proxiedSite();

    $response = withEnvFile(fn ($env) => preg_replace('/^PORRO_KEY=.*$/m', '', $env), fn () => signInFromPorro($site, freshIp()));

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('This site isn’t connected to Porro')
        ->and(redeems())->toBe([]);
});

it('takes the ticket without a CSRF token, though Craft checks them elsewhere', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    // A session first, so Craft has a token it could insist on.
    fetchPage("{$site}/index.php?p=actions/users/session-info", $ip, jar: $jar);
    $login = fetchPage("{$site}/index.php?p=actions/users/login", $ip, ['loginName' => 'editor', 'password' => 'ci-only-password'], $jar);

    expect($login['status'])->toBe(400)
        ->and(signInFromPorro($site, $ip, $jar)['status'])->toBe(302)
        ->and(signedInAs($site, $ip, $jar))->toBe('editor@example.com');
});

it('only answers POSTs at /porro/sign-in', function () {
    $response = fetchPage(proxiedSite().'/porro/sign-in?ticket='.TICKET, freshIp());

    expect($response['status'])->toBe(404)->and(redeems())->toBe([]);
});

it('is limited to 10 tries a minute from one IP', function () {
    porroAnswers(410, ['ok' => false, 'error' => 'expired']);
    $site = proxiedSite();
    $ip = freshIp();

    // Not across a minute boundary, where the count starts again.
    if (time() % 60 > 50) {
        sleep(61 - time() % 60);
    }

    foreach (range(1, 10) as $try) {
        expect(signInFromPorro($site, $ip)['status'])->toBe(410);
    }

    $response = signInFromPorro($site, $ip);

    expect($response['status'])->toBe(429)
        ->and($response['headers']['retry-after'])->toBe('60')
        ->and(redeems())->toHaveCount(10)
        ->and(signInFromPorro($site, freshIp())['status'])->toBe(410);
});

it('just opens the control panel when the member is already signed in as that user', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();

    signInFromPorro($site, $ip, $jar);
    $again = signInFromPorro($site, $ip, $jar);

    expect($again['status'])->toBe(302)
        ->and($again['headers']['location'])->toContain('admin')
        ->and(signedInAs($site, $ip, $jar))->toBe('editor@example.com');
});

/**
 * Signs the jar in as the admin, then posts a ticket for the editor.
 *
 * @return array{0: string, 1: string, 2: string, 3: array{status: int, headers: array<string, string>, body: string}}
 */
function ticketForSomeoneElse(): array
{
    $site = proxiedSite();
    $ip = freshIp();
    $jar = jar();
    porroAnswers(200, ['ok' => true, 'email' => 'admin@example.com', 'name' => 'Admin']);
    signInFromPorro($site, $ip, $jar);
    porroAnswers(200, ['ok' => true, 'email' => 'editor@example.com', 'name' => 'Ed Itor']);

    return [$site, $ip, $jar, signInFromPorro($site, $ip, $jar)];
}

/**
 * The confirmation page's form fields.
 *
 * @return array<string, string>
 */
function switchForm(string $html): array
{
    preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $html, $m);

    return array_combine($m[1], array_map('html_entity_decode', $m[2]));
}

it('asks before switching someone already signed in as another user', function () {
    [$site, $ip, $jar, $response] = ticketForSomeoneElse();

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('You’re signed in as admin@example.com. Switch to editor@example.com?')
        ->and($response['headers']['cache-control'])->toBe('no-store, private')
        // Redeemed once, and nobody swapped yet.
        ->and(redeems())->toHaveCount(2)
        ->and(signedInAs($site, $ip, $jar))->toBe('admin@example.com');
});

it('switches on the confirmation form’s POST, once', function () {
    [$site, $ip, $jar, $response] = ticketForSomeoneElse();
    $form = switchForm($response['body']);
    preg_match('/<form method="post" action="([^"]+)"/', $response['body'], $action);
    $action = html_entity_decode($action[1]);

    $confirm = fetchPage($action, $ip, $form, $jar);

    expect($form)->toHaveKeys(['CRAFT_CSRF_TOKEN', 'switch'])
        ->and($confirm['status'])->toBe(302, $confirm['body'])
        ->and($confirm['headers']['location'])->toContain('admin')
        ->and(signedInAs($site, $ip, $jar))->toBe('editor@example.com');

    // The same form again, with the editor's own CSRF token: used up.
    $token = json_decode(fetchPage("{$site}/index.php?p=actions/users/session-info", $ip, jar: $jar)['body'], true)['csrfTokenValue'];
    $again = fetchPage($action, $ip, ['CRAFT_CSRF_TOKEN' => $token, 'switch' => $form['switch']], $jar);

    expect($again['status'])->toBe(410)->and($again['body'])->toContain('This switch has expired');
});

it('won’t switch without the CSRF token', function () {
    [$site, $ip, $jar, $response] = ticketForSomeoneElse();
    $form = switchForm($response['body']);

    $confirm = fetchPage("{$site}/index.php?p=actions/porro/sign-in/confirm", $ip, ['switch' => $form['switch']], $jar);

    expect($confirm['status'])->toBe(400)
        ->and(signedInAs($site, $ip, $jar))->toBe('admin@example.com');
});

it('won’t switch with a made-up or tampered token', function () {
    [$site, $ip, $jar, $response] = ticketForSomeoneElse();
    $form = switchForm($response['body']);

    $confirm = fetchPage("{$site}/index.php?p=actions/porro/sign-in/confirm", $ip, ['switch' => $form['switch'].'x'] + $form, $jar);

    expect($confirm['status'])->toBe(410)
        ->and(signedInAs($site, $ip, $jar))->toBe('admin@example.com');
});

it('won’t let another browser use the switch', function () {
    [$site, , , $response] = ticketForSomeoneElse();
    $form = switchForm($response['body']);

    // Someone else's browser, signed in with their own CSRF token.
    cpUser('other', ['accessCp']);
    $other = logIn($site, 'other');
    $token = json_decode(http("{$site}/index.php?p=actions/users/session-info", $other)[1], true)['csrfTokenValue'];

    [$status] = http("{$site}/index.php?p=actions/porro/sign-in/confirm", $other, ['CRAFT_CSRF_TOKEN' => $token, 'switch' => $form['switch']]);

    expect($status)->toBe(410)
        ->and(json_decode(http("{$site}/index.php?p=actions/users/session-info", $other)[1], true)['email'])->toBe('other@example.com');
});
