<?php

use craft\console\Application;
use craft\db\Query;
use craft\services\Security;
use Porro\Core\SiteKey;
use porro\craft\activity\Activity;
use porro\craft\migrations\m261116_000000_porro_settings;
use porro\craft\Plugin;
use porro\craft\SiteKeys;

/*
 * What "Connect to Porro" keeps in the Craft install, without running a
 * request. Prints JSON.
 *
 * `php connect.php key`: the key made in the control panel, as SiteKeys
 *     reads it: {token, stored, row}. The tests are the only thing that
 *     ever prints the token.
 * `store <workspace> [host]`: keeps a new key, as a finished connection
 *     would, and prints its public half.
 * `forget`: removes the stored key.
 * `waiting`: every row in Porro's own cache table, as the database holds
 *     it: {id: data}.
 * `expire <id>…`: runs those rows out of time.
 * `drop-table` / `create-table`: porro_settings, before and after its
 *     migration.
 * `permissions`: the labels of the permissions under "Porro".
 * `seal-fails <text>`: what SiteKeys::seal() throws when encrypting
 *     fails, with every string a trace of it would show.
 */

[, $action] = $argv;

$root = (string) getenv('CRAFT_PATH');
chdir($root);
require $root.'/bootstrap.php';
/** @var Application $app */
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';

$keys = Plugin::keys();
$db = $app->getDb();
// Postgres hands a bytea column back as a stream.
$text = fn (mixed $value): string => is_resource($value) ? (string) stream_get_contents($value) : (string) $value;

switch ($action) {
    case 'key':
        echo json_encode([
            'token' => $keys->token(),
            'stored' => $keys->stored(),
            'row' => $text((new Query)->select(['value'])->from(SiteKeys::TABLE)->where(['key' => 'site_key'])->scalar()),
        ]);
        break;
    case 'store':
        $key = SiteKey::generate()->withSite('01jabcdefghjkmnpqrstvwxyz0');
        $keys->store($key, $argv[2], $argv[3] ?? null);
        echo json_encode(['public_key' => $key->publicKey()]);
        break;
    case 'forget':
        $keys->forget();
        break;
    case 'waiting':
        $rows = [];
        foreach ((new Query)->select(['id', 'data'])->from(Activity::CACHE_TABLE)->all() as $row) {
            $rows[$row['id']] = base64_encode($text($row['data']));
        }
        echo json_encode($rows);
        break;
    case 'expire':
        $db->createCommand()->update(Activity::CACHE_TABLE, ['expire' => 1], ['id' => array_slice($argv, 2)], [], false)->execute();
        break;
    case 'drop-table':
        $db->createCommand()->dropTableIfExists(SiteKeys::TABLE)->execute();
        break;
    case 'create-table':
        $migration = new m261116_000000_porro_settings;
        ob_start();
        $migration->createTables($migration);
        ob_end_clean();
        break;
    case 'seal-fails':
        // Traces keep arguments, as most servers have it.
        ini_set('zend.exception_ignore_args', '0');
        $app->set('security', new class extends Security
        {
            public function encryptByKey($data, $inputKey = null, $info = null): string
            {
                throw new RuntimeException('Could not encrypt '.$data);
            }
        });

        try {
            SiteKeys::seal($argv[2]);
        } catch (Throwable $e) {
            $arguments = [];
            $trace = array_column($e->getTrace(), 'args');
            array_walk_recursive($trace, function ($argument) use (&$arguments) {
                if (is_string($argument)) {
                    $arguments[] = $argument;
                }
            });
            echo json_encode(['class' => $e::class, 'previous' => $e->getPrevious() !== null, 'says' => $e->getMessage().' '.implode(' ', $arguments)]);
        }
        break;
    case 'permissions':
        $labels = [];
        foreach ($app->getUserPermissions()->getAllPermissions() as $group) {
            if (($group['heading'] ?? null) === 'Porro') {
                $labels = array_map(fn (array $permission) => $permission['label'], $group['permissions']);
            }
        }
        echo json_encode($labels);
        break;
}
