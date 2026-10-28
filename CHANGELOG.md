# Release Notes for Porro Site Health

## 0.3.1 - 2026-10-28

- The plugin's description, on Packagist and in the Plugin Store, covers suspicious activity and IP blocking as well as site health.
- The README shows Activity, IP details and Blocking, with screenshots.

## 0.3.0 - 2026-10-28

- Reporting now defaults to off outside production (`CRAFT_ENVIRONMENT` other than `production`, `prod` or `live`, or devMode on), so a local or staging copy with the live `.env` doesn't report as the live site. An explicit `PORRO_ENABLED=true` still reports from anywhere. `porro/status` and `porro/report` say why reporting is off.
- Reports carry `CRAFT_ENVIRONMENT` (or `ENVIRONMENT`) as the environment, never the host name Craft falls back to when neither is set.
- Added **Activity**: probes for secrets and admin tools, failed sign-ins, 404 bursts and form floods from the last 24 hours, worst first, with each IP's details (network, verified reverse DNS, Tor and Spamhaus lists, fake crawlers) and what it did here.
- Added **Blocking**: block an IP or range for 7 days, 30 days or for good, and safelist addresses. Blocked IPs get a 403 before Craft handles the request. Every block and safelist asks first. The full lists have their own searchable, filterable pages.
- Block at Cloudflare's edge (`PORRO_CLOUDFLARE_TOKEN`, `php craft porro/cloudflare connect`) or in nginx (`PORRO_BLOCK_NGINX`, with a setup page that tracks each server), as the Laravel, Statamic and Filament connectors do.
- **Always protected**: Cloudflare, Forge, Google's and Bing's crawlers and private ranges can't be blocked; the Blocking page lists each with why. Forge and the crawlers can be switched off.
- Suspicious activity shows on Site health too.
- New permission: **Block, unblock and safelist IPs**.
- The control panel nav item is now **Porro**, with Site health, Activity and Blocking under it.
- Requires `porro/php-core` 0.3. Adds the `porro_blocklist` and `porro_safelist` tables (`php craft up`).

## 0.2.1 - 2026-10-24

- A critical Craft or plugin update now shows as **Act now** rather than **Needs attention**.
- **Check again** now fetches everything again: PHP and Craft support dates, security advisories, Craft's own update check and Porro's findings. Before, it only re-ran the checks on cached data.
- The HTTP client stops reading a response once it's over php-core's size limit.
- Requires `porro/php-core` 0.2.1: requests to Porro are signed for their method and path, and local issues are only hidden when Porro has raised the same thing.
- Reports describe the site's database (engine, version, whether it's on the same server, managed provider), so Porro can flag an end-of-life MySQL, MariaDB or PostgreSQL. Only the socket or host is read from the DSN, and neither it, the database name nor any credentials leave the site.

## 0.2.0 - 2026-10-23

- Added a **Porro Site Health** control panel section and dashboard widget. Without a Porro account they check security advisories in `composer.lock`, PHP and Craft support dates, Craft and plugin updates, dev mode, migrations and the queue. Once connected they also check cron and show the site's open Porro findings, linking into Porro.
- Access follows Craft's **Access Porro** permission; admins always have it.
- Added a plugin icon: the Porro mark.
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
