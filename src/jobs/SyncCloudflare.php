<?php

namespace porro\craft\jobs;

use craft\queue\BaseJob;
use porro\craft\Plugin;

/**
 * Sends the blocklist to Cloudflare after a change, so whoever blocked
 * doesn't wait on Cloudflare's API.
 */
class SyncCloudflare extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()?->getActivity()->cloudflare()->sync();
    }

    protected function defaultDescription(): ?string
    {
        return 'Syncing the Porro blocklist to Cloudflare';
    }
}
