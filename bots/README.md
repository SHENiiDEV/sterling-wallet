# Bots (connectors)

Playwright scripts that fetch provider reports. Laravel runs them — never run
them by hand in production.

```
bots:dispatch (every 30 min)  →  bot_runs (queued)  →  RunConnectorJob
    → node bots/{connector}.mjs  (JSON in on stdin, JSON result on the last stdout line)
    → ReportIngestionService     (files → operations → daily reports)
```

| Script              | Provider                   | What it does                                                                                                                                                     |
| ------------------- | -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `corefy-export.mjs` | Corefy (Paycore dashboard) | Exports payment invoices of one commerce account for the report period, downloads the CSV. 0 rows → the day is marked as received with no operations.            |
| `cardaq-export.mjs` | Cardaq (reports by e-mail) | Logs into Hostinger webmail, finds the report e-mail whose subject date range covers the report date, downloads XLSX/CSV attachments. No e-mail yet → `skipped`. |
| `madfin-export.mjs` | Madfin                     | Placeholder until the portal steps are known.                                                                                                                    |

Credentials, 2FA secrets and per-account settings live in **Admin → Bots**
(`integration_accounts`, encrypted). Settings keys:

- Cardaq: `search_query` (default `EXORAPAY FINANCE LTD`), `mail_base_url`, `proxy`.
  Hostinger announcement popups ("Try conversation view") are closed automatically.
- Corefy: `dashboard_url`, `proxy`. The commerce account comes from each MID's **Gate MID** field.
  Two-step verification: put the base32 secret (the key behind the QR code) in the
  account's **2FA secret**. The bot types a code with at least 6 s left in its window and,
  if Paycore rejects it, retries once with the next code.

## Install on the server

```bash
cd bots
npm ci --omit=dev
sudo npx playwright install-deps chromium   # system libraries, once
npm run install-browser                     # Chromium into bots/.browsers
sudo chown -R www-data:www-data .browsers
```

Chromium lives in `bots/.browsers` (`BOTS_BROWSERS_PATH`), not in the home
directory of whoever installed it, so the queue worker finds it whether it runs
as root or www-data. Run `npm run install-browser` again after upgrading
Playwright.

Laravel settings (`.env`): `BOTS_NODE_BINARY`, `BOTS_HEADLESS`, `BOTS_HTTP_PROXY`,
`BOTS_TIMEOUT_SECONDS`, `TELEGRAM_TOKEN`, `TELEGRAM_CHAT_ID` (alert after 3 failures in a row).

## Adding a provider

1. `App\Reports\Parsers\{Name}Parser` + column aliases in `config/sterling.php`.
2. `App\Bots\Connectors\{Name}ExportConnector` + `bots/{name}-export.mjs`.
3. Register both in `AppServiceProvider`, then create the provider in the admin (report format, connector, delay).
