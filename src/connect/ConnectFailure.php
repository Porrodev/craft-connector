<?php

namespace porro\craft\connect;

use Porro\Core\ConnectError;
use RuntimeException;

/**
 * Why "Connect to Porro" didn't finish, worded for the person who pressed
 * it. Never holds the grant, the seed or the key.
 */
class ConnectFailure extends RuntimeException
{
    /** PORRO_KEY is set in the environment, so there's nothing to connect. */
    public const ENV_KEY = 'env_key';

    /** Not the live site. */
    public const NOT_PRODUCTION = 'not_production';

    /** PORRO_ENABLED=false. */
    public const OFF = 'off';

    /** The site is on https but Craft sees http: an untrusted proxy. */
    public const SCHEME = 'scheme';

    /** The porro_settings table isn't there. */
    public const NOT_MIGRATED = 'not_migrated';

    /** No request waiting in this session: it ran out, or never started here. */
    public const EXPIRED = 'expired';

    /** The browser came back with a request this session didn't start. */
    public const MISMATCH = 'mismatch';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function from(ConnectError $error): self
    {
        $message = $error->getMessage();

        // What Porro said about the site's details is the useful part.
        if ($error->reason === ConnectError::INVALID && $error->detail !== null) {
            $message .= ' '.$error->detail;
        }

        return new self($error->reason, $message);
    }
}
