<?php

use Porro\Core\ReportThrottle;

/*
 * "Disconnect" on Site health and `php craft porro/disconnect`: Porro is
 * told, and the key made in the control panel is deleted whatever it
 * answers. And the other way round: a report Porro turns away deletes the
 * key too. The stand-in Porro saves each disconnect it's sent.
 */

beforeEach(function () {
    // The live site, with no key in its environment and nothing stored.
    $this->env = (string) file_get_contents(craftPath().'/.env');
    file_put_contents(craftPath().'/.env', envWith($this->env, ['PORRO_KEY' => null, 'PORRO_ENABLED' => null, 'PORRO_URL' => porro()['url'], 'CRAFT_DEFAULT_RATE_LIMIT' => '0']));
    connectStore('forget');
    connectStore('forget-turned-away');
    cache('delete', 'porro:health:panel');
    // As if a report had just gone, so no backup report rides on these pages.
    cache('set', ReportThrottle::KEY, (string) time());
});

afterEach(function () {
    if (! isset($this->env)) {
        return;
    }

    file_put_contents(craftPath().'/.env', $this->env);
    cache('delete', ReportThrottle::KEY);
    connectStore('forget');
    connectStore('forget-turned-away');
});

/**
 * Presses the dialog's red Disconnect button.
 *
 * @return array{status: int, location: string, body: string}
 */
function pressDisconnect(string $site, string $jar): array
{
    $token = csrf(browse("{$site}/index.php?p=admin/dashboard", $jar)['body']);

    expect($token)->not->toBe('');

    return browse("{$site}/index.php?p=admin/actions/porro/connect/disconnect", $jar, ['CRAFT_CSRF_TOKEN' => $token]);
}

/**
 * The disconnects the stand-in Porro was sent.
 *
 * @return list<array{body: string, site: string|null}>
 */
function disconnectCalls(): array
{
    $file = porro()['captures'].'/disconnects';

    return array_map(fn ($line) => json_decode($line, true), is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : []);
}

/**
 * What the stand-in Porro answers a disconnect, or a report, with.
 *
 * @param  array<string, mixed>  $body
 */
function porroAnswersWith(string $what, int $status, array $body): void
{
    file_put_contents(porro()['captures']."/{$what}-answer", json_encode(['status' => $status, 'body' => $body]));
}

/**
 * A key kept as "Connect to Porro" leaves it, which the stand-in Porro
 * has never heard of: every request signed with it is turned away.
 */
function connectedWithAKeyPorroDropped(string $workspace = 'Marlowe Studio'): void
{
    connectStore('store', $workspace, 'harbour-arts.org');
}

/**
 * A site key the stand-in Porro has never heard of, for PORRO_KEY.
 */
function unknownKey(): string
{
    return 'porro_01jabcdefghjkmnpqrstvwxyz0_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

describe('what Site health shows', function () {
    it('offers Disconnect, and what it will ask, to someone who may connect', function () {
        connectedFromPanel();
        $site = site();

        $html = siteHealth($site, logIn($site));

        expect($html)
            ->toContain('Connected to Marlowe Studio')
            ->toContain('data-porro-disconnect')
            ->toContain('Open in Porro')
            // The dialog, with the form that does it.
            ->toContain('Disconnect harbour-arts.org from Porro?')
            ->toContain('This site stops sending reports to Marlowe Studio, and its key is deleted from this site.')
            ->toContain('Stops')
            ->toContain('Hourly reports of what is installed here, and signing in to this site from Porro.')
            ->toContain('Carries on')
            ->toContain('The checks on this page. They run on this site and need no account.')
            ->toContain('In Porro')
            ->toContain('Marlowe Studio keeps the site and its history. Uptime, SSL and domain checks go on from outside until someone removes the site there.')
            ->toContain('You can connect again at any time.')
            ->toContain('name="action" value="porro/connect/disconnect"')
            ->toContain('Cancel')
            ->toMatch('/<button type="submit" class="btn porro-danger">Disconnect<\/button>/');
    });

    it('keeps Disconnect from someone who can only see the page', function () {
        connectedFromPanel();
        cpUser('viewer', ['accessCp', 'accessPlugin-porro']);
        $site = site();

        $html = siteHealth($site, logIn($site, 'viewer'));

        expect($html)
            ->toContain('Connected to Marlowe Studio')
            ->toContain('Open in Porro')
            ->not->toContain('data-porro-disconnect')
            ->not->toContain('porro/connect/disconnect')
            ->not->toContain('from Porro?');
    });

    it('says how to disconnect a key in the environment, with no button', function () {
        $porro = porro();
        cache('delete', findingsKey($porro['url'], $porro['key']));

        $html = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => $porro['key']]), function () {
            $site = site();

            return siteHealth($site, logIn($site));
        });

        expect($html)
            ->toContain('To disconnect, remove PORRO_KEY from the environment and deploy. The control panel can’t change it.')
            ->toContain('From Porro')
            ->not->toContain('data-porro-disconnect')
            ->not->toContain('porro/connect/disconnect');
    });

    it('escapes the workspace and host in the dialog', function () {
        connectedFromPanel('<b>Marlowe</b>', '<i>harbour</i>');
        $site = site();

        $html = siteHealth($site, logIn($site));

        expect($html)
            ->toContain('Disconnect &lt;i&gt;harbour&lt;/i&gt; from Porro?')
            ->toContain('&lt;b&gt;Marlowe&lt;/b&gt; keeps the site and its history.')
            ->not->toContain('<b>Marlowe</b>')
            ->not->toContain('<i>harbour</i>');
    });
});

