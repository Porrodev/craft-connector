<?php

namespace porro\craft;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\App;
use craft\services\Dashboard;
use craft\web\UrlManager;
use porro\craft\services\SiteHealth;
use porro\craft\widgets\SiteHealthWidget;
use yii\base\Event;

/**
 * Porro for Craft CMS. A Site health page and dashboard widget that
 * check the site without a Porro account; once connected, it also sends
 * signed reports about the site to Porro (from cron, deploys and, as a
 * backup, the control panel) and never accepts commands.
 *
 * @property-read Reporter $reporter
 * @property-read SiteHealth $siteHealth
 */
class Plugin extends BasePlugin
{
    public const DEFAULT_URL = 'https://app.porro.dev';

    // Craft's own "Access Porro" permission for the plugin's section.
    // Admins always have it.
    public const PERMISSION = 'accessPlugin-porro';

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'reporter' => Reporter::class,
                'siteHealth' => SiteHealth::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'porro\\craft\\console\\controllers';
        }

        if (self::enabled()) {
            (new BackupReports)->register();
        }

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules['porro'] = 'porro/site-health/index';
        });

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = SiteHealthWidget::class;
        });
    }

    /**
     * Control panel nav: "Site health", for users who can see it.
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('porro', 'Site health');

        return $item;
    }

    public function getSiteHealth(): SiteHealth
    {
        /** @var SiteHealth */
        return $this->get('siteHealth');
    }

    public function getReporter(): Reporter
    {
        /** @var Reporter */
        return $this->get('reporter');
    }

    public static function key(): ?string
    {
        $key = App::env('PORRO_KEY');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function url(): string
    {
        $url = App::env('PORRO_URL');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : self::DEFAULT_URL;
    }

    public static function enabled(): bool
    {
        // App::env() already turns "false", "0" and similar into booleans.
        $enabled = App::env('PORRO_ENABLED');

        if ($enabled === null || is_bool($enabled)) {
            return $enabled ?? true;
        }

        return ! in_array(strtolower((string) $enabled), ['0', 'false', 'off', 'no'], true);
    }
}
