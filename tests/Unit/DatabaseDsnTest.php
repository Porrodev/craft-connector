<?php

use porro\craft\DatabaseDsn;

it('reads the socket or host from a DSN', function (string $dsn, ?string $host) {
    expect(DatabaseDsn::host($dsn))->toBe($host);
})->with([
    'a MySQL host' => ['mysql:host=db.example.com;port=3306;dbname=craft', 'db.example.com'],
    'a MySQL socket' => ['mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=craft', '/var/run/mysqld/mysqld.sock'],
    'a socket wins over a host' => ['mysql:host=127.0.0.1;unix_socket=/tmp/mysql.sock;dbname=craft', '/tmp/mysql.sock'],
    'an empty socket falls back to the host' => ['mysql:unix_socket=;host=db;dbname=craft', 'db'],
    'a Postgres socket directory' => ['pgsql:host=/var/run/postgresql;dbname=craft', '/var/run/postgresql'],
    'no host' => ['mysql:dbname=craft', ''],
    'an empty host' => ['pgsql:host=;dbname=craft', ''],
    'mixed case keys' => ['MySQL:Host=DB.Example.com;DBName=craft', 'DB.Example.com'],
    'spaces around pairs' => [' pgsql: host = db ; dbname = craft ', 'db'],
    'a key without a value' => ['mysql:host;dbname=craft', ''],
    'stray separators' => ['mysql:;;host=db;;', 'db'],
    'an IPv6 host' => ['pgsql:host=[::1];port=5432', '[::1]'],
    'an empty string' => ['', null],
    'no driver prefix' => ['host=db;dbname=craft', null],
    'just a driver' => ['mysql:', ''],
]);

it('describes the database from the DSN', function (string $driver, string $version, string $dsn, array $expected) {
    expect(DatabaseDsn::describe($driver, $version, $dsn))->toBe($expected);
})->with([
    'a MySQL socket' => ['mysql', '8.0.36', 'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=craft', ['engine' => 'mysql', 'version' => '8.0.36', 'local' => true, 'managed' => null]],
    'MariaDB on the default host' => ['mysql', '5.5.5-10.11.6-MariaDB-0ubuntu0.24.04.1', 'mysql:dbname=craft', ['engine' => 'mariadb', 'version' => '10.11.6', 'local' => true, 'managed' => null]],
    'a Postgres socket directory' => ['pgsql', '16.4', 'pgsql:host=/var/run/postgresql;dbname=craft', ['engine' => 'pgsql', 'version' => '16.4', 'local' => true, 'managed' => null]],
    'a CI service host' => ['pgsql', '16.4', 'pgsql:host=postgres;port=5432;dbname=craft', ['engine' => 'pgsql', 'version' => '16.4', 'local' => false, 'managed' => null]],
    'a managed host in capitals' => ['pgsql', '16.4', 'PGSQL:HOST=Porro.ABC123.eu-west-2.RDS.amazonaws.com;DBNAME=craft', ['engine' => 'pgsql', 'version' => '16.4', 'local' => false, 'managed' => 'Amazon RDS']],
    'a malformed DSN' => ['mysql', '8.0.36', 'not a dsn', ['engine' => 'mysql', 'version' => '8.0.36', 'local' => null, 'managed' => null]],
]);

it('never sends the host, database name or credentials', function (string $driver, string $dsn) {
    $json = (string) json_encode(DatabaseDsn::describe($driver, '8.0.36', $dsn));

    expect($json)->not->toContain('secret')
        ->not->toContain('alice')
        ->not->toContain('hunter2')
        ->not->toContain('clientdb');
})->with([
    'host and port' => ['mysql', 'mysql:host=secret-db.internal;port=3306;dbname=clientdb'],
    'Postgres with credentials in the DSN' => ['pgsql', 'pgsql:host=secret-db.internal;dbname=clientdb;user=alice;password=hunter2'],
    'a socket' => ['mysql', 'mysql:unix_socket=/srv/secret/mysqld.sock;dbname=clientdb;user=alice;password=hunter2'],
    'a managed host' => ['pgsql', 'pgsql:host=secret.c9akciq32.eu-west-2.rds.amazonaws.com;dbname=clientdb;user=alice;password=hunter2'],
    'malformed' => ['mysql', 'secret-db.internal;dbname=clientdb;user=alice;password=hunter2'],
]);
