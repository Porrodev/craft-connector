<?php

// nginx mode and Cloudflare: the deny file, the setup page, and what each
// enforcement row says. Cloudflare's API itself is covered in php-core.

beforeEach(function () {
    blocking('reset');
    $this->nginx = sys_get_temp_dir().'/porro-nginx-'.bin2hex(random_bytes(4)).'.conf';
});

afterEach(function () {
    if (is_file($this->nginx)) {
        unlink($this->nginx);
    }
});

it('writes the nginx deny file from porro/blocklist', function () {
    blocking('block', '45.83.67.7');
    blocking('block', '194.26.29.0/24');

    [$code, $output] = craft(['porro/blocklist'], ['PORRO_BLOCK_NGINX' => 'true', 'PORRO_NGINX_PATH' => $this->nginx]);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('Wrote '.$this->nginx)
        ->and((string) file_get_contents($this->nginx))->toContain('deny 45.83.67.7;')->toContain('deny 194.26.29.0/24;');
});

it('writes nothing without nginx mode', function () {
    blocking('block', '45.83.67.7');

    craft(['porro/blocklist'], ['PORRO_NGINX_PATH' => $this->nginx]);

    expect(is_file($this->nginx))->toBeFalse();
});

it('writes nothing outside production', function () {
    if (getenv('CRAFT_MAJOR') === '4') {
        $this->markTestSkipped('Craft 4’s starter project loads .env over the environment, so CRAFT_ENVIRONMENT can’t be changed per run.');
    }

    blocking('block', '45.83.67.7');

    [, $output] = craft(['porro/blocklist'], ['PORRO_BLOCK_NGINX' => 'true', 'PORRO_NGINX_PATH' => $this->nginx, 'CRAFT_ENVIRONMENT' => 'staging']);

    expect(is_file($this->nginx))->toBeFalse()
        ->and($output)->toContain('Not writing the nginx file outside production');
});

it('updates the deny file as soon as someone blocks in the control panel', function () {
    $site = proxiedSite(['PORRO_BLOCK_NGINX' => 'true', 'PORRO_NGINX_PATH' => $this->nginx]);
    $jar = logIn($site);
    [, $page] = http("{$site}/index.php?p=admin/porro/blocking", $jar);

    http("{$site}/index.php?p=admin/porro/blocking", $jar, [
        'CRAFT_CSRF_TOKEN' => csrf($page),
        'action' => 'porro/blocking/block',
        'ip' => '45.83.67.8',
    ]);

    expect(is_file($this->nginx))->toBeTrue()
        ->and((string) file_get_contents($this->nginx))->toContain('deny 45.83.67.8;');
});

it('walks through setting up nginx, with this site’s lines to copy', function () {
    $site = proxiedSite(['PORRO_NGINX_PATH' => $this->nginx]);

    [$status, $html] = http("{$site}/index.php?p=admin/porro/blocking/nginx", logIn($site));

    expect($status)->toBe(200)
        ->and($html)->toContain('1 · Turn on nginx mode')
        ->toContain('PORRO_BLOCK_NGINX=true')
        ->toContain('craft porro/blocklist')
        ->toContain('include '.$this->nginx.';')
        ->toContain('systemctl reload nginx')
        ->toContain('No server has checked in yet');
});

it('says how to set up Cloudflare, and that a token alone isn’t connected', function () {
    $without = proxiedSite();
    $with = proxiedSite(['PORRO_CLOUDFLARE_TOKEN' => 'not-a-real-token']);

    [, $setUp] = http("{$without}/index.php?p=admin/porro/blocking", logIn($without));
    [, $token] = http("{$with}/index.php?p=admin/porro/blocking", logIn($with));
    [$code, $output] = craft(['porro/cloudflare', 'status']);

    expect($setUp)->toContain('PORRO_CLOUDFLARE_TOKEN=your-token-here')
        ->and($token)->toContain('token set, not connected')->toContain('php craft porro/cloudflare connect')
        ->and($code)->not->toBe(0)
        ->and($output)->toContain('Set PORRO_CLOUDFLARE_TOKEN first');
});
