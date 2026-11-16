<?php

namespace porro\craft\controllers;

use Craft;
use craft\web\Controller;
use porro\craft\connect\ConnectFailure;
use porro\craft\connect\Disconnect;
use porro\craft\connect\PanelConnect;
use porro\craft\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * "Connect to Porro" on the Site health page: start sends the browser to
 * Porro to approve, and the return route finishes it. Both need the
 * "Connect the site to Porro" permission, not just seeing the page.
 * Anything that goes wrong lands back on Site health with the reason in
 * words. Disconnect undoes it, for the same people.
 */
class ConnectController extends Controller
{
    // The reason it didn't connect, shown once on Site health.
    public const ERROR_FLASH = 'porro.connectError';

    // Who the site was disconnected from and whether Porro was told,
    // shown once on Site health.
    public const DISCONNECTED_FLASH = 'porro.disconnected';

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

    /**
     * "Disconnect" on the connected card: tells Porro and deletes the key
     * made here. Never a key from the environment, and never from a copy
     * that isn't the live site.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        $session = Craft::$app->getSession();

        if (Plugin::keys()->fromEnv() !== null) {
            $session->setFlash(self::ERROR_FLASH, Craft::t('porro', 'This site is connected with PORRO_KEY in its environment, which the control panel can’t change. Remove PORRO_KEY there and deploy.'));

            return $this->redirect('porro');
        }

        // A staging or local copy of the live database holds the live
        // site's key: disconnecting here would switch the live site off.
        if (! Plugin::production()) {
            $session->setFlash(self::ERROR_FLASH, Craft::t('porro', 'This copy isn’t the live site, so it can’t disconnect: that would switch off the live site’s key in Porro too. Disconnect from the live site’s control panel.'));

            return $this->redirect('porro');
        }

        $result = (new Disconnect)->disconnect();

        // Nothing to disconnect: the page says so by itself.
        if ($result !== null) {
            $session->setFlash(self::DISCONNECTED_FLASH, ['workspace' => $result['workspace'], 'told' => $result['told']]);
        }

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