describe('disconnecting from the control panel', function () {
    it('tells Porro, deletes the key and says so once', function () {
        connectedFromPanel();
        $site = site();
        $jar = logIn($site);
        siteHealth($site, $jar);

        $pressed = pressDisconnect($site, $jar);
        $calls = disconnectCalls();

        expect($pressed['status'])->toBe(302, $pressed['body'])
            ->and($pressed['location'])->toEndWith('/admin/porro')
            ->and($calls)->toHaveCount(1)
            // Signed as the site, and nothing about who pressed it.
            ->and($calls[0]['site'])->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0')
            ->and($calls[0]['body'])->toBe('{}')
            ->and(connectStore('key'))->toMatchArray(['token' => null, 'stored' => null, 'row' => '']);

        $html = siteHealth($site, $jar);

        expect($html)
            ->toContain('Disconnected from Marlowe Studio')
            ->toContain('This site no longer sends reports, and its key has been deleted. Marlowe Studio still has the site in Porro; someone in that workspace can remove it there.')
            // Checked again, as a site that isn't connected.
            ->toContain('Not connected to Porro')
            ->toContain('value="porro/connect/start"')
            ->not->toContain('From Porro')
            ->not->toContain('data-porro-disconnect');

        // The next view only.
        expect(siteHealth($site, $jar))
            ->not->toContain('Disconnected from Marlowe Studio')
            ->toContain('Not connected to Porro');

        [, $status] = craft(['porro/status']);

        expect($status)->toMatch('/Key set\s+no/');
    });

    it('deletes the key when Porro doesn’t answer, and says Porro wasn’t told', function () {
        connectedFromPanel();
        porroAnswersWith('disconnect', 503, ['ok' => false, 'error' => 'Service unavailable.']);
        $site = site();
        $jar = logIn($site);

        $pressed = pressDisconnect($site, $jar);
        $html = siteHealth($site, $jar);

        expect($pressed['location'])->toEndWith('/admin/porro')
            ->and(connectStore('key')['token'])->toBeNull()
            ->and($html)
            ->toContain('Disconnected on this site')
            ->toContain('The key has been deleted and reports have stopped. Porro didn’t answer, so it hasn’t been told: Marlowe Studio will see this site as not reporting, not as disconnected.')
            ->toContain('Not connected to Porro')
            ->not->toContain('Disconnected from Marlowe Studio');
    });

    it('needs the connect permission', function () {
        connectedFromPanel();
        cpUser('viewer', ['accessCp', 'accessPlugin-porro']);
        cpUser('blocker', ['accessCp', 'accessPlugin-porro', 'porro-manageBlocking']);
        cpUser('connector', ['accessCp', 'accessPlugin-porro', 'porro-connectSite']);
        $site = site();

        foreach (['viewer', 'blocker'] as $username) {
            expect(pressDisconnect($site, logIn($site, $username))['status'])->toBe(403);
        }

        expect(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['token'])->not->toBeNull();

        $jar = logIn($site, 'connector');

        expect(siteHealth($site, $jar))->toContain('data-porro-disconnect')
            ->and(pressDisconnect($site, $jar)['location'])->toEndWith('/admin/porro')
            ->and(disconnectCalls())->toHaveCount(1)
            ->and(connectStore('key')['token'])->toBeNull();
    });

    it('sends guests to sign in', function () {
        connectedFromPanel();
        $site = site();
        $jar = (string) tempnam(sys_get_temp_dir(), 'jar');
        $token = json_decode(http("{$site}/index.php?p=actions/users/session-info", $jar)[1], true)['csrfTokenValue'];

        $pressed = browse("{$site}/index.php?p=admin/actions/porro/connect/disconnect", $jar, ['CRAFT_CSRF_TOKEN' => $token]);

        expect($pressed['location'])->toContain('login')
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['token'])->not->toBeNull();
    });

    it('only disconnects with a POST that carries Craft’s CSRF token', function () {
        connectedFromPanel();
        $site = site();
        $jar = logIn($site);

        $without = browse("{$site}/index.php?p=admin/actions/porro/connect/disconnect", $jar, []);
        $get = browse("{$site}/index.php?p=admin/actions/porro/connect/disconnect", $jar);

        expect($without['status'])->toBe(400)
            // Craft 5 says the method isn't allowed; Craft 4 calls it a bad request.
            ->and($get['status'])->toBeIn([400, 405])
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['token'])->not->toBeNull();
    });

    it('refuses on a copy that isn’t the live site, and offers no button there', function (array $set) {
        connectedFromPanel();

        // A copy of the live database holds the live site's key.
        [$before, $pressed, $after] = withEnvFile(fn ($env) => envWith($env, $set), function () {
            $site = site();
            $jar = logIn($site);

            return [siteHealth($site, $jar), pressDisconnect($site, $jar), siteHealth($site, $jar)];
        });

        expect($before)->toContain('This is a development copy')
            ->not->toContain('data-porro-disconnect')
            ->not->toContain('porro/connect/disconnect')
            ->and($pressed['location'])->toEndWith('/admin/porro')
            ->and($after)->toContain('This copy isn’t the live site, so it can’t disconnect: that would switch off the live site’s key in Porro too. Disconnect from the live site’s control panel.')
            ->not->toContain('Disconnected')
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['stored'])->toMatchArray(['workspace' => 'Marlowe Studio']);
    })->with([
        'staging' => [['CRAFT_ENVIRONMENT' => 'staging']],
        'staging, told to report' => [['CRAFT_ENVIRONMENT' => 'staging', 'PORRO_ENABLED' => 'true']],
        'devMode' => [['CRAFT_DEV_MODE' => 'true']],
    ]);

    it('leaves a key in the environment alone, and the stored one with it', function () {
        connectedFromPanel();
        $porro = porro();

        [$pressed, $html] = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => $porro['key']]), function () {
            $site = site();
            $jar = logIn($site);

            return [pressDisconnect($site, $jar), siteHealth($site, $jar)];
        });

        expect($pressed['location'])->toEndWith('/admin/porro')
            ->and($html)->toContain('This site is connected with PORRO_KEY in its environment, which the control panel can’t change. Remove PORRO_KEY there and deploy.')
            ->not->toContain('Disconnected')
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['stored'])->not->toBeNull();
    });
});

