<?php

namespace porro\craft;

use Craft;
use craft\events\PluginEvent;
use craft\services\Plugins;
use craft\web\Application as WebApplication;
use Porro\Core\Report;
use porro\craft\jobs\SendReport;
use Throwable;
use yii\base\Application;
use yii\base\Event;

/**
 * Reports that still reach Porro when the server's cron line is missing,
 * as a backup to cron rather than a replacement:
 *
 * - "cp": a logged-in control panel request, at most once per 15 minutes
 *   across cron, deploys and every server sharing the cache.
 * - "event": straight away after a plugin or Craft was installed, updated
 *   or removed, whatever the throttle says.
 *
 * Always after the response has gone, never on front-end requests, with
 * a short timeout and every error swallowed.
 */
class BackupReports
{
    private bool $changed = false;

    public function register(): void
    {
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, fn (PluginEvent $event) => $this->pluginChanged($event));
        Event::on(Plugins::class, Plugins::EVENT_AFTER_UNINSTALL_PLUGIN, fn (PluginEvent $event) => $this->pluginChanged($event));

        Craft::$app->on(Application::EVENT_AFTER_REQUEST, function () {
            try {
                $this->afterRequest();
            } catch (Throwable) {
                // Porro must never break the control panel.
            }
        });
    }

    private function pluginChanged(PluginEvent $event): void
    {
        // Connecting sends the first report; uninstalling Porro sends none.
        if ($event->plugin->id !== 'porro') {
            $this->changed = true;
        }
    }

    private function afterRequest(): void
    {
        $trigger = $this->trigger();

        if (! $trigger) {
            return;
        }

        if (! Craft::$app instanceof WebApplication) {
            $this->send($trigger);

            return;
        }

        // Send the page first, then report. Without PHP-FPM there's no way
        // to finish the response early, so the queue sends it instead.
        $response = Craft::$app->getResponse();

        if (function_exists('fastcgi_finish_request') && $response->stream === null && ! headers_sent()) {
            $response->sendAndClose();
            $this->send($trigger);
        } else {
            Craft::$app->getQueue()->push(new SendReport(['trigger' => $trigger]));
        }
    }

    private function trigger(): ?string
    {
        if (! Plugin::enabled() || ! Plugin::key() || ! Craft::$app->getIsInstalled() || ! Craft::$app->getPlugins()->isPluginInstalled('porro')) {
            return null;
        }

        if (Craft::$app instanceof WebApplication) {
            $request = Craft::$app->getRequest();

            if (! $request->getIsCpRequest() || Craft::$app->getUser()->getIsGuest()) {
                return null;
            }
        }

        $reporter = Plugin::getInstance()?->getReporter();

        if (! $reporter) {
            return null;
        }

        if ($this->changed || $reporter->versionsChanged()) {
            return Report::TRIGGER_EVENT;
        }

        return Craft::$app instanceof WebApplication && $reporter->throttle()->claim() ? Report::TRIGGER_CP : null;
    }

    private function send(string $trigger): void
    {
        Plugin::getInstance()?->getReporter()->send(trigger: $trigger, timeout: 3, retries: 0);
    }
}
