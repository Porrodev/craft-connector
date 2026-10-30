<?php

it('connects, saves the key and sends a signed first report with packages', function () {
    $porro = porro();

    [$code, $output] = craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('Connected. Porro has the first report.')
        ->and(file_get_contents(craftPath().'/.env'))->toContain("PORRO_KEY={$porro['key']}");

    $report = reports()[0];

    expect($report)->toMatchArray(['schema' => 1, 'trigger' => 'connect'])
        ->and($report['connector']['name'])->toBe('porro/craft-connector')
        ->and($report['platform']['name'])->toBe('craft')
        ->and($report['platform']['version'])->toStartWith((string) getenv('CRAFT_MAJOR') ?: '')
        ->and($report['lock']['packages'])->toBeArray()->not->toBeEmpty()
        ->and(array_column($report['lock']['packages'], 'name'))->toContain('craftcms/cms');
});

it('reports Craft facts and never settings values or secrets', function () {
    [$code, $output] = craft(['porro/status', '--json']);
    $report = json_decode($output, true);
    $craft = collect($report['layers'])->firstWhere('name', 'craft');
    $env = (string) file_get_contents(craftPath().'/.env');
    preg_match('/^CRAFT_SECURITY_KEY=(.+)$/m', $env, $securityKey);

    expect($code)->toBe(0)
        ->and($craft['data']['edition'])->toBeIn(['solo', 'team', 'pro'])
        ->and(array_column($craft['data']['plugins'], 'handle'))->toContain('porro')
        ->and($report['health'])->toHaveKeys(['debug', 'scheduler_last_run', 'pending_migrations', 'queue_runs_automatically', 'queue_waiting', 'queue_failed', 'failed_jobs_24h'])
        ->and($report['health']['pending_migrations'])->toBe(0)
        ->and($output)->not->toContain(trim($securityKey[1] ?? 'no-key-found', '"'));
});

it('reports the database engine and version, but never where it is', function () {
    [$code, $output] = craft(['porro/status', '--json']);
    $database = json_decode($output, true)['database'];
    $env = (string) file_get_contents(craftPath().'/.env');
    $setting = fn (string $name) => preg_match('/^CRAFT_DB_'.$name.'=(.*)$/m', $env, $m) ? trim($m[1], " \t\"'") : null;
    $server = strtolower((string) $setting('SERVER'));

    expect($code)->toBe(0)
        ->and(array_keys($database))->toBe(['engine', 'version', 'local', 'managed'])
        // Craft says "mysql" for MariaDB; Porro wants to know which.
        ->and($database['engine'])->toBeIn(($setting('DRIVER') ?? 'pgsql') === 'mysql' ? ['mysql', 'mariadb'] : ['pgsql'])
        ->and($database['version'])->toMatch('/^\d+(\.\d+)+$/')
        ->and($database['local'])->toBeBool();

    // Only assert where the database is when .env says so plainly: CI may
    // reach it on a service host name rather than 127.0.0.1.
    if (in_array($server, ['', 'localhost', '127.0.0.1', '::1'], true) || str_starts_with($server, '/')) {
        expect($database['local'])->toBeTrue()->and($database['managed'])->toBeNull();
    }

    $block = (string) json_encode($database);

    foreach (array_filter([$server, $setting('DATABASE'), $setting('USER'), $setting('PASSWORD')], fn ($value) => strlen((string) $value) > 3) as $secret) {
        expect($block)->not->toContain($secret);
    }
});

it('tells Porro when cron sent the report', function () {
    $porro = porro();
    craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);
    clearReports();

    [$code] = craft(['porro/report', '--scheduled']);
    [$manual] = craft(['porro/report']);

    [$scheduled, $byHand] = reports();

    expect([$code, $manual])->toBe([0, 0])
        ->and($scheduled['trigger'])->toBe('schedule')
        ->and($scheduled['health']['scheduler_last_run'])->not->toBeNull()
        ->and($byHand['trigger'])->toBe('manual');
});

it('sends packages only when the lock file changed or Porro asks', function () {
    $porro = porro();
    craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);
    clearReports();

    craft(['porro/report']);
    touch($porro['captures'].'/need-lock');
    craft(['porro/report']);
    unlink($porro['captures'].'/need-lock');

    $packages = array_map(fn ($r) => is_array($r['lock']['packages'] ?? null), reports());

    expect($packages)->toBe([false, false, true]);
});

it('prints the cron line when it does not touch the crontab', function () {
    $porro = porro();

    [, $output] = craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--interactive=0']);

    expect($output)->toContain('One step left: add this cron line')
        ->toContain('*/15 * * * * cd '.escapeshellarg(craftPath()))
        ->toContain('craft porro/report --scheduled > /dev/null 2>&1 # porro');
});

it('refuses a key that is not a Porro site key', function () {
    [$code, $output] = craft(['porro/connect', 'lk_site_nope', '--no-cron']);

    expect($code)->not->toBe(0)
        ->and(reports())->toBe([]);
});

it('sends nothing when reporting is turned off', function () {
    [$code, $output] = craft(['porro/report'], ['PORRO_ENABLED' => 'false']);

    expect($code)->toBe(0)
        ->and($output)->toContain('off: PORRO_ENABLED=false')
        ->and(reports())->toBe([]);
});

