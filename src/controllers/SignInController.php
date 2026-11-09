<?php

namespace porro\craft\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use InvalidArgumentException;
use Porro\Core\SiteKey;
use porro\craft\Plugin;
use porro\craft\SignIn;
use yii\web\Response;

/**
 * "Sign in to Craft" from the site page in Porro, at POST /porro/sign-in on
 * the front-end site. Porro's page posts a one-time ticket here; the site
 * checks it with Porro using its own key and signs in the Craft user with
 * the member's email. No password changes hands, no account is ever made
 * and no permission is ever changed: a member without a control panel
 * account here gets a page saying so.
 */
class SignInController extends Controller
{
    // Tries from one IP in a minute, like the Statamic connector's throttle.
    public const TRIES_PER_MINUTE = 10;

    // How long "Switch to …" on the confirmation page works, once.
    public const SWITCH_TTL = 300;

    private const SWITCH_KEY = 'porro:sign-in:switch:';

    // The visitor isn't signed in yet; that's the point. Confirming a
    // switch needs someone signed in, and Craft's CSRF check.
    protected array|bool|int $allowAnonymous = ['index' => self::ALLOW_ANONYMOUS_LIVE];

    public function beforeAction($action): bool
    {
        // Porro's page posts the ticket from another origin, so there's no
        // CSRF token to check. The ticket, redeemed with the site's own key,
        // is what proves the request. Every other action keeps the check.
        if ($action->id === 'index') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $this->requirePostRequest();

        // Read once and then dropped from the request, so a warning logged
        // later in this request, with Craft's request context, never
        // carries it.
        $ticket = $this->request->getBodyParam('ticket');
        $this->forgetTicket();

        if (! $this->withinLimit()) {
            $this->response->getHeaders()->set('Retry-After', '60');

            return $this->page('too_many', 429);
        }

        // The site owner's switch comes first, and Porro is never asked.
        if (! SignIn::enabled()) {
            return $this->page('turned_off_here', 403);
        }

        try {
            $key = SiteKey::parse((string) Plugin::key());
        } catch (InvalidArgumentException) {
            return $this->page('not_connected', 403);
        }

        // Only the shape Porro makes. Anything else can't be a live ticket,
        // so it isn't worth a request to Porro.
        if (! is_string($ticket) || ! preg_match('/^[A-Za-z0-9_.~:-]{16,512}$/', $ticket)) {
            return $this->page('expired', 410);
        }

        $result = (new SignIn)->redeem($key, Plugin::url(), $ticket);

        return match ($result['status']) {
            SignIn::OK => $this->signIn((string) $result['email']),
            SignIn::EXPIRED => $this->page('expired', 410),
            SignIn::TURNED_OFF => $this->page('turned_off_in_porro', 403),
            SignIn::KEY_REFUSED => $this->page('key_refused', 502),
            default => $this->page('unreachable', 502),
        };
    }

    private function signIn(string $email): Response
    {
        $matches = $this->findUsers($email);

        if ($matches === []) {
            return $this->page('no_account', 403, $email);
        }

        // Suspended, pending and deactivated accounts stay out, as they
        // would with a password, and so do accounts without the control
        // panel. can() is always true for admins.
        $usable = array_values(array_filter($matches, fn (User $user) => self::canSignIn($user)));

        if ($usable === []) {
            return $matches[0]->getStatus() !== User::STATUS_ACTIVE
                ? $this->page('not_active', 403, $email)
                : $this->page('no_cp_access', 403, $email);
        }

        // Postgres allows two accounts whose emails differ only in case.
        // Porro can't say which one the member meant, so neither is used.
        if (count($usable) > 1) {
            return $this->page('ambiguous', 403, $email);
        }

        $user = $usable[0];
        $current = Craft::$app->getUser()->getIdentity();

        if ($current instanceof User && (int) $current->id === (int) $user->id) {
            $this->privateHeaders();

            return $this->redirect(UrlHelper::cpUrl());
        }

        // Someone else is already signed in in this browser. A ticket posted
        // here by another page mustn't quietly swap their session for the
        // ticket's account (login CSRF), so they're asked first, and only
        // a same-site POST with Craft's CSRF token switches.
        if ($current instanceof User) {
            return $this->confirmSwitch($current, $user);
        }

        return $this->logIn($user, $email);
    }

