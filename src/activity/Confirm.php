<?php

namespace porro\craft\activity;

use Craft;

/**
 * What the block and safelist buttons ask before they act: a title, what
 * will happen to whom and for how long, and a button named for the action,
 * so a misclick costs a "Cancel". The same questions as the Laravel,
 * Statamic and Filament connectors ask.
 */
final class Confirm
{
    /**
     * @return array{title: string, body: string, button: string, danger: bool}
     */
    public static function block(string $target, int $days = 0): array
    {
        $range = str_contains($target, '/');

        $who = $range
            ? Craft::t('porro', 'Every request from the {count} addresses in {range} gets a 403, including any real visitors in it.', ['count' => self::size($target), 'range' => $target])
            : Craft::t('porro', 'Every request from it gets a 403.');

        return $days > 0 ? [
            'title' => Craft::t('porro', 'Block {target} for {days} days?', ['target' => $target, 'days' => $days]),
            'body' => $who.' '.Craft::t('porro', 'It’s lifted automatically after that.'),
            'button' => $range ? Craft::t('porro', 'Block range for {days} days', ['days' => $days]) : Craft::t('porro', 'Block for {days} days', ['days' => $days]),
            'danger' => true,
        ] : [
            'title' => Craft::t('porro', 'Block {target} permanently?', ['target' => $target]),
            'body' => $who.' '.Craft::t('porro', 'It stays blocked until someone removes it.'),
            'button' => $range ? Craft::t('porro', 'Block range permanently') : Craft::t('porro', 'Block permanently'),
            'danger' => true,
        ];
    }

    /**
     * @return array{title: string, body: string, button: string, danger: bool}
     */
    public static function safelist(string $ip): array
    {
        return [
            'title' => Craft::t('porro', 'Safelist {ip}?', ['ip' => $ip]),
            'body' => Craft::t('porro', 'It will never be blocked or flagged here, even if it keeps probing. Remove it from the safelist on the Blocking page.'),
            'button' => Craft::t('porro', 'Safelist'),
            'danger' => false,
        ];
    }

    /**
     * For a form's data-porro-confirm attribute.
     *
     * @param  array{title: string, body: string, button: string, danger: bool}  $confirm
     */
    public static function attribute(array $confirm): string
    {
        return (string) json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The script behind forms with data-porro-confirm: it opens the page's
     * <dialog id="porro-confirm"> and submits once confirmed. Without it,
     * the form simply submits, so nothing breaks.
     */
    public static function script(): string
    {
        return (string) file_get_contents(__DIR__.'/../resources/confirm.js');
    }

    /** Addresses in a range, readable: "256", "65,536", or "many" for IPv6. */
    private static function size(string $range): string
    {
        [$ip, $bits] = explode('/', $range, 2);

        if (str_contains($ip, ':')) {
            return Craft::t('porro', 'many');
        }

        return number_format(2 ** (32 - max(0, min(32, (int) $bits))));
    }
}