it('stays quiet outside production unless PORRO_ENABLED says otherwise', function () {
    // Craft 4's starter loads .env over the process environment, so change it there.
    $env = craftPath().'/.env';
    $original = (string) file_get_contents($env);
    $environment = function (string $name) use ($env, $original) {
        file_put_contents($env, preg_replace('/^CRAFT_ENVIRONMENT=.*$/m', "CRAFT_ENVIRONMENT={$name}", $original));
    };

    try {
        $environment('dev');
        [$code, $output] = craft(['porro/report']);

        expect($code)->toBe(0)
            ->and($output)->toContain('off: not production (CRAFT_ENVIRONMENT=dev)')
            ->and(reports())->toBe([]);

        $environment('staging');
        [, $output] = craft(['porro/status']);

        expect($output)->toMatch('/Reporting\s+off: not production \(CRAFT_ENVIRONMENT=staging\)/');

        [$code] = craft(['porro/report'], ['PORRO_ENABLED' => 'true']);

        expect($code)->toBe(0)
            ->and(reports())->toHaveCount(1);
    } finally {
        file_put_contents($env, $original);
    }

    clearReports();
    [, $output] = craft(['porro/report'], ['CRAFT_DEV_MODE' => 'true', 'DEV_MODE' => 'true']);

    expect($output)->toContain('off: devMode is on')
        ->and(reports())->toBe([]);
});

it('adds the cron line with porro/cron, without the site key, and only once', function () {
    $file = tempnam(sys_get_temp_dir(), 'crontab');
    file_put_contents($file, "0 3 * * * /usr/bin/backup\n");

    // Earlier tests saved a key to .env; take it out for this one.
    [$code, $output] = withDotEnv(['PORRO_KEY' => null], function () use ($file) {
        $first = craft(['porro/cron'], ['PORRO_CRONTAB_FILE' => $file]);
        craft(['porro/cron'], ['PORRO_CRONTAB_FILE' => $file]);

        return $first;
    });

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('Added to this server’s crontab')
        ->and($output)->toContain('No PORRO_KEY set, so no report sent')
        ->and(file_get_contents($file))->toStartWith("0 3 * * * /usr/bin/backup\n")
        ->and(substr_count(file_get_contents($file), 'craft porro/report --scheduled'))->toBe(1)
        ->and(reports())->toBe([]);
});

it('sends a report from porro/cron once the line is added, so Porro stops saying cron is missing', function () {
    $porro = porro();
    $file = tempnam(sys_get_temp_dir(), 'crontab');

    [$code, $output] = withDotEnv(['PORRO_KEY' => $porro['key'], 'PORRO_URL' => $porro['url']], fn () => craft(['porro/cron'], ['PORRO_CRONTAB_FILE' => $file]));

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('Added to this server’s crontab')
        ->and($output)->toContain('Told Porro cron runs porro/report')
        ->and(reports())->toHaveCount(1)
        ->and(reports()[0]['trigger'])->toBe('connect')
        ->and(reports()[0]['health'])->toMatchArray(['scheduler_in_crontab' => true]);
});

it('sends a report from porro/cron when cron already runs porro/report', function () {
    $porro = porro();
    $file = tempnam(sys_get_temp_dir(), 'crontab');
    file_put_contents($file, '*/15 * * * * cd '.craftPath().' && php craft porro/report --scheduled'."\n");

    [$code, $output] = withDotEnv(['PORRO_KEY' => $porro['key'], 'PORRO_URL' => $porro['url']], fn () => craft(['porro/cron'], ['PORRO_CRONTAB_FILE' => $file]));

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('Found porro/report in this server’s cron')
        ->and(reports()[0]['health'])->toMatchArray(['scheduler_in_crontab' => true]);
});

it('keeps porro/cron working when Porro can not be reached', function () {
    $file = tempnam(sys_get_temp_dir(), 'crontab');

    // Craft 4's .env wins over the process environment, so change it there.
    [$code, $output] = withDotEnv(['PORRO_KEY' => porro()['key'], 'PORRO_URL' => 'http://127.0.0.1:'.freePort()], fn () => craft(['porro/cron'], ['PORRO_CRONTAB_FILE' => $file]));

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('Added to this server’s crontab')
        ->and($output)->toContain('Couldn’t send a report to Porro');
});

it('adds cron from connect when asked to', function () {
    $porro = porro();
    $file = tempnam(sys_get_temp_dir(), 'crontab');

    [$code] = craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--cron'], ['PORRO_CRONTAB_FILE' => $file]);

    expect($code)->toBe(0)
        ->and(file_get_contents($file))->toContain('*/15 * * * * cd '.escapeshellarg(craftPath()));
});

it('tells Porro whether cron already runs porro/report', function () {
    $porro = porro();
    $file = tempnam(sys_get_temp_dir(), 'crontab');
    file_put_contents($file, '*/15 * * * * cd '.craftPath().' && php craft porro/report --scheduled'."\n");

    [$code] = craft(['porro/connect', $porro['key'], '--url='.$porro['url']], ['PORRO_CRONTAB_FILE' => $file]);

    expect($code)->toBe(0)
        ->and(reports()[0]['health'])->toMatchArray(['scheduler_in_crontab' => true, 'report_interval' => 15]);
});

/**
 * Runs $run with these values in the Craft install's .env (null removes
 * one), then puts the file back.
 *
 * @param  array<string, string|null>  $values
 */
function withDotEnv(array $values, callable $run): mixed
{
    $path = craftPath().'/.env';
    $original = (string) file_get_contents($path);
    $env = $original;

    foreach ($values as $name => $value) {
        $env = (string) preg_replace("/^{$name}=.*\n?/m", '', $env);
        $env = $value === null ? $env : rtrim($env, "\n")."\n{$name}={$value}\n";
    }

    file_put_contents($path, $env);

    try {
        return $run();
    } finally {
        file_put_contents($path, $original);
    }
}
