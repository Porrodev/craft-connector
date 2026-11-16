<?php

namespace porro\craft\controllers;

use Craft;
use craft\web\Controller;
use porro\craft\connect\ConnectFailure;
use porro\craft\connect\PanelConnect;
use porro\craft\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * "Connect to Porro" on the Site health page: start sends the browser to
 * Porro to approve, and the return route finishes it. Both need the
 * "Connect the site to Porro" permission, not just seeing the page.
 * Anything that goes wrong lands back on Site health with the reason in
 * words.
 */
class ConnectController extends Controller
{
    // The reason it didn't connect, shown once on Site health.
    public const ERROR_FLASH = 'porro.connectError';

    public function beforeAction($action): bool
    {
        if (! parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION);
        $this->requirePermission(Plugin::CONNECT_PERMISSION);

        return true;
    }

    public function actionStart(): Response
    {
        $this->requirePostRequest();

        try {
            return $this->redirect((new PanelConnect)->start($this->request, $this->user()));
        } catch (ConnectFailure $e) {
            return $this->failed($e);
        }
    }

    public function actionReturn(): Response
    {
        $connect = new PanelConnect;

        // Porro's own page sends the person back here when they're done.
        if (! $connect->returning($this->request)) {
            return $this->redirect('porro');
        }

        try {
            $next = $connect->finish($this->request, $this->user());
        } catch (ConnectFailure $e) {
            return $this->failed($e);
        }

        if ($next !== null) {
            return $this->redirect($next);
        }

        Craft::$app->getSession()->setNotice(Craft::t('porro', 'Connected to Porro. The first report has been sent.'));

        return $this->redirect('porro');
    }

    private function failed(ConnectFailure $failure): Response
    {
        Craft::$app->getSession()->setFlash(self::ERROR_FLASH, $failure->getMessage());

        return $this->redirect('porro');
    }

    /**
     * Whoever is signed in. Craft has already turned away anyone who
     * isn't; a request to connect is never tied to nobody.
     */
    private function user(): string
    {
        $id = (string) Craft::$app->getUser()->getId();

        if ($id === '') {
            throw new ForbiddenHttpException('User is not signed in.');
        }

        return $id;
    }
}
