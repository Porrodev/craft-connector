<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use Porro\Core\Activity\Cloudflare;
use Porro\Core\Activity\CloudflareError;
use porro\craft\activity\CloudflareMode;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Blocks at Cloudflare's edge: `php craft porro/cloudflare connect`, then
 * `sync`, `status` or `disconnect`.
 */
class CloudflareController extends Controller
{
    public function actionIndex(string $action = 'status'): int
    {
        $cloudflare = Plugin::getInstance()->getActivity()->cloudflare();

        if (! $cloudflare->configured()) {
            $this->stderr('Set PORRO_CLOUDFLARE_TOKEN first: a token with Account Filter Lists Edit, Zone WAF Edit and Zone Read.'.PHP_EOL);

            return ExitCode::CONFIG;
        }

        try {
            return match ($action) {
                'connect' => $this->connect($cloudflare),
                'sync' => $this->sync($cloudflare),
                'disconnect' => $this->disconnect($cloudflare),
                default => $this->status($cloudflare),
            };
        } catch (CloudflareError $e) {
            $this->stderr($e->getMessage().PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    private function connect(CloudflareMode $cloudflare): int
    {
        $connection = $cloudflare->connect();

        $this->stdout("Connected to {$connection['zone_name']}: blocks go to the ".Cloudflare::LIST_NAME.' list, with a rule blocking it on this zone.'.PHP_EOL);

        return $this->sync($cloudflare);
    }

    private function sync(CloudflareMode $cloudflare): int
    {
        $result = $cloudflare->sync();

        if ($result === null) {
            $status = $cloudflare->status();
            $this->stderr(($status['production'] ? ($status['error'] ?? 'Didn’t sync.') : 'Only production syncs to Cloudflare: a copy of the site shouldn’t change the list every site on the account shares.').PHP_EOL);

            return $status['production'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
        }

        $this->stdout("Synced: {$result['added']} added, {$result['removed']} removed".($result['shared'] ? ", {$result['shared']} already blocked by another site" : '').'.'.PHP_EOL);

        return ExitCode::OK;
    }

    private function disconnect(CloudflareMode $cloudflare): int
    {
        $cloudflare->disconnect();
        $this->stdout('Disconnected: this site’s items and the rule are gone from Cloudflare.'.PHP_EOL);

        return ExitCode::OK;
    }

    private function status(CloudflareMode $cloudflare): int
    {
        $status = $cloudflare->status();

        $this->stdout('Zone: '.($status['zone'] ?? 'not connected (php craft porro/cloudflare connect)').PHP_EOL);
        $this->stdout('Syncs: '.($status['production'] ? 'yes' : 'no, not production').PHP_EOL);
        $this->stdout('Last sync: '.($status['synced_at'] ? date('j M H:i', $status['synced_at']) : '—').PHP_EOL);
        $this->stdout('Last error: '.($status['error'] ?? '—').PHP_EOL);

        return ExitCode::OK;
    }
}
