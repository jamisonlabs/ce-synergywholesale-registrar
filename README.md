# ClientExec — Synergy Wholesale Registrar Plugin (Extended)

A fork of the [official Clientexec Synergy Wholesale registrar plugin](https://github.com/clientexec/synergywholesale-registrar), extended to support the full Synergy Wholesale API v3.x.

**Maintained by:** [Jamison Labs](https://jamisonlabs.com) / ClubHost Australia

---

## Features

### Included in upstream (clientexec/synergywholesale-registrar)
- Domain availability check
- Register, Transfer, Renew
- Get / Set Nameservers
- Get / Set Contact Information
- Get / Set Registrar Lock
- Get / Set Auto-Renew
- Get DNS Records / Set DNS Records (A, AAAA, MX, CNAME, TXT)
- Retrieve EPP Code
- Import domains from registrar account

### Added in this fork
- **Pricing Sync** — `getDomainPricing` / `getDomainExtensionOptions` (`importPrices = true`)
- **ID Protection** — `enableIDProtection` / `disableIDProtection`
- **DNSSEC** — `DNSSECAddDS` / `DNSSECRemoveDS` / `DNSSECListDS`
- **Glue Records / Child Hosts** — `addHost` / `deleteHost` / `addHostIP` / `deleteHostIP` / `listAllHosts`
- **URL Forwarding** — `addSimpleURLForward` / `deleteSimpleURLForward` / `getSimpleURLForwards`
- **Email Forwarding** — `addMailForward` / `deleteMailForward` / `listMailForwards`
- **Domain Restore** — `restoreDomain` (expired/grace-period domains)
- **SRV DNS record type** support
- **.AU eligibility & Change of Registrant** — `getDomainEligibilityDetails` / `initiateAUCOR`
- **Transfer cancel** — `transferCancel`
- **Auto Pricing Sync** — scheduled cron script syncs TLD prices from SW at a configurable interval with a percentage margin markup
- **SW Specials support** — optionally applies active Synergy Wholesale sale prices daily, reverting automatically once the sale window closes

---

## Requirements

- ClientExec 7.0.x
- PHP 8.2–8.4
- ionCube Loader 15.0 (PHP 8.4) or 14.x (PHP 8.2–8.3)
- A [Synergy Wholesale](https://synergywholesale.com) reseller account
- Your reseller IP whitelisted in the Synergy Wholesale management panel

---

## Installation

1. Copy `PluginSynergywholesale.php` and the `resource/` directory into your ClientExec installation:
   ```
   /path/to/clientexec/plugins/registrars/synergywholesale/
   ```
2. In the CE admin panel go to **Settings → Plugins → Registrars → Synergy Wholesale**.
3. Enter your **Reseller ID** and **API Key**, then click **Save**.
4. Ensure your server's outbound IP is whitelisted in the [Synergy Wholesale Management System](https://manage.synergywholesale.com) under **Account → API Settings**.

> **Upgrading from the official plugin:** This fork is a drop-in replacement. No database changes required.

---

## Auto Pricing Sync

The plugin ships with a standalone cron script (`sw-pricing-cron.php`) that fetches live pricing from the Synergy Wholesale API and updates all matching CE domain product prices automatically.

### How it works

- The script reads its configuration directly from the CE plugin settings (no separate config file).
- On each cron invocation it checks whether the configured sync interval has elapsed. If not due, it exits immediately.
- When due, it calls `getDomainPricing`, applies your margin markup, and updates the `pricing` blob for every CE domain package whose `planname` matches a SW TLD extension.
- After a successful run the timestamp is recorded in the CE settings table so the next interval is calculated correctly.
- A human-readable last-run status string is also written to the CE settings table (key: `plugin_synergywholesale_Pricing Sync Last Status`) for easy querying.

### Plugin settings

Configure all options under **Settings → Plugins → Registrars → Synergy Wholesale**:

| Setting | Type | Description |
|---------|------|-------------|
| **Auto Pricing Sync** | Yes / No | Master switch. When off the cron script exits immediately without doing anything. |
| **Pricing Sync Interval** | Number | How many periods between full syncs. e.g. `1` |
| **Pricing Sync Period** | Text | Period unit: `day`, `week`, `month`, or `year`. Default: `month` |
| **Pricing Margin** | Number | Percentage markup applied over SW cost prices. e.g. `10` = 10% markup |
| **Apply SW Specials** | Yes / No | When enabled, active SW sale prices are applied on every daily cron run and revert automatically once the sale window ends (see below). |

### Cron setup

Copy `sw-pricing-cron.php` to the CE root directory (alongside `config.php`), then add a cron entry on your server:

```
0 16 * * * /usr/local/bin/ea-php84 /path/to/clientexec/sw-pricing-cron.php >> /var/log/sw-pricing-sync.log 2>&1
```

> **Time choice:** 16:00 UTC = 02:00 AEST / 03:00 AEDT. Adjust to suit your timezone. The script uses an interval + last-run check, so the cron schedule just needs to be at least as frequent as your shortest sync period (daily is recommended, especially when specials are enabled).

Replace `/usr/local/bin/ea-php84` with your server's PHP binary path and `/path/to/clientexec/` with your CE document root.

### Structured log output

Every log line is prefixed with a level tag for easy grepping:

```
[2026-04-03 16:00:01] [INFO]  SW Pricing Sync starting
[2026-04-03 16:00:02] [INFO]  SW API responded OK — processing pricing
[2026-04-03 16:00:04] [SALE]  Active sale for .com (2026-04-01 – 2026-04-07): reg=5.99, renew=9.99, xfer=5.99
[2026-04-03 16:00:05] [INFO]  Done (full-sync). Updated: 45 | Unchanged: 467 | Specials applied: 3 | TLD not in CE: 2 | Errors: 0
```

Log levels: `[INFO]` `[WARN]` `[ERROR]` `[SALE]`

To check the last run result directly from MySQL:
```sql
SELECT value FROM setting WHERE name = 'plugin_synergywholesale_Pricing Sync Last Status';
```

### SW Specials (Option B)

Synergy Wholesale occasionally runs time-limited sale prices on specific TLDs. When **Apply SW Specials** is enabled:

- The cron script runs a daily API check **regardless** of the configured sync interval.
- For each TLD, if an active sale window is found (`start_sale_date` ≤ today ≤ `end_sale_date`), sale prices are applied instead of standard prices.
- When the sale window closes, the next daily run automatically restores standard pricing — no manual intervention required.
- TLDs with no active sale are skipped on specials-only runs (standard pricing is not unnecessarily re-written).
- Full interval syncs (e.g. monthly) still run on schedule in addition to daily specials checks.

---

## Synergy Wholesale API

This plugin uses the Synergy Wholesale SOAP API (`https://api.synergywholesale.com`).

- [API Documentation](https://synergywholesale.com/support-centre/using-the-synergy-wholesale-api/)
- [API Updates](https://synergywholesale.com/support-centre/api-updates/)

Your server IP must be whitelisted in the [Synergy Wholesale Management System](https://manage.synergywholesale.com) under **Account > API Settings**.

---

## Upstream

This fork tracks [clientexec/synergywholesale-registrar](https://github.com/clientexec/synergywholesale-registrar).

To pull upstream changes:
```bash
git fetch upstream
git merge upstream/master
```

---

## Credits

This plugin was developed by [Jamison Labs](https://jamisonlabs.com) and would not have been possible without the following open-source reference implementations:

| Repository | Used for |
|---|---|
| [clientexec/synergywholesale-registrar](https://github.com/clientexec/synergywholesale-registrar) | Upstream base plugin (forked) |
| [clientexec/sample-registrar](https://github.com/clientexec/sample-registrar) | CE plugin contract / method signatures |
| [clientexec/enom-registrar](https://github.com/clientexec/enom-registrar) | `getTLDsAndPrices()` pricing array format |
| [clientexec/namesilo-registrar](https://github.com/clientexec/namesilo-registrar) | `fetchDomains()` metadata structure |
| [clientexec/namecheap-registrar](https://github.com/clientexec/namecheap-registrar) | IDPROTECT at registration pattern |
| [clientexec/joker-registrar](https://github.com/clientexec/joker-registrar) | `getGeneralInfo()` return key set |
| [clientexec/openprovider-registrar](https://github.com/clientexec/openprovider-registrar) | DNSSEC management pattern |
| [clientexec/realtimeregister-registrar](https://github.com/clientexec/realtimeregister-registrar) | Glue record / child host management |
| [clientexec/opensrs-registrar](https://github.com/clientexec/opensrs-registrar) | URL / email forwarding pattern |
| [clientexec/domainnameapi-registrar](https://github.com/clientexec/domainnameapi-registrar) | `setRegistrarLock()` lock param usage |
| [clientexec/connectreseller-registrar](https://github.com/clientexec/connectreseller-registrar) | Transfer cancel / restore domain flow |
| [dondominio/clientexec-plugin](https://github.com/dondominio/clientexec-plugin) | AU eligibility / change of registrant |
| [SynergyWholesale/WHMCS-Domains-Module](https://github.com/SynergyWholesale/WHMCS-Domains-Module) | Authoritative SW SOAP API field names |

---

## License

MIT — see [LICENSE](LICENSE)
