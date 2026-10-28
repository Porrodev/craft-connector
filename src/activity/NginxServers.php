<?php

namespace porro\craft\activity;

use Craft;
use Porro\Core\Activity\NginxStatus;
use Throwable;

/**
 * Each server's nginx setup, as it last saw it. In nginx mode every server
 * runs porro/blocklist/sync each minute and records its own check here, so
 * the setup page can list them all. That needs a cache the servers share
 * (Redis, the database); with a per-server cache, only the server showing
 * the page appears.
 */
class NginxServers
{
    public const KEY = 'porro:nginx:servers';

    /** Servers not heard from in this long drop off the list. */
    public const FORGET_AFTER = 86400;

    public const LABELS = [
        NginxStatus::LOADED => 'Loaded',
        NginxStatus::NOT_WRITTEN => 'No file yet',
        NginxStatus::NOT_INCLUDED => 'Include missing',
        NginxStatus::WAITING => 'Waiting for reload',
        NginxStatus::UNCONFIRMED => 'Reloading, include not confirmed',
    ];

    public function __construct(private Activity $activity) {}

    /**
     * Checks this server and records it.
     *
     * @return array<string, mixed>
     */
    public function record(): array
    {
        $status = NginxStatus::check($this->activity->nginxPath(), $this->activity->nginxStamp(), $this->activity->nginxConfigDirs()) + ['checked_at' => time()];

        try {
            $servers = $this->all();
            $servers[self::name()] = $status;
            Activity::store()->set(self::KEY, array_filter($servers, fn ($s) => ($s['checked_at'] ?? 0) > time() - self::FORGET_AFTER), 0);
        } catch (Throwable) {
            // A broken cache only means the page can't list this server.
        }

        return $status;
    }

    /**
     * @return array<string, array<string, mixed>> hostname => status
     */
    public function all(): array
    {
        $servers = Activity::store()->get(self::KEY);

        return is_array($servers) ? $servers : [];
    }

    /**
     * The servers, ready to show: worst first.
     *
     * @return list<array<string, mixed>>
     */
    public function present(): array
    {
        $order = array_flip([NginxStatus::NOT_WRITTEN, NginxStatus::NOT_INCLUDED, NginxStatus::WAITING, NginxStatus::UNCONFIRMED, NginxStatus::LOADED]);
        $rows = [];

        foreach ($this->all() as $name => $status) {
            $state = (string) ($status['state'] ?? NginxStatus::NOT_WRITTEN);
            $rows[] = [
                'name' => (string) $name,
                'state' => $state,
                'label' => Craft::t('porro', self::LABELS[$state] ?? $state),
                'ok' => $state === NginxStatus::LOADED,
                'reloaded' => isset($status['reloaded']) ? Presenter::ago((int) $status['reloaded']) : null,
                'checked' => isset($status['checked_at']) ? Presenter::ago((int) $status['checked_at']) : null,
                'realIp' => $status['real_ip'] ?? null,
                'config' => $status['config'] ?? null,
            ];
        }

        usort($rows, fn ($a, $b) => [$order[$a['state']] ?? 9, $a['name']] <=> [$order[$b['state']] ?? 9, $b['name']]);

        return $rows;
    }

    public static function name(): string
    {
        return gethostname() ?: 'this server';
    }
}
