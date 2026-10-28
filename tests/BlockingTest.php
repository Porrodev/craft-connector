<?php

beforeEach(function () {
    blocking('reset');
});

it('turns blocked IPs and ranges away with a 403, front end and control panel', function () {
    blocking('block', '45.83.65.7');
    blocking('block', '194.26.29.0/24');
    $site = proxiedSite();

    expect(asVisitor("{$site}/", '45.83.65.7'))->toBe([403, 'Forbidden'])
        ->and(asVisitor("{$site}/index.php?p=admin/login", '194.26.29.77'))->toBe([403, 'Forbidden'])
        ->and(asVisitor("{$site}/", '45.83.65.8')[0])->toBe(200);
});

it('ignores X-Forwarded-For until trustedHosts names the proxies', function () {
    blocking('block', '45.83.65.7');
    // Craft's default trustedHosts trusts anyone's forwarded header.
    $site = site(env: ['PORRO_ENABLED' => 'false']);

    // Sent straight from 127.0.0.1, a private address that's never blocked.
    expect(asVisitor("{$site}/", '45.83.65.7')[0])->toBe(200);
});

it('lets a safelisted IP through, even inside a blocked range', function () {
    blocking('block', '194.26.29.0/24');
    blocking('safelist', '194.26.29.5', 'Office');
    $site = proxiedSite();

    expect(asVisitor("{$site}/", '194.26.29.5')[0])->toBe(200)
        ->and(asVisitor("{$site}/", '194.26.29.6')[0])->toBe(403);
});

it('only records, and never blocks, with PORRO_BLOCK_APP=false', function () {
    blocking('block', '45.83.65.7');
    $site = proxiedSite(['PORRO_BLOCK_APP' => 'false']);

    expect(asVisitor("{$site}/", '45.83.65.7')[0])->toBe(200);
});

it('blocks and unblocks from the control panel', function () {
    $site = proxiedSite();
    $jar = logIn($site);
    [, $page] = http("{$site}/index.php?p=admin/porro/blocking", $jar);

    http("{$site}/index.php?p=admin/porro/blocking", $jar, [
        'CRAFT_CSRF_TOKEN' => csrf($page),
        'action' => 'porro/blocking/block',
        'ip' => '45.83.65.20',
        'reason' => 'Probing for .env',
        'days' => '7',
    ]);

    [, $html] = http("{$site}/index.php?p=admin/porro/blocking", $jar);

    expect($html)->toContain('45.83.65.20')
        ->toContain('Probing for .env')
        ->toContain('in 7 days')
        ->and(asVisitor("{$site}/", '45.83.65.20')[0])->toBe(403);

    http("{$site}/index.php?p=admin/porro/blocking", $jar, [
        'CRAFT_CSRF_TOKEN' => csrf($html),
        'action' => 'porro/blocking/unblock',
        'ip' => '45.83.65.20',
    ]);

    expect(asVisitor("{$site}/", '45.83.65.20')[0])->toBe(200);
});

it('refuses to block an always-protected address, and says why', function () {
    $site = proxiedSite();
    $jar = logIn($site);
    [, $page] = http("{$site}/index.php?p=admin/porro/blocking", $jar);

    // 173.245.48.1 is Cloudflare's.
    http("{$site}/index.php?p=admin/porro/blocking", $jar, [
        'CRAFT_CSRF_TOKEN' => csrf($page),
        'action' => 'porro/blocking/block',
        'ip' => '173.245.48.1',
    ]);

    [, $html] = http("{$site}/index.php?p=admin/porro/blocking", $jar);

    expect($html)->toContain('Cloudflare')
        ->toContain('Nothing is blocked.');
});

it('lets only users with the Manage blocking permission change the lists', function () {
    cpUser('viewer', ['accessCp', 'accessPlugin-porro']);
    $site = proxiedSite();
    $jar = logIn($site, 'viewer');
    [$status, $page] = http("{$site}/index.php?p=admin/porro/blocking", $jar);

    expect($status)->toBe(200)->and($page)->not->toContain('porro/blocking/block');

    [$status] = http("{$site}/index.php?p=admin/porro/blocking", $jar, [
        'CRAFT_CSRF_TOKEN' => csrf($page),
        'action' => 'porro/blocking/block',
        'ip' => '45.83.65.21',
    ]);

    expect($status)->toBe(403)
        ->and(asVisitor("{$site}/", '45.83.65.21')[0])->toBe(200);
});

it('searches and filters the whole blocklist', function () {
    blocking('block', '45.83.65.30');
    blocking('block', '45.83.66.0/24', '3');
    $site = proxiedSite();
    $jar = logIn($site);

    [, $byIp] = http("{$site}/index.php?p=admin/porro/blocking/blocklist&q=45.83.66.9", $jar);
    [, $expiring] = http("{$site}/index.php?p=admin/porro/blocking/blocklist&filter=expiring", $jar);
    [$status, $junk] = http("{$site}/index.php?p=admin/porro/blocking/blocklist&q[]=x", $jar);

    expect($byIp)->toContain('45.83.66.0/24')->not->toContain('45.83.65.30')
        ->and($expiring)->toContain('45.83.66.0/24')->not->toContain('45.83.65.30')
        ->and($status)->toBe(200)
        ->and($junk)->toContain('45.83.65.30')->toContain('45.83.66.0/24');
});

it('lists what’s always protected, with the switch for each optional list', function () {
    $site = proxiedSite(['PORRO_PROTECT_CRAWLERS' => 'false']);

    [, $html] = http("{$site}/index.php?p=admin/porro/blocking", logIn($site));

    expect($html)->toContain('Always protected')
        ->toContain('Cloudflare')
        ->toContain('Switched off with PORRO_PROTECT_CRAWLERS=false');
});
