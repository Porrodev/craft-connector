<?php

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
})->in(__DIR__);
