<?php

namespace porro\craft\controllers;

use Craft;
use craft\web\Controller;
use porro\craft\activity\Presenter;
use porro\craft\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Activity page, `/admin/porro/activity`, and each IP's details.
 */
class ActivityController extends Controller
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
        $activity = Plugin::getInstance()->getActivity();
        $activity->maintainDaily();

        try {
            $overview = (new Presenter($activity))->activity($activity->overview());
        } catch (Throwable $e) {
            Craft::error('Porro couldn’t read the activity: '.$e->getMessage(), __METHOD__);
            $overview = null;
        }

        return $this->renderTemplate('porro/activity/_index', [
            'selectedSubnavItem' => 'activity',
            'activity' => $overview,
            'canManage' => Plugin::canManage(),
        ]);
    }

    /**
     * IP details: who it is, what it did here, and what can be done.
     */
    public function actionIp(string $ip): Response
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new NotFoundHttpException;
        }

        $reputation = Craft::$app->getSession()->getFlash('porro-reputation');

        return $this->renderTemplate('porro/activity/_ip', [
            'selectedSubnavItem' => 'activity',
            'ipInfo' => (new Presenter(Plugin::getInstance()->getActivity()))->ip($ip, is_array($reputation) && ($reputation['ip'] ?? null) === $ip ? $reputation : null),
            'canManage' => Plugin::canManage(),
        ]);
    }

    /**
     * AbuseIPDB, on request only: it sends the IP to AbuseIPDB.
     */
    public function actionReputation(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::MANAGE_PERMISSION);

        $ip = (string) $this->request->getRequiredBodyParam('ip');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new NotFoundHttpException;
        }

        $result = Plugin::getInstance()->getActivity()->reputation($ip);

        if ($result === null) {
            $this->setFailFlash(Craft::t('porro', 'AbuseIPDB couldn’t be reached just now, or the key was refused.'));
        } else {
            Craft::$app->getSession()->setFlash('porro-reputation', $result + ['ip' => $ip]);
        }

        return $this->redirect('porro/activity/'.$ip);
    }
}
