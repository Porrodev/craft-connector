<?php

namespace porro\craft;

use Porro\Core\Database;

/**
 * The report's database block from Craft's DSN. The DSN is read only for
 * the socket or host, to tell a local database from a remote or managed
 * one; the host, database name and any credentials in it never leave
 * the site.
 */
final class DatabaseDsn
{
    /**
     * @return array{engine: string, version: string|null, local: bool|null, managed: string|null}|null
     */
    public static function describe(string $driver, ?string $serverVersion, string $dsn): ?array
    {
        // Craft says "mysql" for MariaDB too; describe() tells them apart by the version.
        return Database::describe($driver, $serverVersion, self::host($dsn));
    }

    /**
     * The socket or host from a DSN such as "mysql:host=db;port=3306;dbname=craft"
     * or "pgsql:host=/var/run/postgresql;dbname=craft". '' when it names
     * neither, which means the driver's local default; null when it isn't
     * a DSN at all, so whether the database is local stays unknown.
     */
    public static function host(string $dsn): ?string
    {
        if (! preg_match('/^\s*[a-z][a-z0-9]*:(.*)$/is', $dsn, $m)) {
            return null;
        }

        $params = [];

        foreach (explode(';', $m[1]) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[strtolower(trim($key))] ??= trim($value);
        }

        return ($params['unix_socket'] ?? '') !== '' ? $params['unix_socket'] : ($params['host'] ?? '');
    }
}
