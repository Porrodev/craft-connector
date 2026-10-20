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
        ->and($output)->toContain('turned off')
        ->and(reports())->toBe([]);
});

it('adds the cron line with porro/cron, without the site key, and only once', function () {
    $file = tempnam(sys_get_temp_dir(), 'crontab');
    file_put_contents($file, "0 3 * * * /usr/bin/backup\n");

    [$code, $output] = craft(['porro/cron'], ['PORRO_CRONTAB_FILE' => $file]);
    craft(['porro/cron'], ['PORRO_CRONTAB_FILE' => $file]);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('Added to this server’s crontab')
        ->and(file_get_contents($file))->toStartWith("0 3 * * * /usr/bin/backup\n")
        ->and(substr_count(file_get_contents($file), 'craft porro/report --scheduled'))->toBe(1)
        ->and(reports())->toBe([]);
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
