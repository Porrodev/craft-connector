<?php

namespace porro\craft;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\helpers\App;

/**
 * Porro connector for Craft CMS. Read-only: it sends one signed report
 * about the site to Porro on a schedule and never accepts commands.
 *
 * @property-read Reporter $reporter
 */
class Plugin extends BasePlugin
{
    public const DEFAULT_URL = 'https://app.porro.dev';

    public static function config(): array
    {
        return [
            'components' => [
                'reporter' => Reporter::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'porro\\craft\\console\\controllers';
        }
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
