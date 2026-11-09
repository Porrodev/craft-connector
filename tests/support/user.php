<?php

use craft\console\Application;
use craft\elements\User;
use craft\enums\CmsEdition;

/*
 * Makes (or resets) a control panel user who isn't an admin and has only
 * the given permissions: `php user.php <username> [permission…]`. Name
 * `--suspended` among them to suspend the account too, or
 * `--email=<address>` for an email other than <username>@example.com.
 * Switches the install to Craft Pro, which more than one user needs.
 */

[, $username] = $argv;
$flags = array_filter(array_slice($argv, 2), fn ($arg) => str_starts_with($arg, '--'));
$permissions = array_values(array_diff(array_slice($argv, 2), $flags));
$suspended = in_array('--suspended', $flags, true);
$email = null;
foreach ($flags as $flag) {
    if (str_starts_with($flag, '--email=')) {
        $email = substr($flag, 8);
    }
}

$root = (string) getenv('CRAFT_PATH');
chdir($root);
require $root.'/bootstrap.php';
/** @var Application $app */
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';

// The install runs as production, where project config is read-only.
$app->getProjectConfig()->readOnly = false;
$app->setEdition(class_exists(CmsEdition::class) ? CmsEdition::Pro : Craft::Pro);
// A request saves project config changes when it ends; this isn't one.
$app->getProjectConfig()->saveModifiedConfigData();

$user = User::find()->username($username)->status(null)->one() ?? new User;
$user->username = $username;
$user->email = $email ?? "{$username}@example.com";
$user->newPassword = 'ci-only-password';
$user->admin = false;
$user->active = true;
$user->pending = false;
$user->suspended = $suspended;

if (! $app->getElements()->saveElement($user, false)) {
    fwrite(STDERR, implode("\n", $user->getFirstErrors()));
    exit(1);
}

$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