describe('the disconnect service', function () {
    it('tells Porro, deletes the key and forgets the cached checks', function () {
        connectedFromPanel();
        // What Site health last saw, as a connected site.
        $site = site();
        expect(siteHealth($site, logIn($site)))->toContain('From Porro');

        $result = connectStore('disconnect');

        expect($result)->toBe(['workspace' => 'Marlowe Studio', 'host' => 'harbour-arts.org', 'told' => true])
            ->and(disconnectCalls())->toHaveCount(1)
            ->and(connectStore('key'))->toMatchArray(['token' => null, 'stored' => null, 'row' => ''])
            // Not the five-minute-old result: checked again.
            ->and(siteHealth($site, logIn($site)))->not->toContain('From Porro');
    });

    it('deletes the key and says Porro wasn’t told when Porro can’t be reached', function () {
        connectedFromPanel();

        $result = withEnvFile(fn ($env) => envWith($env, ['PORRO_URL' => 'http://127.0.0.1:'.freePort()]), fn () => connectStore('disconnect'));

        expect($result)->toBe(['workspace' => 'Marlowe Studio', 'host' => 'harbour-arts.org', 'told' => false])
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['token'])->toBeNull();
    });

    it('deletes the key and says Porro wasn’t told when Porro answers with an error', function (int $status, string $error) {
        connectedFromPanel();
        porroAnswersWith('disconnect', $status, ['ok' => false, 'error' => $error]);

        expect(connectStore('disconnect')['told'])->toBeFalse()
            ->and(connectStore('key')['token'])->toBeNull();
    })->with([
        'a server error' => [500, 'Server Error'],
        // The site's clock is out: Porro heard, but didn't act on it.
        'a clock error' => [401, 'Request timestamp is too old or too far ahead.'],
    ]);

    it('counts a key Porro already turns away as told', function () {
        connectedWithAKeyPorroDropped();

        expect(connectStore('disconnect')['told'])->toBeTrue()
            ->and(connectStore('key')['token'])->toBeNull();
    });

    it('refuses a key from the environment, and leaves the stored one', function () {
        connectedFromPanel();

        $result = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => porro()['key']]), fn () => connectStore('disconnect'));

        expect($result)->toBeNull()
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['stored'])->toMatchArray(['workspace' => 'Marlowe Studio']);
    });

    it('does nothing when there’s no key', function () {
        expect(connectStore('disconnect'))->toBeNull()
            ->and(disconnectCalls())->toBeEmpty();
    });
});

