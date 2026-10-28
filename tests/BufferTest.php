<?php

// Suspicious requests wait in a file on the server and are recorded in
// batches. Visitors are at public addresses: private ranges never count.

beforeEach(function () {
    blocking('reset');
});

function buffered(): string
{
    return craftPath().'/storage/runtime/porro/activity.buf';
}

/** The site with no lottery: only the minute rule, pages and cron flush. */
function quietSite(array $env = []): string
{
    return proxiedSite(['PORRO_ACTIVITY_FLUSH_ODDS' => '1000000000', ...$env]);
}

/** This server flushed just now, so the next requests only buffer. */
function flushedJustNow(): void
{
    is_dir(dirname(buffered())) || mkdir(dirname(buffered()), 0775, true);
    touch(buffered().'.lock');
}

it('only appends to the buffer, and the Activity page flushes before reading', function () {
    $site = quietSite();
    flushedJustNow();

    foreach (['/.env', '/.git/config', '/wp-login.php'] as $path) {
        asVisitor("{$site}{$path}", '45.83.64.30');
    }

    $lines = file(buffered());

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toContain('45.83.64.30');

    [, $html] = http("{$site}/index.php?p=admin/porro/activity", logIn($site));

    expect($html)->toContain('45.83.64.30')
        ->toContain('probe 3')
        ->and(file_exists(buffered()))->toBeFalse();
});

it('flushes before the IP page reads an IP’s history', function () {
    $site = quietSite();
    flushedJustNow();

    asVisitor("{$site}/not-here", '45.83.64.31');
    [, $html] = http("{$site}/index.php?p=admin/porro/activity/45.83.64.31", logIn($site));

    expect($html)->toContain('requests per hour')
        ->and(file_exists(buffered()))->toBeFalse();
});

it('flushes once the request is over when this server hasn’t for a minute', function () {
    $site = quietSite();
    flushedJustNow();
    asVisitor("{$site}/.env", '45.83.64.32');
    touch(buffered().'.lock', time() - 61);

    asVisitor("{$site}/.env", '45.83.64.32');

    expect(file_exists(buffered()))->toBeFalse();
});

it('flushes now and then by lottery', function () {
    $site = proxiedSite(['PORRO_ACTIVITY_FLUSH_ODDS' => '1']);
    flushedJustNow();

    asVisitor("{$site}/.env", '45.83.64.33');

    expect(file_exists(buffered()))->toBeFalse();
});

it('flushes from the console, for a cron line every minute', function () {
    $site = quietSite();
    flushedJustNow();
    asVisitor("{$site}/.env", '45.83.64.34');

    [$code] = craft(['porro/activity/flush']);

    expect($code)->toBe(0)
        ->and(file_exists(buffered()))->toBeFalse();
});

it('costs an ordinary visitor nothing', function () {
    $site = quietSite();
    flushedJustNow();

    asVisitor("{$site}/", '45.83.64.35');

    expect(file_exists(buffered()))->toBeFalse();
});

it('records straight away when the folder can’t be written', function () {
    $dir = sys_get_temp_dir().'/porro-readonly-'.bin2hex(random_bytes(4));
    mkdir($dir, 0555);
    $site = quietSite(['PORRO_ACTIVITY_BUFFER' => "{$dir}/porro/activity.buf"]);

    foreach (['/.env', '/.git/config', '/wp-login.php'] as $path) {
        asVisitor("{$site}{$path}", '45.83.64.36');
    }

    // Read through a site that buffers normally: the records are already in the table.
    $other = quietSite();
    flushedJustNow();
    [, $html] = http("{$other}/index.php?p=admin/porro/activity", logIn($other));

    expect($html)->toContain('45.83.64.36')
        ->toContain('probe 3')
        ->and(is_dir("{$dir}/porro"))->toBeFalse();

    chmod($dir, 0755);
});
