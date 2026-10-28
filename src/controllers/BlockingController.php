<?php

namespace porro\craft\controllers;

use Closure;
use Craft;
use craft\web\Controller;
use Porro\Core\Activity\Blocklist;
use Porro\Core\Activity\CloudflareError;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\Refused;
use porro\craft\activity\Presenter;
use porro\craft\Plugin;
use Throwable;
use yii\web\Response;

/**
 * The Blocking page, `/admin/porro/blocking`, the full blocklist and
 * safelist, and the forms that change them. Every rule about what may be
 * blocked lives in core's Blocklist; this only shows its answers.
 */
class BlockingController extends Controller
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
        Plugin::getInstance()->getActivity()->maintainDaily();
        $prefill = filter_var($this->request->getQueryParam('safelist'), FILTER_VALIDATE_IP) ?: null;

        return $this->renderTemplate('porro/blocking/_index', (new Presenter(Plugin::getInstance()->getActivity()))->blocking() + [
            'selectedSubnavItem' => 'blocking',
            'prefill' => $prefill,
            'canManage' => Plugin::canManage(),
        ]);
    }

    /**
     * Setting up nginx: the steps for the site and each server, and how
     * far each server has got.
     */
    public function actionNginx(): Response
    {
        return $this->renderTemplate('porro/blocking/_nginx', (new Presenter(Plugin::getInstance()->getActivity()))->nginx($this->request->getRemoteIP()) + [
            'selectedSubnavItem' => 'blocking',
        ]);
    }

    /**
     * Setting up Cloudflare: the token and its two permission policies,
     * where it goes, and a button that connects.
     */
    public function actionCloudflare(): Response
    {
        return $this->renderTemplate('porro/blocking/_cloudflare', (new Presenter(Plugin::getInstance()->getActivity()))->cloudflare() + [
            'selectedSubnavItem' => 'blocking',
            'canManage' => Plugin::canManage(),
        ]);
    }

    /**
     * Connect from the page, so nobody needs a terminal: finds the zone,
     * creates or reuses the list and adds the rule, then syncs.
     */
    public function actionCloudflareConnect(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::MANAGE_PERMISSION);
        $cloudflare = Plugin::getInstance()->getActivity()->cloudflare();

        if (! $cloudflare->configured()) {
            $this->setFailFlash(Craft::t('porro', 'Set PORRO_CLOUDFLARE_TOKEN first, then connect.'));

            return $this->redirectToPostedUrl(null, 'porro/blocking/cloudflare');
        }

        try {
            $connection = $cloudflare->connect();
        } catch (CloudflareError $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirectToPostedUrl(null, 'porro/blocking/cloudflare');
        }

        $result = $cloudflare->sync();
        $this->setSuccessFlash($result !== null
            ? Craft::t('porro', 'Connected to {zone} and synced the blocklist.', ['zone' => $connection['zone_name']])
            : Craft::t('porro', 'Connected to {zone}. Only production syncs the blocklist.', ['zone' => $connection['zone_name']]));

        return $this->redirectToPostedUrl(null, 'porro/blocking/cloudflare');
    }

    /**
     * The whole blocklist or safelist: searchable, filterable, 50 a page.
     */
    public function actionList(string $list): Response
    {
        $query = Presenter::listQuery($this->request->getQueryParams());

        return $this->renderTemplate('porro/blocking/_list', (new Presenter(Plugin::getInstance()->getActivity()))->entries($list, ...$query) + [
            'selectedSubnavItem' => 'blocking',
            'canManage' => Plugin::canManage(),
        ]);
    }

    public function actionBlock(): ?Response
    {
        $ip = $this->ip();
        $reason = trim((string) $this->request->getBodyParam('reason', ''));
        $days = max(0, min(3650, (int) $this->request->getBodyParam('days', 0)));
        $suggested = (bool) $this->request->getBodyParam('suggested');
        $activity = Plugin::getInstance()->getActivity();

        return $this->change(fn (Blocklist $list) => $list->block(
            $ip,
            mb_substr($reason, 0, 255) ?: Craft::t('porro', 'Blocked in the control panel'),
            $suggested ? Entry::SUGGESTED : Entry::MANUAL,
            $days > 0 ? time() + $days * 86400 : null,
            $this->who(),
            $activity->clientIp($this->request),
        ), fn (Entry $entry) => Craft::t('porro', 'Blocked {ip}.', ['ip' => $entry->cidr]));
    }

    public function actionUnblock(): ?Response
    {
        $ip = $this->ip();

        return $this->change(fn (Blocklist $list) => $list->unblock($ip), fn () => Craft::t('porro', 'Unblocked {ip}.', ['ip' => $ip]));
    }

    public function actionSafelist(): ?Response
    {
        $ip = $this->ip();
        $label = mb_substr(trim((string) $this->request->getBodyParam('label', '')), 0, 255);
        $wide = (bool) $this->request->getBodyParam('confirmWide');

        return $this->change(
            fn (Blocklist $list) => $list->safelist($ip, $label, null, $this->who(), $wide),
            fn (Entry $entry) => Craft::t('porro', 'Safelisted {ip}.', ['ip' => $entry->cidr]),
        );
    }

    public function actionUnsafelist(): ?Response
    {
        $ip = $this->ip();

        return $this->change(fn (Blocklist $list) => $list->unsafelist($ip), fn () => Craft::t('porro', 'Removed {ip} from the safelist.', ['ip' => $ip]));
    }

    private function ip(): string
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::MANAGE_PERMISSION);

        return mb_substr(trim((string) $this->request->getRequiredBodyParam('ip')), 0, 64);
    }

    /**
     * Applies a change, then refreshes what depends on the lists: the
     * request-time copy and the cached health.
     */
    private function change(Closure $change, Closure $message): ?Response
    {
        $activity = Plugin::getInstance()->getActivity();

        try {
            $result = $change($activity->blocklist());
        } catch (Refused $e) {
            $this->setFailFlash($e->getMessage());
            // So the form keeps what was typed.
            Craft::$app->getSession()->setFlash('porro-form', $this->request->getBodyParams());

            return $this->redirectToPostedUrl(null, 'porro/blocking');
        }

        $activity->changed();

        try {
            Plugin::getInstance()->getSiteHealth()->forgetPanel();
        } catch (Throwable $e) {
            Craft::warning('Porro couldn’t clear the cached health: '.$e->getMessage(), __METHOD__);
        }

        $this->setSuccessFlash($message($result));

        return $this->redirectToPostedUrl(null, 'porro/blocking');
    }

    private function who(): string
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user ? (string) ($user->getFriendlyName() ?: $user->username) : 'console';
    }
}
