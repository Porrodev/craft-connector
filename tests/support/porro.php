<?php

/*
 * A stand-in for Porro, run with `php -S`. It checks each report's
 * signature against the public key in PORRO_PUBLIC_KEY (hex), saves the
 * body to PORRO_CAPTURE_DIR and replies like Porro does. If the file
 * "need-lock" exists in the capture dir, it asks for the package list
 * when a report arrives without it.
 */

$dir = getenv('PORRO_CAPTURE_DIR');
$public = hex2bin((string) getenv('PORRO_PUBLIC_KEY'));
$body = (string) file_get_contents('php://input');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_URI'] !== '/api/v1/reports' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit('{"ok":false,"error":"Not found."}');
}

$message = ($_SERVER['HTTP_X_PORRO_TIMESTAMP'] ?? '')."\n".($_SERVER['HTTP_X_PORRO_NONCE'] ?? '')."\n".hash('sha256', $body);
$signature = base64_decode($_SERVER['HTTP_X_PORRO_SIGNATURE'] ?? '', true);

if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signature, $message, $public)) {
    http_response_code(401);
    exit('{"ok":false,"error":"Invalid signature."}');
}

file_put_contents(sprintf('%s/%s-%s.json', $dir, microtime(true), bin2hex(random_bytes(3))), $body);

$report = json_decode($body, true);
$needLock = is_file("{$dir}/need-lock") && ! is_array($report['lock']['packages'] ?? null);

http_response_code(202);
echo json_encode(['ok' => true, 'need_lock' => $needLock]);
