<?php

/*
 * A stand-in for Porro, run with `php -S`. It checks each report's
 * signature against the public key in PORRO_PUBLIC_KEY (hex), saves the
 * body to PORRO_CAPTURE_DIR and replies like Porro does. Signed reads of
 * /api/v1/findings get two canned findings. If the file
 * "need-lock" exists in the capture dir, it asks for the package list
 * when a report arrives without it.
 */

$dir = getenv('PORRO_CAPTURE_DIR');
$public = hex2bin((string) getenv('PORRO_PUBLIC_KEY'));
$body = (string) file_get_contents('php://input');

header('Content-Type: application/json');

$route = $_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'];

if (! in_array($route, ['POST /api/v1/reports', 'GET /api/v1/findings'], true)) {
    http_response_code(404);
    exit('{"ok":false,"error":"Not found."}');
}

$message = ($_SERVER['HTTP_X_PORRO_TIMESTAMP'] ?? '')."\n".($_SERVER['HTTP_X_PORRO_NONCE'] ?? '')."\n".hash('sha256', $body);
$signature = base64_decode($_SERVER['HTTP_X_PORRO_SIGNATURE'] ?? '', true);

if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signature, $message, $public)) {
    http_response_code(401);
    exit('{"ok":false,"error":"Invalid signature."}');
}

// The Site health panel reading the site's findings back.
if ($route === 'GET /api/v1/findings') {
    exit(json_encode(['ok' => true, 'site' => ['url' => 'https://porro.test/acme/sites/craft'], 'findings' => [
        ['kind' => 'ssl_expiring', 'rank' => 3, 'title' => 'SSL certificate expires in 9 days', 'detail' => 'Let’s Encrypt R11', 'scope' => 'site', 'url' => 'https://porro.test/acme/sites/craft'],
        ['kind' => 'os_unsupported', 'rank' => 2, 'title' => 'Ubuntu 20.04 past security support', 'scope' => 'server', 'url' => 'https://porro.test/acme/servers/web-03'],
    ]]));
}

file_put_contents(sprintf('%s/%s-%s.json', $dir, microtime(true), bin2hex(random_bytes(3))), $body);

$report = json_decode($body, true);
$needLock = is_file("{$dir}/need-lock") && ! is_array($report['lock']['packages'] ?? null);

http_response_code(202);
echo json_encode(['ok' => true, 'need_lock' => $needLock]);
