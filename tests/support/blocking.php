<?php

use craft\console\Application;
use porro\craft\activity\Activity;
use porro\craft\Plugin;

/*
 * Sets up activity and blocking in the Craft install, without running a
 * request: `php blocking.php reset`, `block <cidr> [days]`,
 * `safelist <cidr> <label>`, `flush` (the buffer, as cron would).
 */

[, $action] = $argv;

$root = (string) getenv('CRAFT_PATH');
chdir($root);
require $root.'/bootstrap.php';
/** @var Application $app */
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';

$activity = Plugin::getInstance()->getActivity();

switch ($action) {
    case 'reset':
        // The compiled blocklist lives in Craft's cache; recorded activity
        // in Porro's own table, which clear-caches leaves alone.
        $app->getCache()->flush();
        Activity::store()->flush();
        $activity->buffer()?->take();
        $app->getDb()->createCommand()->delete('{{%porro_blocklist}}')->execute();
        $app->getDb()->createCommand()->delete('{{%porro_safelist}}')->execute();
        break;
    case 'block':
        $days = (int) ($argv[3] ?? 0);
        $activity->blocklist()->block($argv[2], 'Set up by the tests', 'manual', $days ? time() + $days * 86400 : null, 'tests');
        break;
    case 'flush':
        $activity->flush();
        break;
    case 'safelist':
        $activity->blocklist()->safelist($argv[2], $argv[3] ?? 'Tests', null, 'tests');
        break;
}

$activity->changed();
