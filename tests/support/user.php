<?php

use craft\console\Application;
use craft\elements\User;
use craft\enums\CmsEdition;

/*
 * Makes (or resets) a control panel user who isn't an admin and has only
 * the given permissions: `php user.php <username> [permission…]`.
 * Switches the install to Craft Pro, which more than one user needs.
 */

[, $username] = $argv;
$permissions = array_slice($argv, 2);

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
$user->email = "{$username}@example.com";
$user->newPassword = 'ci-only-password';
$user->admin = false;
$user->active = true;
$user->pending = false;

if (! $app->getElements()->saveElement($user, false)) {
    fwrite(STDERR, implode("\n", $user->getFirstErrors()));
    exit(1);
}

$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
