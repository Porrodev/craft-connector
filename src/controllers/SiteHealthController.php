<?php

namespace porro\craft\controllers;

use Craft;
use craft\web\Controller;
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
        return $this->renderTemplate('porro/_index', Plugin::getInstance()->getSiteHealth()->view());
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
