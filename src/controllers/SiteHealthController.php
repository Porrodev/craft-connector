<?php

namespace porro\craft\controllers;

use Craft;
use craft\web\Controller;
use porro\craft\connect\PanelConnect;
use porro\craft\Plugin;
use yii\web\Response;

/**
 * The Site health page: `/admin/porro`. Admins, and users with the
 * "Access Porro" permission.
 */
class SiteHealthController extends Controller
{
    public function beforeAction($action): bool
    {
        if (! parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION);

        return true;
    }

    public function actionIndex(): Response
    {
        $view = Plugin::getInstance()->getSiteHealth()->view();

        return $this->renderTemplate('porro/_index', $view + [
            'selectedSubnavItem' => 'health',
            'connect' => (new PanelConnect)->panel($view['connected'], $view['porroUrl']),
            // Why "Connect to Porro" didn't finish, when it just didn't.
            'connectError' => Craft::$app->getSession()->getFlash(ConnectController::ERROR_FLASH, null, true),
            // The site was just disconnected: said once, on this view only.
            'disconnected' => self::disconnected(Craft::$app->getSession()->getFlash(ConnectController::DISCONNECTED_FLASH, null, true)),
        ]);
    }

    /**
     * @return array{workspace: string|null, told: bool}|null
     */
    private static function disconnected(mixed $flash): ?array
    {
        return is_array($flash) ? [
            'workspace' => is_string($flash['workspace'] ?? null) && $flash['workspace'] !== '' ? $flash['workspace'] : null,
            'told' => (bool) ($flash['told'] ?? false),
        ] : null;
    }

    /**
     * "Check again": run every check now rather than use the cached result.
     */
    public function actionRefresh(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getSiteHealth()->get(fresh: true);
        Craft::$app->getSession()->setNotice(Craft::t('porro', 'Checked again.'));

        return $this->redirect('porro');
    }
}
