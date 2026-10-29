<?php

// Each test sends requests as visitors at public addresses; private and
// documentation ranges are always protected, so they'd never be counted.

beforeEach(function () {
    blocking('reset');
});

it('records probes and 404s, and shows the IPs on the Activity page', function () {
    $site = proxiedSite();

    foreach (['/.env', '/.git/config', '/wp-login.php', '/not-here-1', '/not-here-2'] as $path) {
        [$status] = asVisitor("{$site}{$path}", '45.83.64.9');
        expect($status)->toBe(404);
    }

    [$status, $html] = http("{$site}/index.php?p=admin/porro/activity", logIn($site));

    expect($status)->toBe(200)
        ->and($html)->toContain('Suspicious activity')
        ->toContain('45.83.64.9')
        ->toContain('probe 3')
        ->toContain('404 2')
        ->toContain('Block 7 days')
        ->toContain('data-porro-confirm')
        ->toContain('id="porro-confirm"');
});

it('keeps activity through clear-caches, as a deploy runs it', function () {
    $site = proxiedSite();

    foreach (['/.env', '/.git/config', '/wp-login.php'] as $path) {
        asVisitor("{$site}{$path}", '45.83.64.10');
    }

    [$code] = craft(['clear-caches/all']);
    [, $html] = http("{$site}/index.php?p=admin/porro/activity", logIn($site));

    expect($code)->toBe(0)
        ->and($html)->toContain('45.83.64.10')
        ->toContain('probe 3');
});

it('sums up what an IP did, and counts a flagged IP’s other requests and those a block turns away', function () {
    $site = proxiedSite();

    foreach (['/containers/json', '/images/json', '/.env'] as $path) {
        asVisitor("{$site}{$path}", '45.83.64.11');
    }
    // The flag lands with the next flush, then the watch list.
    blocking('flush');
    craft(['cache/flush-all']);
    asVisitor("{$site}/", '45.83.64.11');
    blocking('block', '45.83.64.11');
    [$blocked] = asVisitor("{$site}/info", '45.83.64.11');

    [, $html] = http("{$site}/index.php?p=admin/porro/activity/45.83.64.11", logIn($site));

    expect($blocked)->toBe(403)
        ->and($html)->toContain('requests per hour')
        ->toContain('5 · 1 turned away by the block')
        ->toContain('Exposed services: Docker, MLflow, cloud metadata ×2')
        ->toContain('Secrets: .env, keys, database dumps ×1')
        ->toContain('Paths it asked for')
        ->toContain('/containers/json');
});

it('counts failed sign-ins, and not as form posts', function () {
    $site = proxiedSite();
    $jar = (string) tempnam(sys_get_temp_dir(), 'jar');
    [, $info] = asVisitor("{$site}/index.php?p=actions/users/session-info", '45.83.64.10', jar: $jar);
    $info = json_decode($info, true);

    // Ten an hour flags an IP.
    for ($i = 0; $i < 10; $i++) {
        asVisitor("{$site}/index.php?p=actions/users/login", '45.83.64.10', [
            $info['csrfTokenName'] => $info['csrfTokenValue'],
            'loginName' => "nobody{$i}@example.com",
            'password' => 'wrong',
        ], $jar);
    }

    [, $html] = http("{$site}/index.php?p=admin/porro/activity", logIn($site));

    expect($html)->toContain('45.83.64.10')
        ->toContain('login 10')
        ->not->toContain('>form ');
});

it('says when this copy isn’t recording', function () {
    $site = proxiedSite(['PORRO_ACTIVITY' => 'false']);
    asVisitor("{$site}/.env", '45.83.64.11');

    [, $html] = http("{$site}/index.php?p=admin/porro/activity", logIn($site));

    expect($html)->toContain('Not recording on this copy of the site')
        ->not->toContain('45.83.64.11');
});

it('adds suspicious activity to Site health, with a link to the IPs', function () {
    $site = proxiedSite();
    cache('delete', 'porro:health:panel');

    foreach (['/.env', '/.git/config', '/.env.bak'] as $path) {
        asVisitor("{$site}{$path}", '45.83.64.12');
    }

    [, $html] = http("{$site}/index.php?p=admin/porro", logIn($site));

    expect($html)->toContain('probing for secrets and admin tools')
        ->toContain('1 IP looks suspicious')
        ->toContain('porro/activity');
});

it('shows an IP’s details, and calls out a fake Googlebot', function () {
    $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    $site = proxiedSite(['PORRO_FAKE_REVERSE_DNS' => '45.83.64.13=scan.example.net']);

    // Three probes flag an IP.
    foreach (['/.env', '/.git/config', '/backup.zip'] as $path) {
        $curl = curl_init("{$site}{$path}");
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['X-Forwarded-For: 45.83.64.13']]);
        curl_exec($curl);
    }

    [$status, $html] = http("{$site}/index.php?p=admin/porro/activity/45.83.64.13", logIn($site));

    expect($status)->toBe(200)
        ->and($html)->toContain('Pretending to be Googlebot')
        ->toContain('scan.example.net')
        ->toContain('What it did here')
        ->toContain('/.env')
        ->toContain('Block permanently');
});

it('turns away an address that isn’t an IP', function () {
    $site = proxiedSite();

    [$status] = http("{$site}/index.php?p=admin/porro/activity/not-an-ip", logIn($site));

    expect($status)->toBe(404);
});

it('counts a request as through Cloudflare when a trusted load balancer sits behind it', function () {
    $site = proxiedSite();

    // Cloudflare (173.245.48.1) → the trusted proxy at 127.0.0.1 → Craft.
    foreach (['/.env', '/.git/config', '/wp-login.php'] as $path) {
        http("{$site}{$path}", null, null, ['X-Forwarded-For: 173.245.48.1', 'CF-Connecting-IP: 45.83.64.31']);
    }
    blocking('flush');

    [, $html] = http("{$site}/index.php?p=admin/porro/activity/45.83.64.31", logIn($site));

    expect($html)->toContain('45.83.64.31')
        ->toContain('through Cloudflare')
        ->not->toContain('straight to the server');
});
