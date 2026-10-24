<?php

// SiteHealth::CACHE_KEY: the panel's result, cached for five minutes.
const PANEL_KEY = 'porro:health:panel';

beforeEach(function () {
    cache('delete', PANEL_KEY);
});

it('shows the site’s health without a Porro account, with the connect command', function () {
    $site = site(env: ['PORRO_ENABLED' => 'false']);
    $jar = logIn($site);

    [$status, $html] = http("{$site}/index.php?p=admin/porro", $jar);

    expect($status)->toBe(200)
        ->and($html)->toContain('Porro Site Health')
        ->toContain('Checked on this site')
        ->toContain('Watch this site from outside too')
        ->toContain('php craft porro/connect &lt;site key&gt;')
        ->not->toContain('From Porro')
        // Craft needs no cron of its own until the site is connected.
        ->not->toContain('Cron isn’t running');
});

it('adds the site’s Porro findings once connected', function () {
    $porro = porro();
    craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);
    cache('delete', findingsKey($porro['url'], $porro['key']));
    $site = site();
    $jar = logIn($site);

    [$status, $html] = http("{$site}/index.php?p=admin/porro", $jar);

    expect($status)->toBe(200)
        ->and($html)->toContain('From Porro')
        ->toContain('SSL certificate expires in 9 days')
        ->toContain('Ubuntu 20.04 past security support')
        ->toContain('https://porro.test/acme/sites/craft')
        ->not->toContain('Watch this site from outside too');
});

it('says when Porro can’t be reached, and shows the local checks', function () {
    $porro = porro();
    craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);
    // Nothing listens on port 9. The site's .env wins over the server's
    // environment, so change it there for this test.
    $env = (string) file_get_contents(craftPath().'/.env');
    file_put_contents(craftPath().'/.env', preg_replace('/^PORRO_URL=.*$/m', 'PORRO_URL=http://127.0.0.1:9', $env));
    cache('delete', findingsKey('http://127.0.0.1:9', $porro['key']));

    try {
        $site = site();
        [, $html] = http("{$site}/index.php?p=admin/porro", logIn($site));
    } finally {
        file_put_contents(craftPath().'/.env', $env);
    }

    expect($html)->toContain('Couldn’t reach Porro just now.')
        ->toContain('Checked on this site')
        ->not->toContain('SSL certificate expires in 9 days');
});

it('keeps guests out', function () {
    [$status, $body] = http(site().'/index.php?p=admin/porro');

    expect($status)->not->toBe(200)
        ->and($body)->not->toContain('Checked on this site');
});

it('checks again on request', function () {
    $site = site(env: ['PORRO_ENABLED' => 'false']);
    $jar = logIn($site);
    [, $page] = http("{$site}/index.php?p=admin/porro", $jar);
    preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $page, $csrf);

    [$status, $body] = http("{$site}/index.php?p=admin/actions/porro/site-health/refresh", $jar, ['CRAFT_CSRF_TOKEN' => $csrf[1] ?? '']);

    expect($csrf)->not->toBeEmpty()
        ->and($status)->toBeIn([200, 302], $body);
});

it('asks Porro again when checking again', function () {
    $porro = porro();
    craft(['porro/connect', $porro['key'], '--url='.$porro['url'], '--no-cron']);
    $site = site();
    $jar = logIn($site);
    // A cached answer from Porro that the stand-in would never give.
    cache('set-json', findingsKey($porro['url'], $porro['key']), json_encode(['value' => ['ok' => true, 'site' => ['url' => null], 'findings' => [['kind' => 'down', 'rank' => 1, 'title' => 'Stale finding']]]]));

    [, $before] = http("{$site}/index.php?p=admin/porro", $jar);
    http("{$site}/index.php?p=admin/actions/porro/site-health/refresh", $jar, ['CRAFT_CSRF_TOKEN' => csrf($before)]);
    [, $after] = http("{$site}/index.php?p=admin/porro", $jar);

    expect($before)->toContain('Stale finding')
        ->and($after)->not->toContain('Stale finding')->toContain('SSL certificate expires in 9 days');
});

it('keeps users without the Porro permission out of the page, check again and the widget', function () {
    cpUser('editor', ['accessCp']);
    $site = site(env: ['PORRO_ENABLED' => 'false']);

    // The admin runs the checks, so there's a result to leak.
    http("{$site}/index.php?p=admin/porro", logIn($site));

    $jar = logIn($site, 'editor');
    [, $dashboard] = http("{$site}/index.php?p=admin/dashboard", $jar);
    [$page, $html] = http("{$site}/index.php?p=admin/porro", $jar);
    [$refresh] = http("{$site}/index.php?p=admin/actions/porro/site-health/refresh", $jar, ['CRAFT_CSRF_TOKEN' => csrf($dashboard)]);
    // Even if Craft lets the widget be added, it shows nothing.
    http("{$site}/index.php?p=admin/actions/dashboard/create-widget", $jar, [
        'CRAFT_CSRF_TOKEN' => csrf($dashboard),
        'type' => 'porro\\craft\\widgets\\SiteHealthWidget',
    ]);
    [, $after] = http("{$site}/index.php?p=admin/dashboard", $jar);

    expect(csrf($dashboard))->not->toBe('')
        ->and($page)->toBe(403, $html)
        ->and($html)->not->toContain('Checked on this site')
        ->and($refresh)->toBe(403)
        ->and($dashboard)->not->toContain('Porro Site Health')
        ->and($after)->not->toContain('Open Porro Site Health')->not->toContain('Not checked yet');
});

it('adds Porro Site Health to the control panel nav', function () {
    $site = site(env: ['PORRO_ENABLED' => 'false']);
    $jar = logIn($site);

    [, $html] = http("{$site}/index.php?p=admin/dashboard", $jar);

    expect($html)->toContain('Porro Site Health')->toContain('/porro');
});

it('shows the last result in the dashboard widget, never running the checks itself', function () {
    $site = site(env: ['PORRO_ENABLED' => 'false']);
    $jar = logIn($site);
    [, $dashboard] = http("{$site}/index.php?p=admin/dashboard", $jar);
    preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"|"csrfTokenValue":"([^"]+)"/', $dashboard, $csrf);

    [$created, $body] = http("{$site}/index.php?p=admin/actions/dashboard/create-widget", $jar, [
        'CRAFT_CSRF_TOKEN' => ($csrf[1] ?? '') ?: ($csrf[2] ?? ''),
        'type' => 'porro\\craft\\widgets\\SiteHealthWidget',
    ]);
    expect($created)->toBe(200, $body);

    cache('delete', PANEL_KEY);
    [, $before] = http("{$site}/index.php?p=admin/dashboard", $jar);

    http("{$site}/index.php?p=admin/porro", $jar);
    [, $after] = http("{$site}/index.php?p=admin/dashboard", $jar);

    expect($before)->toContain('Not checked yet')
        ->and($after)->not->toContain('Not checked yet')->toContain('Open Porro Site Health');
});
