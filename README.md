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

---

## Requirements

- ClientExec 7.0.x
- PHP 8.2–8.4
- ionCube Loader 15.0 (PHP 8.4) or 14.x (PHP 8.2–8.3)
- A [Synergy Wholesale](https://synergywholesale.com) reseller account
- Your reseller IP whitelisted in the Synergy Wholesale management panel

---

## Installation

1. Copy the `PluginSynergywholesale.php` file and `resource/` directory into your ClientExec installation:
   ```
   /path/to/clientexec/plugins/registrars/synergywholesale/
   ```
2. In the CE admin panel, go to **Settings > Domain Registrars > Synergy Wholesale**.
3. Enter your **Reseller ID** and **API Key**.
4. Click **Save**.

> **Upgrading from the official plugin:** This fork is a drop-in replacement. No database changes required.

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

## License

MIT — see [LICENSE](LICENSE)
