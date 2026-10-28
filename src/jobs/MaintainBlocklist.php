<?php

namespace porro\craft\jobs;

use craft\queue\BaseJob;
use porro\craft\Plugin;

/**
 * Blocking's daily housekeeping for sites without Porro's cron line:
 * protected ranges, expired entries and the country file. Queued, so the
 * Activity and Blocking pages never wait on the downloads.
 */
class MaintainBlocklist extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()?->getActivity()->maintain();
    }

    protected function defaultDescription(): ?string
    {
        return 'Updating Porro’s protected ranges and blocklist';
    }
}
