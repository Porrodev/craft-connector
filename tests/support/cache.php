<?php

use craft\console\Application;

/*
 * Reads or changes one value in the Craft install's cache, without
 * running a request: `php cache.php set|delete <key> [value]`.
 */

[, $action, $key] = $argv;

$root = (string) getenv('CRAFT_PATH');
chdir($root);
require $root.'/bootstrap.php';
/** @var Application $app */
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';

match ($action) {
    'set' => $app->getCache()->set($key, $argv[3], 0),
    'delete' => $app->getCache()->delete($key),
};
