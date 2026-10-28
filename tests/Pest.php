<?php

use Porro\Core\SiteKey;

/*
 * These tests drive a real Craft install with this plugin installed:
 * set CRAFT_PATH to its folder. CI builds one for Craft 4 and Craft 5.
 */

function craftPath(): string
{
    return (string) getenv('CRAFT_PATH');
}

/**
 * Runs `php craft …` in the Craft install.
 *
 * @param  array<int, string>  $args
 * @return array{0: int, 1: string}
 */
function craft(array $args, array $env = []): array
{
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, 'craft', ...$args])).' 2>&1';
    $process = proc_open($command, [1 => ['pipe', 'w']], $pipes, craftPath(), [...getenv(), ...$env]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/**
 * Starts the stand-in Porro once and returns its URL and a fresh site key.
 *
 * @return array{url: string, key: string, captures: string}
 */
function porro(): array
{
    static $porro = null;

    if ($porro !== null) {
        return $porro;
    }

    $captures = sys_get_temp_dir().'/porro-craft-'.bin2hex(random_bytes(4));
    mkdir($captures);
    $seed = random_bytes(32);
    $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
    $port = 18000 + random_int(0, 999);

    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/support/porro.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        [...getenv(), 'PORRO_CAPTURE_DIR' => $captures, 'PORRO_PUBLIC_KEY' => bin2hex($public)],
    );
    register_shutdown_function(fn () => proc_terminate($server));

    // Wait for it to listen; refused connections until then are expected.
    set_error_handler(fn () => true);
    for ($i = 0; $i < 50 && ! fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    restore_error_handler();

    return $porro = [
        'url' => "http://127.0.0.1:{$port}",
        'key' => 'porro_01jabcdefghjkmnpqrstvwxyz0_'.rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
        'captures' => $captures,
    ];
}

/**
 * Reports the stand-in Porro has accepted, oldest first.
 *
 * @return array<int, array<string, mixed>>
 */
function reports(): array
{
    $files = glob(porro()['captures'].'/*.json') ?: [];
    sort($files);

    return array_map(fn ($file) => json_decode((string) file_get_contents($file), true), $files);
}

function clearReports(): void
{
    array_map('unlink', glob(porro()['captures'].'/*') ?: []);
}

uses()->beforeEach(function () {
    if (craftPath() === '' || ! is_file(craftPath().'/craft')) {
        $this->markTestSkipped('Set CRAFT_PATH to a Craft install with this plugin installed.');
    }

    clearReports();
    // Tests in Unit/ need no Craft install.
})->in(...glob(__DIR__.'/*Test.php'));

/**
 * Serves the Craft install over HTTP. With $fpm it can finish responses
 * early like PHP-FPM; without, backup reports go through the queue.
 *
 * @param  array<string, string>  $env
 */
function site(bool $fpm = true, array $env = []): string
{
    static $sites = [];
    $id = md5(serialize([$fpm, $env]));

    if (isset($sites[$id])) {
        return $sites[$id];
    }

    $port = 19000 + random_int(0, 999);
    $router = $fpm ? __DIR__.'/support/fastcgi.php' : craftPath().'/web/index.php';
    $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', craftPath().'/web', $router];
    $server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, craftPath(), [...getenv(), ...$env]);
    register_shutdown_function(fn () => proc_terminate($server));

    set_error_handler(fn () => true);
    for ($i = 0; $i < 50 && ! fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    restore_error_handler();

    return $sites[$id] = "http://127.0.0.1:{$port}";
}

/**
 * A GET (or POST) with a cookie jar, so a login sticks.
 *
 * @param  array<string, string>|null  $post
 * @param  list<string>  $headers  e.g. "X-Forwarded-For: 45.83.64.9" to send as a visitor
 * @return array{0: int, 1: string}
 */
function http(string $url, ?string $jar = null, ?array $post = null, array $headers = []): array
{
    $curl = curl_init($url);
    // + rather than spread: spreading renumbers the integer option keys.
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', ...$headers],
        CURLOPT_TIMEOUT => 30,
        // Craft won't start a session without one.
        CURLOPT_USERAGENT => 'porro-tests',
    ] + ($jar ? [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar] : [])
      + ($post !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)] : []));
    $body = (string) curl_exec($curl);

    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
}

/**
 * Logs the CI admin (or another user) in to the control panel and returns
 * the cookie jar.
 */
function logIn(string $site, string $username = 'admin'): string
{
    $jar = (string) tempnam(sys_get_temp_dir(), 'jar');
    [, $info] = http("{$site}/index.php?p=actions/users/session-info", $jar);
    $info = json_decode($info, true);

    [$status, $body] = http("{$site}/index.php?p=actions/users/login", $jar, [
        $info['csrfTokenName'] => $info['csrfTokenValue'],
        'loginName' => $username,
        'password' => 'ci-only-password',
    ]);

    expect($status)->toBe(200, $body);

    return $jar;
}

/**
 * Reports that arrive within a few seconds; backup reports go after the
 * response, so they can land just after the request returns.
 *
 * @return array<int, array<string, mixed>>
 */
function reportsAfterwards(int $expected): array
{
    for ($i = 0; $i < 40 && count(reports()) < $expected; $i++) {
        usleep(100_000);
    }

    // Long enough for an unexpected extra report to show up.
    usleep(500_000);

    return reports();
}

function cache(string $action, string $key, ?string $value = null): void
{
    $command = implode(' ', array_map('escapeshellarg', array_filter([PHP_BINARY, __DIR__.'/support/cache.php', $action, $key, $value], fn ($arg) => $arg !== null)));
    exec($command.' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
}

/**
 * A control panel user who isn't an admin, with only these permissions.
 */
function cpUser(string $username, array $permissions): void
{
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__.'/support/user.php', $username, ...$permissions]));
    exec($command.' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
}

/**
 * Activity and blocking in the Craft install: reset, block or safelist.
 */
function blocking(string $action, string ...$args): void
{
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__.'/support/blocking.php', $action, ...$args]));
    exec($command.' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
}

/**
 * The site behind a proxy at 127.0.0.1 that Craft trusts, so asVisitor()
 * can say who's visiting. Unconnected, so no reports are sent.
 *
 * @param  array<string, string>  $env
 */
function proxiedSite(array $env = []): string
{
    return site(env: ['PORRO_ENABLED' => 'false', 'CRAFT_TRUSTED_HOSTS' => '127.0.0.1', ...$env]);
}

/**
 * A request from a visitor at $ip, through proxiedSite()'s proxy.
 *
 * @param  array<string, string>|null  $post
 * @return array{0: int, 1: string}
 */
function asVisitor(string $url, string $ip, ?array $post = null, ?string $jar = null): array
{
    return http($url, $jar, $post, ["X-Forwarded-For: {$ip}"]);
}

/**
 * The CSRF token on a control panel page.
 */
function csrf(string $html): string
{
    preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"|"csrfTokenValue":"([^"]+)"/', $html, $m);

    return ($m[1] ?? '') ?: ($m[2] ?? '');
}

/**
 * The cache key php-core keeps Porro's findings under for this Porro and key.
 */
function findingsKey(string $url, string $key): string
{
    $siteKey = SiteKey::parse($key);

    return 'porro:health:porro:'.$siteKey->siteId.':'.substr(hash('sha256', rtrim($url, '/')."\n".$siteKey->id()), 0, 16);
}
