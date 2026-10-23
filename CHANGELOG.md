# Release Notes for Porro Site Health

## Unreleased

- Added a **Site health** control panel section and dashboard widget. Without a Porro account they check security advisories in `composer.lock`, PHP and Craft support dates, Craft and plugin updates, dev mode, migrations and the queue. Once connected they also check cron and show the site's open Porro findings, linking into Porro.
- Access follows Craft's **Access Porro** permission; admins always have it.
- Added a plugin icon.
- Requires `porro/php-core` 0.2.

## 0.1.5 - 2026-10-22

- Backup reports from control panel visits and plugin or Craft updates, for when cron is missing, throttled to one per 15 minutes across every server sharing the cache.

## 0.1.4 - 2026-10-20

- Added `porro/report --deploy` for deploy scripts.

## 0.1.3 - 2026-10-20

- Reports include the site's npm lock file.

## 0.1.2 - 2026-10-20

- `porro/connect` tells Porro whether cron already runs `porro/report`.

## 0.1.1 - 2026-10-20

- `php craft porro/cron` adds the cron line on its own.

## 0.1.0 - 2026-10-20

- First release.
