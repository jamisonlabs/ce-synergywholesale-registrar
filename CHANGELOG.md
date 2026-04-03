# Changelog

All notable changes to this project are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [1.0.1] — 2026-04-03

### Added

- **Auto Pricing Sync** — new cron script (`sw-pricing-cron.php`) reads interval, period, margin, and API credentials from CE registrar plugin settings and updates all CE domain package prices automatically on schedule.
- **Pricing Sync settings** — `Auto Pricing Sync` (yes/no), `Pricing Sync Interval` (number), `Pricing Sync Period` (text: day/week/month/year), `Pricing Margin` (% markup) added to registrar plugin settings.
- **Apply SW Specials** (yes/no) — when enabled the cron runs daily and applies active Synergy Wholesale sale prices within their date window; prices revert to standard automatically once the sale ends.
- **Structured logging** — cron log output uses `[INFO]` / `[WARN]` / `[ERROR]` / `[SALE]` prefixes for easy grepping.
- **Last-status CE setting** — after each run, `plugin_synergywholesale_Pricing Sync Last Status` is written to the CE `setting` table (format: `OK | YYYY-MM-DD HH:MM:SS | Updated: N | Unchanged: N | Specials applied: N | TLD not in CE: N | Errors: N`).

### Fixed

- `Pricing Sync Period` field was declared as `dropdown` type which does not render in CE 7.0.1 registrar plugin settings; changed to `text` type with an inline description of accepted values.
- Removed `getPricingSyncPeriodValues()` helper method (no longer needed).

---

## [1.0.0] — 2026-04-03

Initial release of the Jamison Labs extended fork.
Baseline comparison: [`clientexec/synergywholesale-registrar`](https://github.com/clientexec/synergywholesale-registrar) @ `master` (last upstream commit prior to fork).

### Added — New API features

- **Pricing Sync** (`getTLDsAndPrices`) — pulls live register / renew / transfer pricing from SW `getDomainPricing` / `getDomainExtensionOptions`. Sets `importPrices = true` in plugin features.
- **ID Protection** — `enablePrivateRegistration` / `disablePrivateRegistration` wrapping SW `enableIDProtection` / `disableIDProtection`. Also applied automatically at domain registration when the CE `IDPROTECT` addon is ordered.
- **DNSSEC DS Records** — `getDNSSEC` / `addDNSSECRecord` / `deleteDNSSECRecord` wrapping SW `DNSSECListDS` / `DNSSECAddDS` / `DNSSECRemoveDS`.
- **Glue Records / Child Hosts** — `getGlueRecords` / `addGlueRecord` / `deleteGlueRecord` wrapping SW `listAllHosts` / `addHost` / `deleteHost` / `addHostIP` / `deleteHostIP`.
- **URL Forwarding** — `getURLForwarding` / `addURLForward` / `deleteURLForward` wrapping SW `getSimpleURLForwards` / `addSimpleURLForward` / `deleteSimpleURLForward`.
- **Email Forwarding** — `getEmailForwarding` / `addEmailForward` / `deleteEmailForward` wrapping SW `listMailForwards` / `addMailForward` / `deleteMailForward`.
- **Domain Restore** — `doRestoreDomain` registered as a CE Action, wrapping SW `restoreDomain`. Allows restoring expired domains still in the redemption grace period.
- **Transfer Cancel** — `doCancelTransfer` registered as a CE Action, wrapping SW `transferCancel`.
- **SRV DNS record type** — added `SRV` to the supported `$dnsTypes` list alongside `A`, `AAAA`, `MX`, `CNAME`, `TXT`.
- **.AU Eligibility & Change of Registrant** — `getAUEligibilityFields` / `initiateAUChangeOfRegistrant` wrapping SW `getDomainEligibilityDetails` / `initiateAUCOR`.
- **Registered Actions** updated — `RestoreDomain (Restore Domain)` and `CancelTransfer (Cancel Transfer)` added to the CE action menu.

### Fixed — Bugs corrected vs. upstream

- **`getTLDsAndPrices()` return format** — upstream returned an indexed array with year-keyed sub-arrays. CE 7.x expects `$tlds[$tld]['pricing']['register|renew|transfer'] = float` (TLD string as key, single base price per operation). Corrected to match the format used by enom, namesilo, namecheap, and all other official CE registrar plugins.
- **`getGeneralInfo()` incomplete early return** — the RGP (redemption grace period) early-return path only returned `['registrationstatus' => 'RGP']`. CE accesses `id`, `domain`, `expiration`, `autorenew`, `idprotect`, `is_registered`, `is_expired` downstream and would crash on missing keys. Fixed to return a complete array.
- **`getGeneralInfo()` autoRenew undefined** — `$response->autoRenew != 'off'` evaluates to `true` when the property is absent. Added `isset()` guard.
- **`fetchDomains()` missing metadata** — upstream returned `[]` as the second element of the return pair. CE uses this metadata for pagination display. Fixed to return `['total', 'start', 'end', 'numPerPage', 'next']`.
- **`setRegistrarLock()` unnecessary API round-trip** — upstream queried `domainInfo` to detect current lock state before toggling. CE passes `$params['lock']` (1 = lock, 0 = unlock) directly. Removed the extra API call and used `$params['lock']` directly.
- **`validatePhone()` TypeError on null** — `preg_replace()` on a `null` input throws `TypeError` in PHP 8.2+. Added `(string)` cast.
- **`setContactInformation()` optional params** — `Registrant_Address2` and `Registrant_Fax` are not guaranteed to be present in CE params. Added `?? ''` null-coalescing defaults.
- **`getNameServers()` null foreach** — `$response->nameServers` can be `null` when no nameservers are returned. Added `!empty()` guard before the loop.
- **`getDNS()` / `setDNS()` null foreach** — `$response->records` can be `null`. Replaced bare `foreach` with `foreach ($response->records ?? [] as ...)`.
- **`getTLDsAndPrices()` SOAP field name** — the SW API response field may be named `domainPricingList` in some API versions rather than `pricing`. Added a fallback check for both field names.

### Changed

- `plugin.ini` — `createdby` updated to `Jamison Labs (fork of Clientexec Inc.)`, `forum_url` set to this repository's issue tracker, `creator_url` set to `https://jamisonlabs.com`, `brief` updated to reflect the extended fork, `pricingsync = 1`, `idprotect = 1`, `dnssec = 1` added to `[features]`.
- Class docblock added to `PluginSynergywholesale.php` with author, copyright, license, and upstream attribution.
- "Developed by Jamison Labs" attribution added to the CE admin Supported Features label.
- `README.md` rewritten with full feature list, requirements, installation guide, API references, upstream tracking instructions, credits table, and license.
- `LICENSE` updated to MIT — Jamison Labs copyright.
- `.gitignore` added (OS, IDE, PHP log artefacts).

---

## Upstream baseline

The upstream plugin (`clientexec/synergywholesale-registrar`) covered:

- Domain availability check
- Register, Transfer, Renew
- Get / Set Nameservers
- Get / Set Contact Information
- Get / Set Registrar Lock
- Get / Set Auto-Renew
- Get DNS Records / Set DNS Records (A, AAAA, MX, CNAME, TXT)
- Retrieve EPP Code
- Import domains from registrar account
