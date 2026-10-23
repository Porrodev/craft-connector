# porro/craft-connector

Read-only [Porro](https://porro.dev) connector for Craft CMS 4–5. It sends one signed report about your site to Porro every 15 minutes. It never changes the site and never accepts commands: Porro never connects in.

## Install

```bash
composer require porro/craft-connector
php craft plugin/install porro
php craft porro/connect <site key>
```

Copy the site key from the site's page in Porro. `porro/connect` saves `PORRO_KEY` to `.env` and sends the first report.

Reports then go every 15 minutes from cron. `porro/connect` offers to add the line to the server's crontab for you, with the right PHP binary and project folder, and never adds it twice. If it can't, it prints the line to paste into Forge, Ploi or `crontab -e`:

```
*/15 * * * * cd /path/to/site && php craft porro/report --scheduled > /dev/null 2>&1
```

Use `--cron` to add it without asking (handy in provisioning scripts) or `--no-cron` to leave the crontab alone. Skipped it, or connected from another machine? Run `php craft porro/cron` on the server; it needs no key. Add `php craft porro/report --deploy` to your deploy script so Porro hears about deploys straight away.

## Reports

Each report says what sent it, so Porro can tell when cron has stopped:

| Trigger | Sent by |
|---|---|
| `schedule` | Cron, every 15 minutes: the main way reports arrive |
| `deploy` | `php craft porro/report --deploy` in your deploy script. Never fails the deploy |
| `cp` | A backup for a missing cron line: a logged-in control panel visit, when no report has gone in the last 15 minutes |
| `event` | Straight after a plugin is installed, updated or removed, or Craft is updated |
| `connect`, `manual` | `porro/connect`, or running `porro/report` by hand |

Control panel reports are a backup, not a replacement for cron: a quiet site with nobody logging in sends nothing, and Porro still flags a cron line that has stopped. They follow a few rules so the control panel never slows down or breaks because of Porro:

- Never on front-end requests, and never for visitors who aren't logged in.
- Only after the response has gone. On PHP-FPM the page is finished first; elsewhere the report goes through Craft's queue.
- A 3-second timeout, no retries, and every error swallowed.
- One 15-minute throttle shared with cron, deploys and events, kept in Craft's cache. Load-balanced servers sharing a cache send one report between them.

Event reports skip the throttle. `PORRO_ENABLED=false` turns off every kind of report.

## What leaves a site

- Craft version, edition and environment name
- Installed plugins and their versions, and package versions from `composer.lock` (the list is only sent when the lock file changes)
- Whether dev mode is on, as true or false
- Pending migrations, and Craft's queue: whether it runs automatically, jobs waiting, failed jobs, and how long the oldest job has waited
- When cron last ran
- The database engine and version (e.g. MySQL 8.0.36, MariaDB 10.11.6, PostgreSQL 16.4), whether it's on the same server, and the provider if it's a known managed database. Never its host, name, user or password

## What never does

Content, entries, assets, users, settings values or secrets. Run `php craft porro/status --json` to see the exact report your site would send.

## Commands

| Command | Does |
|---|---|
| `porro/connect <key>` | Saves the key, sends the first report and sets up cron (`--cron`, `--no-cron`, `--url`) |
| `porro/cron` | Adds the cron line on its own, no key needed |
| `porro/report` | Sends a report now (`--packages` forces the package list, `--deploy` for deploy scripts) |
| `porro/status` | Shows what the next report contains (`--json` for the raw report) |

## Config

| Env | Default | |
|---|---|---|
| `PORRO_KEY` | | The site key |
| `PORRO_ENABLED` | `true` | Turn reporting off, e.g. on staging |

## Developing

The tests drive a real Craft install with the plugin installed:

```bash
composer install
CRAFT_PATH=/path/to/craft-site composer test
```

CI builds fresh Craft 4 and Craft 5 sites on Postgres for every push.

## Security

Please email security@porro.dev rather than opening an issue. We reply within one working day.

## License

MIT
