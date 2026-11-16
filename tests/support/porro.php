<?php

/*
 * A stand-in for Porro, run with `php -S`. It checks each request's
 * signature against the public key in PORRO_PUBLIC_KEY (hex), saves the
 * body to PORRO_CAPTURE_DIR and replies like Porro does. Signed reads of
 * /api/v1/findings get two canned findings. If the file
 * "need-lock" exists in the capture dir, it asks for the package list
 * when a report arrives without it. A sign-in redeem gets the answer in
 * "sign-in-answer" ({"status": …, "body": …}) and is saved, with its
 * headers, to "sign-in-requests" (one JSON line each).
 *
 * "Connect to Porro": a start is saved to "connect-starts" and a finish to
 * "connect-completes" (one JSON line each), and both get the answer in
 * "connect-answers" ({"start": [status, body], "complete": […]}) when the
 * test set one. Both must be signed by the public key the start sent,
 * which is kept in "connect-public-key"; from then on that key signs the
 * site's reports, findings and sign-ins as well.
 */

const CONNECT_ID = '0196f3a2-7c41-7e0b-9d55-2f6a8c1e4b70';

$dir = getenv('PORRO_CAPTURE_DIR');
$public = hex2bin((string) getenv('PORRO_PUBLIC_KEY'));
$body = (string) file_get_contents('php://input');

header('Content-Type: application/json');

$route = $_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'];

if (! in_array($route, ['POST /api/v1/reports', 'GET /api/v1/findings', 'POST /api/v1/sign-in/redeem', 'POST /api/v1/connect', 'POST /api/v1/connect/'.CONNECT_ID.'/complete'], true)) {
    http_response_code(404);
    exit('{"ok":false,"error":"Not found."}');
}

// A start brings the public key its signature is checked with.
if ($route === 'POST /api/v1/connect') {
    file_put_contents("{$dir}/connect-public-key", (string) (json_decode($body, true)['public_key'] ?? ''));
}

$connected = base64_decode((string) @file_get_contents("{$dir}/connect-public-key"), true);
$connecting = str_starts_with($route, 'POST /api/v1/connect');

// Signed for the method and path, as Porro checks it: by the key the
// tests gave the site, or the one the site made when it connected.
$message = "connector-v1\n".$_SERVER['REQUEST_METHOD']."\n".$_SERVER['REQUEST_URI']."\n"
    .($_SERVER['HTTP_X_PORRO_TIMESTAMP'] ?? '')."\n".($_SERVER['HTTP_X_PORRO_NONCE'] ?? '')."\n".hash('sha256', $body);
$signature = base64_decode($_SERVER['HTTP_X_PORRO_SIGNATURE'] ?? '', true);
$signedBy = fn (string|false $key) => is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    && sodium_crypto_sign_verify_detached($signature, $message, $key);

if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! ($signedBy($connected) || (! $connecting && $signedBy($public)))) {
    http_response_code(401);
    exit('{"ok":false,"error":"Invalid signature."}');
}

if ($connecting) {
    $step = $route === 'POST /api/v1/connect' ? 'start' : 'complete';
    file_put_contents("{$dir}/connect-{$step}s", json_encode(['body' => json_decode($body, true), 'site' => $_SERVER['HTTP_X_PORRO_SITE'] ?? null])."\n", FILE_APPEND);
    $answers = json_decode((string) @file_get_contents("{$dir}/connect-answers"), true) ?: [];

    // The key has no site until the connection is finished.
    if (isset($_SERVER['HTTP_X_PORRO_SITE'])) {
        http_response_code(401);
        exit('{"ok":false,"error":"Invalid signature."}');
    }

    if ($step === 'complete' && (json_decode($body, true)['grant'] ?? null) !== 'the-grant') {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'That grant doesn’t match this request.']));
    }

    [$status, $answer] = $answers[$step] ?? ($step === 'start'
        ? [201, ['ok' => true, 'id' => CONNECT_ID, 'approve_url' => 'http://'.$_SERVER['HTTP_HOST'].'/connect/'.CONNECT_ID, 'expires_at' => gmdate('c', time() + 600)]]
        : [200, ['ok' => true, 'site' => '01jabcdefghjkmnpqrstvwxyz0', 'host' => 'harbour-arts.org', 'workspace' => 'Marlowe Studio']]);
    http_response_code($status);
    exit(json_encode($answer));
}

// The Site health panel reading the site's findings back.
if ($route === 'GET /api/v1/findings') {
    exit(json_encode(['ok' => true, 'site' => ['url' => 'https://porro.test/acme/sites/craft'], 'findings' => [
        ['kind' => 'ssl_expiring', 'rank' => 3, 'title' => 'SSL certificate expires in 9 days', 'detail' => 'Let’s Encrypt R11', 'scope' => 'site', 'url' => 'https://porro.test/acme/sites/craft'],
        ['kind' => 'os_unsupported', 'rank' => 2, 'title' => 'Ubuntu 20.04 past security support', 'scope' => 'server', 'url' => 'https://porro.test/acme/servers/web-03'],
    ]]));
}

// Sign-in from Porro: what the test set up, and what the site sent.
if ($route === 'POST /api/v1/sign-in/redeem') {
    file_put_contents("{$dir}/sign-in-requests", json_encode(['body' => $body, 'headers' => [
        'X-Porro-Site' => $_SERVER['HTTP_X_PORRO_SITE'] ?? '',
        'X-Porro-Timestamp' => $_SERVER['HTTP_X_PORRO_TIMESTAMP'] ?? '',
        'X-Porro-Nonce' => $_SERVER['HTTP_X_PORRO_NONCE'] ?? '',
        'X-Porro-Signature' => $_SERVER['HTTP_X_PORRO_SIGNATURE'] ?? '',
        'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? '',
    ]])."\n", FILE_APPEND);
    $answer = json_decode((string) @file_get_contents("{$dir}/sign-in-answer"), true) ?: ['status' => 410, 'body' => ['ok' => false, 'error' => 'That sign-in link has expired.']];
    http_response_code($answer['status']);
    exit(json_encode($answer['body']));
}

file_put_contents(sprintf('%s/%s-%s.json', $dir, microtime(true), bin2hex(random_bytes(3))), $body);
// Which site the report said it was from, for the tests that connect.
file_put_contents("{$dir}/report-sites", ($_SERVER['HTTP_X_PORRO_SITE'] ?? '')."\n", FILE_APPEND);

$report = json_decode($body, true);
$needLock = is_file("{$dir}/need-lock") && ! is_array($report['lock']['packages'] ?? null);

http_response_code(202);
echo json_encode(['ok' => true, 'need_lock' => $needLock]);
