<?php

namespace porro\craft\connect;

use Porro\Core\Client;
use Porro\Core\SiteKey;
use porro\craft\Plugin;
use porro\craft\SiteKeys;
use Throwable;

/**
 * Undoes "Connect to Porro": tells Porro, signed with the site's key, then
 * deletes the key from this site's database. The key goes whatever Porro
 * answered, because the person asked for the site to stop reporting; when
 * Porro didn't answer it shows the site as silent instead of disconnected.
 *
 * Only the stored key can be removed here. PORRO_KEY in the environment is
 * not this code's to change. Porro is told nothing about who asked.
 */
class Disconnect
{
    private SiteKeys $keys;

    public function __construct(?SiteKeys $keys = null)
    {
        $this->keys = $keys ?? Plugin::keys();
    }

    /**
     * Whether there is a key this site can remove by itself.
     */
    public function available(): bool
    {
        return $this->keys->source() === SiteKeys::STORED;
    }

    /**
     * @return array{workspace: string|null, host: string|null, told: bool}|null null when there was nothing to disconnect
     */
    public function disconnect(): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $stored = $this->keys->stored();
        $told = false;

        try {
            // Sent as reports are. One more go if Porro is slow to answer.
            $result = (new Client(SiteKey::parse((string) $this->keys->token()), Plugin::url(), retries: 1))->disconnect();

            // A key Porro already turns away is as disconnected as it gets.
            $told = $result->ok || $result->keyTurnedAway();
        } catch (Throwable) {
            // A key that can't be read can still be deleted.
        }

        $this->keys->forget();

        try {
            $plugin = Plugin::getInstance();
            $plugin?->getReporter()->forgetSent();
            // So Site health checks again, as a site that isn't connected.
            $plugin?->getSiteHealth()->forgetPanel();
        } catch (Throwable) {
            // The key is gone; a cache that can't be cleared runs out by itself.
        }

        return [
            'workspace' => $stored['workspace'] ?? null,
            'host' => $stored['host'] ?? null,
            'told' => $told,
        ];
    }
}