describe('a key Porro turns away', function () {
    it('deletes the stored key, remembers who for and when, and says so until it connects again', function () {
        connectedWithAKeyPorroDropped();
        $before = time();

        [$code, $output] = craft(['porro/report']);
        $note = connectStore('turned-away');

        expect($code)->not->toBe(0)
            ->and($output)->toContain('Invalid signature.')
            ->and(connectStore('key'))->toMatchArray(['token' => null, 'stored' => null, 'row' => ''])
            ->and(array_keys($note))->toBe(['workspace', 'at'])
            ->and($note['workspace'])->toBe('Marlowe Studio')
            ->and(strtotime($note['at']))->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time());

        $site = site();
        $html = siteHealth($site, logIn($site));

        expect($html)
            ->toContain('No longer connected to Porro')
            ->toContain('Porro turned this site’s key away')
            ->toContain('The site was removed from Marlowe Studio, or given a new key there.')
            ->toMatch('/Reports stopped on \d{1,2} \p{L}+ at \d{1,2}:\d{2}[^.]*\. The old key has been deleted from this site\./u')
            ->toContain('Connect again to carry on. Opens 127.0.0.1 to sign in and approve.')
            ->toContain('value="porro/connect/start"')
            ->not->toContain('Have this site checked every hour')
            ->not->toContain('Not connected to Porro')
            ->not->toContain('From Porro');

        // It stays, view after view, until the site connects again.
        expect(siteHealth($site, logIn($site)))->toContain('Porro turned this site’s key away');

        connectedFromPanel();

        expect(connectStore('turned-away'))->toBeNull()
            ->and(siteHealth($site, logIn($site)))->toContain('Connected to Marlowe Studio')->not->toContain('turned this site’s key away');
    });

    it('keeps the wording and leaves the button out for someone who can’t connect', function () {
        connectedWithAKeyPorroDropped();
        craft(['porro/report']);
        cpUser('viewer', ['accessCp', 'accessPlugin-porro']);
        $site = site();

        $html = siteHealth($site, logIn($site, 'viewer'));

        expect($html)
            ->toContain('No longer connected to Porro')
            ->toContain('Porro turned this site’s key away')
            ->not->toContain('value="porro/connect/start"')
            ->not->toContain('Watch this site from outside too');
    });

    it('says Porro when it doesn’t know the workspace', function () {
        connectStore('store', '');
        craft(['porro/report']);
        $site = site();

        expect(siteHealth($site, logIn($site)))->toContain('The site was removed from Porro, or given a new key there.');
    });

    it('deletes it when the report came from the control panel', function () {
        connectedWithAKeyPorroDropped();
        // The throttle lets the next control panel request send a report.
        cache('delete', ReportThrottle::KEY);
        $site = site();
        $jar = logIn($site);

        browse("{$site}/index.php?p=admin/dashboard", $jar);

        // It goes after the response has.
        for ($i = 0; $i < 50 && connectStore('key')['stored'] !== null; $i++) {
            usleep(100_000);
        }

        expect(connectStore('key')['stored'])->toBeNull()
            ->and(connectStore('turned-away')['workspace'])->toBe('Marlowe Studio')
            ->and(siteHealth($site, $jar))->toContain('Porro turned this site’s key away');
    });

    it('leaves the key alone when Porro turns a report down for another reason', function (int $status, string $error) {
        connectedFromPanel();
        porroAnswersWith('report', $status, ['ok' => false, 'error' => $error]);

        [$code] = craft(['porro/report']);

        expect($code)->not->toBe(0)
            ->and(connectStore('key')['stored'])->toMatchArray(['workspace' => 'Marlowe Studio'])
            ->and(connectStore('turned-away'))->toBeNull();
    })->with([
        // A 401 too, but the clock's fault, not the key's.
        'a clock error' => [401, 'Request timestamp is too old or too far ahead.'],
        'a server error' => [500, 'Server Error'],
        'another refusal' => [403, 'Invalid signature.'],
    ]);

    it('never deletes anything for a key from the environment', function () {
        connectedFromPanel();

        [$code, $output] = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => unknownKey()]), fn () => craft(['porro/report']));

        expect($code)->not->toBe(0)
            ->and($output)->toContain('Invalid signature.')
            ->and(connectStore('key')['stored'])->toMatchArray(['workspace' => 'Marlowe Studio'])
            ->and(connectStore('turned-away'))->toBeNull();
    });

    it('never deletes the stored key for one passed in on the command line', function () {
        connectedFromPanel();

        [$code, $output] = craft(['porro/connect', unknownKey(), '--no-cron']);

        expect($code)->not->toBe(0)
            ->and($output)->toContain('Invalid signature.')
            ->and(connectStore('key')['stored'])->toMatchArray(['workspace' => 'Marlowe Studio'])
            ->and(connectStore('turned-away'))->toBeNull();
    });
});

