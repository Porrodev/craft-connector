<?php

namespace porro\craft\jobs;

use craft\queue\BaseJob;
use porro\craft\Plugin;
use Throwable;

/**
 * Sends a backup report from the queue, where the server can't finish the
 * response first. Never fails: a failed job would show up in Porro.
 */
class SendReport extends BaseJob
{
    public string $trigger = 'cp';

    public function execute($queue): void
    {
        if (! Plugin::enabled() || ! Plugin::key()) {
            return;
        }

        try {
            Plugin::getInstance()?->getReporter()->send(trigger: $this->trigger, timeout: 3, retries: 0);
        } catch (Throwable) {
            // Porro must never break the site.
        }
    }

    public function getTtr(): int
    {
        return 30;
    }

    protected function defaultDescription(): ?string
    {
        return 'Sending a report to Porro';
    }
}
