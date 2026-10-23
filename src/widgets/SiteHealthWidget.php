<?php

namespace porro\craft\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Cp;
use porro\craft\Plugin;

/**
 * Dashboard: what needs attention, linking to the Site health page.
 * Shows the last result only, so the dashboard never waits on checks.
 */
class SiteHealthWidget extends Widget
{
    public int $limit = 5;

    public static function displayName(): string
    {
        return Craft::t('porro', 'Site health');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@porro/craft/icon-mask.svg');
    }

    public static function isSelectable(): bool
    {
        return parent::isSelectable() && (bool) Craft::$app->getUser()->checkPermission(Plugin::PERMISSION);
    }

    public function getTitle(): ?string
    {
        return Craft::t('porro', 'Site health');
    }

    public function getBodyHtml(): ?string
    {
        if (! Craft::$app->getUser()->checkPermission(Plugin::PERMISSION)) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('porro/_widget/body', Plugin::getInstance()->getSiteHealth()->view(max(1, $this->limit), cachedOnly: true));
    }

    public function getSettingsHtml(): ?string
    {
        return Cp::textFieldHtml([
            'label' => Craft::t('porro', 'How many issues to list'),
            'id' => 'limit',
            'name' => 'limit',
            'type' => 'number',
            'min' => 1,
            'value' => $this->limit,
        ]);
    }

    protected function defineRules(): array
    {
        return [...parent::defineRules(), [['limit'], 'integer', 'min' => 1, 'max' => 50]];
    }
}