describe('php craft porro/disconnect', function () {
    it('won’t disconnect from a shell it can’t ask in, without --force', function () {
        connectedFromPanel();

        // Craft answers yes to every question when there's no terminal.
        [$code, $output] = craft(['porro/disconnect']);

        // Where Craft still asks, no answer is a no.
        expect($output)->toMatch('/Can’t ask here whether to disconnect harbour-arts.org from Marlowe Studio. Add --force to disconnect without being asked.|Disconnect harbour-arts.org from Marlowe Studio\?/')
            ->not->toContain('Disconnected')
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['token'])->not->toBeNull();
    });

    it('disconnects, and says what happened', function () {
        connectedFromPanel();

        [$code, $output] = craft(['porro/disconnect', '--force']);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('Disconnected. Porro has been told.')
            ->toContain('The key has been deleted from this site’s database.')
            ->toContain('The local checks still run. Connect again from the control panel, or with php craft porro/connect.')
            ->and(disconnectCalls())->toHaveCount(1)
            ->and(disconnectCalls()[0]['body'])->toBe('{}')
            ->and(connectStore('key'))->toMatchArray(['token' => null, 'stored' => null, 'row' => '']);
    });

    it('refuses on a copy that isn’t the live site, unless told it is', function (array $set, string $says) {
        connectedFromPanel();

        // A copy of the live database holds the live site's key.
        [$refused, $live] = withEnvFile(fn ($env) => envWith($env, $set), fn () => [
            craft(['porro/disconnect', '--force']),
            fn () => craft(['porro/disconnect', '--force', '--live']),
        ]);

        expect($refused[0])->not->toBe(0)
            ->and($refused[1])->toContain($says.' Disconnecting here would switch off the live site’s key in Porro too. Run it on the live site, or add --live if this really is it.')
            ->not->toContain('Disconnected')
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['token'])->not->toBeNull();

        [$code, $output] = withEnvFile(fn ($env) => envWith($env, $set), $live);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('Disconnected. Porro has been told.')
            ->and(disconnectCalls())->toHaveCount(1)
            ->and(connectStore('key')['token'])->toBeNull();
    })->with([
        'staging' => [['CRAFT_ENVIRONMENT' => 'staging'], 'This copy’s environment is “staging”, not the live site.'],
        'devMode' => [['CRAFT_DEV_MODE' => 'true'], 'This copy has devMode on, so it isn’t the live site.'],
    ]);

    it('says so when Porro didn’t answer, and still deletes the key', function () {
        connectedFromPanel();
        porroAnswersWith('disconnect', 500, ['ok' => false, 'error' => 'Server Error']);

        [$code, $output] = craft(['porro/disconnect', '--force']);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('Disconnected on this site. Porro didn’t answer, so it hasn’t been told: it will show this site as not reporting.')
            ->toContain('The key has been deleted from this site’s database.')
            ->not->toContain('Porro has been told')
            ->and(connectStore('key')['token'])->toBeNull();
    });

    it('can’t change a key in the environment', function () {
        connectedFromPanel();

        [$code, $output] = withEnvFile(fn ($env) => envWith($env, ['PORRO_KEY' => porro()['key']]), fn () => craft(['porro/disconnect', '--force']));

        expect($code)->not->toBe(0)
            ->and($output)->toContain('This site is connected with PORRO_KEY in its environment, which this command can’t change. Remove PORRO_KEY there and deploy.')
            ->and(disconnectCalls())->toBeEmpty()
            ->and(connectStore('key')['stored'])->not->toBeNull();
    });

    it('says so when the site isn’t connected', function () {
        [$code, $output] = craft(['porro/disconnect', '--force']);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('This site isn’t connected to Porro.')
            ->and(disconnectCalls())->toBeEmpty();
    });
});
