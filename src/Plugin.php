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
use porro\craft\connect\PanelConnect;
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
 * @property-read SiteKeys $keys
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

    // "Connect to Porro" on Site health. Admins always have it; seeing
    // the page isn't enough.
    public const CONNECT_PERMISSION = 'porro-connectSite';

    // 1.1.0: the blocklist and safelist tables. 1.3.0: porro_settings,
    // for a site key made in the control panel.
    public string $schemaVersion = '1.3.0';

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'activity' => Activity::class,
                'keys' => SiteKeys::class,
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
            // Where the browser comes back from Porro with its approval.
            $event->rules['GET '.PanelConnect::RETURN_PATH] = 'porro/connect/return';
            $event->rules['porro/activity'] = 'porro/activity/index';
            $event->rules['porro/activity/<ip:[0-9a-fA-F:.]+>'] = 'porro/activity/ip';
            $event->rules['porro/blocking'] = 'porro/blocking/index';
            $event->rules['porro/blocking/nginx'] = 'porro/blocking/nginx';
            $event->rules['porro/blocking/cloudflare'] = 'porro/blocking/cloudflare';
            $event->rules['porro/blocking/<list:blocklist|safelist>'] = 'porro/blocking/list';
        });

        // Sign in from Porro: Porro's page posts a one-time ticket to
        // /porro/sign-in on the front-end site. POST only, so a GET there
        // is left to the site's own routes.
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules['POST porro/sign-in'] = 'porro/sign-in/index';
            // Where the site's own page posts the ticket on, with the
            // session cookie the browser held back from Porro's page.
            $event->rules['POST porro/sign-in/continue'] = 'porro/sign-in/continue';
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
                    self::CONNECT_PERMISSION => ['label' => Craft::t('porro', 'Connect the site to Porro')],
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

    /**
     * Whether the signed-in user may connect the site to Porro.
     */
    public static function canConnect(): bool
    {
        $user = Craft::$app->getUser();

        return $user->getIsAdmin() || $user->checkPermission(self::CONNECT_PERMISSION);
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

    /**
     * Where the site key comes from: PORRO_KEY, or the one made by
     * "Connect to Porro" in the control panel.
     */
    public static function keys(): SiteKeys
    {
        /** @var SiteKeys */
        return self::getInstance()?->get('keys') ?? new SiteKeys;
    }

    /**
     * The site key token, wherever it's kept. Read each time it's asked
     * for, and never held on to.
     */
    public static function key(): ?string
    {
        return self::keys()->token();
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

        if ($environment !== null && ! self::isProductionName($environment)) {
            return "not production (CRAFT_ENVIRONMENT={$environment}). Set PORRO_ENABLED=true to report from here";
        }

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return 'devMode is on. Set PORRO_ENABLED=true to report from here';
        }

        return null;
    }

    /**
     * Whether this is the live site rather than a local or staging copy:
     * CRAFT_ENVIRONMENT is production (or unset) and devMode is off.
     * Whatever PORRO_ENABLED says.
     */
    public static function production(): bool
    {
        $environment = self::environment();

        return ($environment === null || self::isProductionName($environment))
            && ! Craft::$app->getConfig()->getGeneral()->devMode;
    }

    public static function isProductionName(string $environment): bool
    {
        return in_array(strtolower($environment), ['production', 'prod', 'live'], true);
    }
}