    /**
     * "Switch to …": the confirmation page's form, with its CSRF token and
     * the token naming who to switch to.
     */
    public function actionConfirm(): Response
    {
        $this->requirePostRequest();

        if (! SignIn::enabled()) {
            return $this->page('turned_off_here', 403);
        }

        $nonce = Craft::$app->getSecurity()->validateData((string) $this->request->getBodyParam('switch'));
        $current = Craft::$app->getUser()->getIdentity();
        $cache = Craft::$app->getCache();
        $switch = is_string($nonce) && $nonce !== '' ? $cache->get(self::SWITCH_KEY.$nonce) : false;

        if (is_string($nonce) && $nonce !== '') {
            // Single use, whatever happens next.
            $cache->delete(self::SWITCH_KEY.$nonce);
        }

        // Only the browser session it was made for can use it.
        if (! is_array($switch) || ! $current instanceof User || (int) ($switch['from'] ?? 0) !== (int) $current->id) {
            return $this->page('switch_expired', 410);
        }

        /** @var User|null $user */
        $user = User::find()->id((int) ($switch['to'] ?? 0))->status(null)->one();

        // Checked again: the account may have changed in the meantime.
        if ($user === null || ! self::canSignIn($user)) {
            return $this->page('switch_expired', 410);
        }

        return $this->logIn($user, (string) $user->email);
    }

    private function confirmSwitch(User $current, User $user): Response
    {
        $nonce = Craft::$app->getSecurity()->generateRandomString(32);
        Craft::$app->getCache()->set(self::SWITCH_KEY.$nonce, ['from' => (int) $current->id, 'to' => (int) $user->id], self::SWITCH_TTL);

        return $this->page('switch', 200, (string) $user->email, [
            'current' => (string) $current->email,
            'switchToken' => Craft::$app->getSecurity()->hashData($nonce),
            'action' => UrlHelper::actionUrl('porro/sign-in/confirm'),
            'stay' => UrlHelper::cpUrl(),
        ]);
    }

    private function logIn(User $user, string $email): Response
    {
        $session = Craft::$app->getUser();

        // Yii gives the session a fresh id as it logs in, so nothing from
        // before the sign-in carries over.
        if (! $session->login($user)) {
            return $this->page('not_signed_in', 403, $email);
        }

        // Craft's login() also marks the session as elevated, as if the
        // password had just been typed. It hasn't, so anything that asks
        // for the password again (changing an email or password, for one)
        // still does.
        Craft::$app->getSession()->remove($session->elevatedSessionTimeoutParam);

        // And a fresh CSRF token, as Craft makes on logging out.
        if (Craft::$app->getConfig()->getGeneral()->enableCsrfProtection) {
            $this->request->regenCsrfToken();
        }

        $this->privateHeaders();

        return $this->redirect(UrlHelper::cpUrl());
    }

    private static function canSignIn(User $user): bool
    {
        return $user->getStatus() === User::STATUS_ACTIVE && $user->can('accessCp');
    }

    /**
     * Every account with this email. Craft compares emails case-sensitively
     * on Postgres, and Porro's may differ in case from the one on the
     * account here. Only the email counts, never the username.
     *
     * @return list<User>
     */
    private function findUsers(string $email): array
    {
        /** @var User[] $candidates */
        $candidates = User::find()
            ->status(null)
            ->where(['lower([[users.email]])' => mb_strtolower($email)])
            ->orderBy(['elements.id' => SORT_ASC])
            ->all();

        return array_values(array_filter($candidates, fn (User $user) => strcasecmp((string) $user->email, $email) === 0));
    }

    /**
     * Counted in Craft's cache, so load-balanced servers with a shared
     * cache share the limit. Not atomic, so a burst can get a try or two
     * more; each try still needs a live ticket.
     */
    private function withinLimit(): bool
    {
        $cache = Craft::$app->getCache();
        $key = 'porro:sign-in:'.hash('sha256', (string) $this->request->getUserIP()).':'.intdiv(time(), 60);
        $tries = (int) $cache->get($key);

        if ($tries >= self::TRIES_PER_MINUTE) {
            return false;
        }

        $cache->set($key, $tries + 1, 120);

        return true;
    }

    private function forgetTicket(): void
    {
        unset($_POST['ticket']);

        $params = $this->request->getBodyParams();
        unset($params['ticket']);
        $this->request->setBodyParams($params);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function page(string $reason, int $status, ?string $email = null, array $extra = []): Response
    {
        $this->response->setStatusCode($status);
        $this->response->format = Response::FORMAT_HTML;
        $this->response->data = Craft::$app->getView()->renderTemplate('porro/_sign-in', [
            'reason' => $reason,
            'email' => $email,
            'login' => UrlHelper::cpUrl('login'),
        ] + $extra, View::TEMPLATE_MODE_CP);
        // After rendering: Craft sets its own no-cache headers when a page
        // asks for a CSRF token, as the switch form does.
        $this->privateHeaders();

        return $this->response;
    }

    private function privateHeaders(): void
    {
        // A sign-in answer is for this visitor once, never for a cache.
        $this->response->getHeaders()
            ->set('Cache-Control', 'no-store, private')
            ->set('Referrer-Policy', 'no-referrer');
    }
}
