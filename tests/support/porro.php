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
 */

$dir = getenv('PORRO_CAPTURE_DIR');
$public = hex2bin((string) getenv('PORRO_PUBLIC_KEY'));
$body = (string) file_get_contents('php://input');

header('Content-Type: application/json');

$route = $_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'];

if (! in_array($route, ['POST /api/v1/reports', 'GET /api/v1/findings', 'POST /api/v1/sign-in/redeem'], true)) {
    http_response_code(404);
    exit('{"ok":false,"error":"Not found."}');
}

// Signed for the method and path, as Porro checks it.
$message = "connector-v1\n".$_SERVER['REQUEST_METHOD']."\n".$_SERVER['REQUEST_URI']."\n"
    .($_SERVER['HTTP_X_PORRO_TIMESTAMP'] ?? '')."\n".($_SERVER['HTTP_X_PORRO_NONCE'] ?? '')."\n".hash('sha256', $body);
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

$report = json_decode($body, true);
$needLock = is_file("{$dir}/need-lock") && ! is_array($report['lock']['packages'] ?? null);

http_response_code(202);
echo json_encode(['ok' => true, 'need_lock' => $needLock]);
