<?php

namespace porro\craft;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\App;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use porro\craft\activity\Activity;
use porro\craft\activity\WatchRequests;
use porro\craft\services\SiteHealth;
use porro\craft\variables\PorroVariable;
use porro\craft\widgets\SiteHealthWidget;
use yii\base\Event;

/**
 * Porro for Craft CMS. Site health, suspicious activity and blocking in
 * the control panel, without a Porro account; once connected, it also
 * sends signed reports about the site to Porro (from cron, deploys and, as
 * a backup, the control panel) and never accepts commands.
 *
 * @property-read Activity $activity
 * @property-read Reporter $reporter
 * @property-read SiteHealth $siteHealth
 */
class Plugin extends BasePlugin
{
    public const DEFAULT_URL = 'https://app.porro.dev';

    // Craft's own "Access Porro" permission for the plugin's section.
    // Admins always have it.
    public const PERMISSION = 'accessPlugin-porro';

    // Blocking, unblocking and safelisting. Admins always have it.
    public const MANAGE_PERMISSION = 'porro-manageBlocking';

    // 1.1.0: the blocklist and safelist tables.
    public string $schemaVersion = '1.2.0';

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'activity' => Activity::class,
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

        (new WatchRequests)->register();

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules['porro'] = 'porro/site-health/index';
            $event->rules['porro/activity'] = 'porro/activity/index';
            $event->rules['porro/activity/<ip:[0-9a-fA-F:.]+>'] = 'porro/activity/ip';
            $event->rules['porro/blocking'] = 'porro/blocking/index';
            $event->rules['porro/blocking/nginx'] = 'porro/blocking/nginx';
            $event->rules['porro/blocking/cloudflare'] = 'porro/blocking/cloudflare';
            $event->rules['porro/blocking/<list:blocklist|safelist>'] = 'porro/blocking/list';
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('porro', PorroVariable::class);
        });

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('porro', 'Porro'),
                'permissions' => [
                    self::MANAGE_PERMISSION => ['label' => Craft::t('porro', 'Block, unblock and safelist IPs')],
                ],
            ];
        });

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = SiteHealthWidget::class;
        });
    }

    /**
     * Control panel nav: "Porro", with the Porro mark and its three pages.
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('porro', 'Porro');
        $item['subnav'] = [
            'health' => ['label' => Craft::t('porro', 'Site health'), 'url' => 'porro'],
            'activity' => ['label' => Craft::t('porro', 'Activity'), 'url' => 'porro/activity'],
            'blocking' => ['label' => Craft::t('porro', 'Blocking'), 'url' => 'porro/blocking'],
        ];

        return $item;
    }

    public function getActivity(): Activity
    {
        /** @var Activity */
        return $this->get('activity');
    }

    /**
     * Whether the signed-in user may block, unblock and safelist.
     */
    public static function canManage(): bool
    {
        $user = Craft::$app->getUser();

        return $user->getIsAdmin() || $user->checkPermission(self::MANAGE_PERMISSION);
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

    /**
     * The environment the site is set up as. Unlike Craft::$app->env, never
     * the request's host name, which Craft falls back to when it's unset.
     */
    public static function environment(): ?string
    {
        foreach (['CRAFT_ENVIRONMENT', 'ENVIRONMENT'] as $name) {
            $value = App::env($name);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function enabled(): bool
    {
        return self::offReason() === null;
    }

    /**
     * Why reporting is off, or null when it's on.
     *
     * PORRO_ENABLED wins when it's set. Left unset, only production reports,
     * so a local or staging copy with the live .env never poses as the live
     * site: CRAFT_ENVIRONMENT must be production (or unset) and devMode off.
     */
    public static function offReason(): ?string
    {
        // App::env() already turns "false", "0" and similar into booleans.
        $enabled = App::env('PORRO_ENABLED');

        if ($enabled !== null && $enabled !== '') {
            $on = is_bool($enabled) ? $enabled : ! in_array(strtolower((string) $enabled), ['0', 'false', 'off', 'no'], true);

            return $on ? null : 'PORRO_ENABLED=false';
        }

        $environment = self::environment();

        if ($environment !== null && ! in_array(strtolower($environment), ['production', 'prod', 'live'], true)) {
            return "not production (CRAFT_ENVIRONMENT={$environment}). Set PORRO_ENABLED=true to report from here";
        }

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return 'devMode is on. Set PORRO_ENABLED=true to report from here';
        }

        return null;
    }
}
