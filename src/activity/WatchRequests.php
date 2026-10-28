<?php

namespace porro\craft\activity;

use Craft;
use craft\controllers\UsersController;
use craft\web\Application as WebApplication;
use Porro\Core\Activity\Probes;
use Porro\Core\Activity\Recorder;
use porro\craft\Plugin;
use Throwable;
use yii\base\Application;
use yii\base\Event;

/**
 * Turns blocked IPs away with a plain 403 before Craft does any work, and
 * once the request is over, records requests that look like an attack.
 *
 * Recording runs at shutdown rather than after the request: Craft's error
 * handler exits on a 404 before EVENT_AFTER_REQUEST, and a scanner's
 * requests are mostly 404s.
 */
class WatchRequests
{
    private bool $blocked = false;

    private bool $failedLogin = false;

    public function register(): void
    {
        if (! Craft::$app instanceof WebApplication) {
            return;
        }

        Craft::$app->on(Application::EVENT_BEFORE_REQUEST, function () {
            $this->turnAway();

            if (! $this->blocked) {
                register_shutdown_function(fn () => $this->record());
            }
        });

        Event::on(UsersController::class, UsersController::EVENT_LOGIN_FAILURE, fn () => $this->loginFailed());
    }

    private function activity(): ?Activity
    {
        return Plugin::getInstance()?->getActivity();
    }

    private function turnAway(): void
    {
        try {
            $activity = $this->activity();

            if (! $activity || ! $activity->enforcing() || $activity->blocked($activity->clientIp(Craft::$app->getRequest())) === null) {
                return;
            }
        } catch (Throwable) {
            // Fail open: a broken blocklist never takes the site down.
            return;
        }

        $this->blocked = true;
        $response = Craft::$app->getResponse();
        $response->setStatusCode(403);
        $response->format = $response::FORMAT_RAW;
        $response->data = 'Forbidden';
        $response->getHeaders()->set('Content-Type', 'text/plain')->set('X-Porro-Blocked', '1');
        Craft::$app->end(0, $response);
    }

    /**
     * Counts a failed sign-in. The username is kept only as a keyed hash.
     */
    private function loginFailed(): void
    {
        try {
            $activity = $this->activity();

            if (! $activity || ! $activity->recording()) {
                return;
            }

            $request = Craft::$app->getRequest();
            $username = $request->getBodyParam('loginName');
            $this->failedLogin = true;

            $activity->recorder()->record(
                $activity->clientIp($request),
                Recorder::LOGIN_FAILED,
                $request->getMethod(),
                self::path(),
                401,
                is_string($username) && $username !== '' ? $username : null,
                country: $activity->country($request),
                userAgent: $request->getUserAgent(),
            );
        } catch (Throwable) {
            //
        }
    }

    private function record(): void
    {
        try {
            $activity = $this->activity();

            if ($this->blocked || ! $activity || ! $activity->recording()) {
                return;
            }

            $request = Craft::$app->getRequest();
            $ip = $activity->clientIp($request);
            $country = $activity->country($request);
            $method = $request->getMethod();
            $path = self::path();
            $status = (int) (http_response_code() ?: Craft::$app->getResponse()->getStatusCode());
            $recorder = $activity->recorder();
            $category = $activity->probes()->match($path);

            if ($category !== null) {
                $recorder->record($ip, Recorder::PROBE, $method, $path, $status, country: $country, userAgent: $request->getUserAgent());

                if (Probes::exposes($category) && $status >= 200 && $status < 300) {
                    $recorder->exposed($ip, $path, $status);
                }

                return;
            }

            if ($status === 404) {
                $recorder->record($ip, Recorder::NOT_FOUND, $method, $path, $status, country: $country, userAgent: $request->getUserAgent());
            } elseif ($method === 'POST' && ! $this->failedLogin && ! $this->signedIn()) {
                // People using the control panel post all day; a flood comes from outside.
                $recorder->record($ip, Recorder::POST_FLOOD, $method, $path, $status, country: $country, userAgent: $request->getUserAgent());
            }
        } catch (Throwable) {
            //
        }
    }

    private function signedIn(): bool
    {
        try {
            return Craft::$app->getUser()->getIdentity(false) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The request path with its leading slash, without the query string.
     */
    private static function path(): string
    {
        $path = parse_url((string) Craft::$app->getRequest()->getUrl(), PHP_URL_PATH);

        return '/'.ltrim(is_string($path) ? $path : '', '/');
    }
}
