<?php

namespace porro\craft;

use Craft;
use craft\helpers\App;
use Porro\Core\SiteKey;
use Throwable;

/**
 * Redeems a sign-in ticket with Porro, server to server, signed with the
 * site key like the connector's reports. Porro only answers with the
 * member's email when the ticket is new, under 60 seconds old and was made
 * for this site, so the ticket alone is worth nothing to anyone else.
 */
class SignIn
{
    public const PATH = '/api/v1/sign-in/redeem';

    public const OK = 'ok';

    public const EXPIRED = 'expired';

    public const TURNED_OFF = 'turned_off';

    public const KEY_REFUSED = 'key_refused';

    public const UNREACHABLE = 'unreachable';

    /**
     * Whether the site owner allows it. On unless PORRO_SIGN_IN says no.
     */
    public static function enabled(): bool
    {
        // App::env() already turns "false", "0" and similar into booleans.
        $value = App::env('PORRO_SIGN_IN');

        if ($value === null || $value === '') {
            return true;
        }

        return is_bool($value) ? $value : ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * @return array{status: string, email: string|null, name: string|null}
     */
    public function redeem(SiteKey $key, string $baseUrl, string $ticket): array
    {
        $body = (string) json_encode(['ticket' => $ticket]);

        try {
            // Craft's Guzzle client, so the site's proxy settings apply. No
            // retries: a ticket works once, and Porro may have used it up
            // even when the answer never arrived. No redirects either, so
            // the ticket only ever goes to the Porro the site is set up with.
            $response = Craft::createGuzzleClient()->request('POST', rtrim($baseUrl, '/').self::PATH, [
                'headers' => $key->headers($body, null, 'POST', self::PATH) + [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'porro-craft-connector',
                ],
                'body' => $body,
                'connect_timeout' => 5,
                'timeout' => 10,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (Throwable) {
            // The exception's message isn't shown or logged: it can carry
            // the request.
            return self::result(self::UNREACHABLE);
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $json = json_decode((string) $response->getBody(), true);
            $email = is_array($json) && ($json['ok'] ?? false) === true && is_string($json['email'] ?? null) ? trim($json['email']) : '';

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return self::result(self::UNREACHABLE);
            }

            return self::result(self::OK, $email, is_string($json['name'] ?? null) ? $json['name'] : null);
        }

        return self::result(match ($status) {
            410 => self::EXPIRED,
            403 => self::TURNED_OFF,
            401 => self::KEY_REFUSED,
            default => self::UNREACHABLE,
        });
    }

    /**
     * @return array{status: string, email: string|null, name: string|null}
     */
    private static function result(string $status, ?string $email = null, ?string $name = null): array
    {
        return ['status' => $status, 'email' => $email, 'name' => $name];
    }
}
