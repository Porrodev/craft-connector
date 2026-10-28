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
 * requests are mostly 404s. Recording is a line in this server's buffer,
 * flushed now and then.
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
            // Per request, should a worker serve more than one.
            $this->blocked = false;
            $this->failedLogin = false;
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
        register_shutdown_function(fn () => $this->recordTurnedAway());
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
                self::method(),
                self::path(),
                401,
                is_string($username) && $username !== '' ? $username : null,
                country: $activity->country($request),
                userAgent: $request->getUserAgent(),
            );
            $activity->buffered();
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
            $method = self::method();
            $path = self::path();

            $status = (int) (http_response_code() ?: Craft::$app->getResponse()->getStatusCode());
            $recorder = $activity->recorder();
            $category = $activity->probes()->match($path);
            $more = [
                'country' => $activity->country($request),
                'userAgent' => $request->getUserAgent(),
                'host' => self::host(),
                'proxied' => $activity->viaCloudflare($request),
            ];

            $suspicious = true;

            if ($category !== null) {
                $recorder->record($ip, Recorder::PROBE, $method, $path, $status, ...[...$more, 'category' => $category]);

                if (Probes::exposes($category) && $status >= 200 && $status < 300) {
                    $recorder->exposed($ip, $path, $status);
                }
            } elseif ($status === 404) {
                $recorder->record($ip, Recorder::NOT_FOUND, $method, $path, $status, ...$more);
            } elseif ($method === 'POST' && ! $this->failedLogin && ! $this->signedIn()) {
                // People using the control panel post all day; a flood comes from outside.
                $recorder->record($ip, Recorder::POST_FLOOD, $method, $path, $status, ...$more);
            } elseif (! $this->failedLogin && $activity->watching($ip)) {
                // Flagged already: count the rest, so its page shows them all.
                $recorder->seen($ip, $method, $path, $status, $more['host'], $more['userAgent'], $more['proxied']);
            } else {
                $suspicious = false;
            }

            if ($suspicious) {
                $activity->buffered();
            }
        } catch (Throwable) {
            //
        }

        $this->flush();

        // Expired rows are cleared now and then even without cron, after
        // recording, so a failed tidy never costs an event.
        try {
            if (random_int(1, 500) === 1 && ($activity = $this->activity()) && $activity->recording()) {
                $activity->tidyStore();
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * After a block's 403 has gone: counted for the IP's page.
     */
    private function recordTurnedAway(): void
    {
        try {
            $activity = $this->activity();

            if (! $activity || ! $activity->recording()) {
                return;
            }

            $request = Craft::$app->getRequest();
            $activity->recorder()->turnedAway($activity->clientIp($request), self::method(), self::path(), self::host(), $request->getUserAgent(), $activity->viaCloudflare($request));
            $activity->buffered();
        } catch (Throwable) {
            //
        }

        $this->flush();
    }

    /**
     * Records the buffer now and then, once this request has added to it:
     * an ordinary page view never pays for it.
     */
    private function flush(): void
    {
        try {
            $this->activity()?->flushIfDue();
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
     * The method actually sent: Yii honours X-Http-Method-Override and
     * _method, which would let a POST flood pass as a PUT.
     */
    private static function method(): string
    {
        return (string) ($_SERVER['REQUEST_METHOD'] ?? Craft::$app->getRequest()->getMethod());
    }

    /**
     * The Host it asked for, or null if it can't be read.
     */
    private static function host(): ?string
    {
        try {
            return Craft::$app->getRequest()->getHostName();
        } catch (Throwable) {
            return null;
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
