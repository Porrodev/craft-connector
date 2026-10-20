<?php

namespace porro\craft\console\controllers;

use craft\console\Controller;
use porro\craft\Plugin;
use yii\console\ExitCode;

/**
 * Shows what the next report contains: `php craft porro/status`.
 */
class StatusController extends Controller
{
    /** @var bool Print the exact report that would be sent */
    public bool $json = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'json'];
    }

    public function actionIndex(): int
    {
        $report = Plugin::getInstance()->getReporter()->build()->toArray();

        if ($this->json) {
            $this->stdout((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            return ExitCode::OK;
        }

        $craft = collect($report['layers'])->firstWhere('name', 'craft');
        $rows = [
            'Platform' => sprintf('Craft %s %s (%s)', $report['platform']['version'], ucfirst((string) ($craft['data']['edition'] ?? '')), $report['platform']['environment'] ?? '—'),
            'PHP' => $report['php']['version'],
            'Plugins' => (string) count($craft['data']['plugins'] ?? []),
        ];

        foreach ($report['health'] as $key => $value) {
            $rows[ucfirst(str_replace('_', ' ', $key === 'scheduler_last_run' ? 'cron_last_run' : $key))] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '—');
        }

        $rows['composer.lock'] = (string) ($report['lock']['hash'] ?? 'not found');
        $rows['Key set'] = Plugin::key() ? 'yes' : 'no';

        $width = max(array_map('strlen', array_keys($rows)));
        foreach ($rows as $label => $value) {
            $this->stdout(str_pad($label, $width + 2).$value.PHP_EOL);
        }

        return ExitCode::OK;
    }
}
