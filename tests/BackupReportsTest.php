<?php

// SENT_KEY in porro/php-core.
const SENT_KEY = 'porro:report:sent';

beforeEach(function () {
    $porro = porro();
    craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);
    cache('delete', SENT_KEY);
    clearReports();
});

it('sends a backup report after a control panel page, once per 15 minutes', function () {
    $jar = logIn(site());

    [$status] = http(site().'/index.php?p=admin/dashboard', $jar);
    http(site().'/index.php?p=admin/entries', $jar);

    $reports = reportsAfterwards(1);

    expect($status)->toBe(200)
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]['trigger'])->toBe('cp');

    // Fifteen minutes on.
    cache('delete', SENT_KEY);
    http(site().'/index.php?p=admin/dashboard', $jar);

    expect(array_column(reportsAfterwards(2), 'trigger'))->toBe(['cp', 'cp']);
});

it('never reports from front-end requests or logged-out visitors', function () {
    http(site().'/');
    http(site().'/index.php?p=admin/login');

    expect(reportsAfterwards(0))->toBe([]);
});

it('stays quiet while cron is reporting', function () {
    craft(['porro/report', '--scheduled']);
    clearReports();

    http(site().'/index.php?p=admin/dashboard', logIn(site()));

    expect(reportsAfterwards(0))->toBe([]);
});

it('queues the backup report when the server cannot finish the response early', function () {
    $site = site(fpm: false);

    http("{$site}/index.php?p=admin/dashboard", logIn($site));

    expect(reportsAfterwards(0))->toBe([]);

    [$code, $output] = craft(['queue/run']);

    expect($code)->toBe(0, $output)
        ->and(array_column(reports(), 'trigger'))->toBe(['cp']);
});

it('reports straight away when Craft or a plugin changed, throttle or not', function () {
    craft(['porro/report', '--scheduled']);
    clearReports();
    // As if a plugin was updated since the last report.
    cache('set', 'porro:versions', 'before-the-update');

    [$code] = craft(['porro/status']);

    expect($code)->toBe(0)
        ->and(array_column(reportsAfterwards(1), 'trigger'))->toBe(['event']);

    // Reported, so the next request has nothing new to tell.
    http(site().'/index.php?p=admin/dashboard', logIn(site()));

    expect(reportsAfterwards(1))->toHaveCount(1);
});

it('sends no backup reports when reporting is turned off', function () {
    $site = site(env: ['PORRO_ENABLED' => 'false']);
    cache('set', 'porro:versions', 'before-the-update');

    http("{$site}/index.php?p=admin/dashboard", logIn($site));
    craft(['porro/status'], ['PORRO_ENABLED' => 'false']);

    expect(reportsAfterwards(0))->toBe([]);
});
