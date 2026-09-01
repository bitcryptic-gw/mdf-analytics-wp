# MDF Analytics for WordPress

A WordPress plugin that gives you visibility into AI agent traffic hitting your site — and, since v0.1.4, lets you start serving clean markdown to agents that ask for it.

Part of the [MDF (Markdown First)](https://github.com/bitcryptic-gw/mdf) ecosystem.

---

## The problem

AI agents now represent [over 57% of web traffic](https://blog.cloudflare.com/application-security-2024/). Most of them are silently scraping your content, burning tokens to parse HTML, and moving on — with no signal to you and no value exchange in either direction.

MDF proposes a better model: serve clean markdown directly to agents via HTTP content negotiation, with access policy expressed through price. This plugin gives you visibility into that traffic first, and an opt-in path to start serving markdown.

---

## What it does

MDF Analytics classifies every request to your WordPress site — known AI agent, likely automated client, internal/monitor, or human browser — and logs the relevant data. As of v0.1.6, it can also **serve markdown directly**: turn on "Offer markdown to agents" in Settings, and requests sending `Accept: text/markdown` receive a pre-generated CommonMark version of the page instead of HTML, at the same URL (`Vary: Accept`). Browsers are unaffected.

The dashboard shows you:

- How many AI agents are hitting your site, and which ones
- Whether any are already sending `Accept: text/markdown` headers
- What you would have earned if you'd been serving *paid* markdown content (payments are not yet implemented — see Roadmap)
- A daily trend chart of inbound agent traffic
- A separate table for internal/monitor traffic (uptime checkers, WordPress core calls) so they don't inflate your agent counts

**Nothing leaves your site.** No external API calls, no analytics beacons, no phoning home. All data is stored in your WordPress database and purged after 90 days.

---

## Installation

The plugin is **multi-file** as of v0.1.4 — it ships a vendored, namespaced copy of `league/html-to-markdown` in `vendor/`, which `mdf-analytics.php` requires to load.

> **Do not** upload `mdf-analytics.php` on its own. Without the `vendor/` directory alongside it, WordPress will fatal-error on activation.

1. Download the plugin as a zip — **Code ▸ Download ZIP** on this repository (or the latest release archive, once packaged releases are available).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Activate**.
4. Find **MDF Analytics** in the left admin menu.

The plugin starts logging immediately. To also serve markdown to agents, go to **Settings** and enable **"Offer markdown to agents."**

---

## Dashboard

![MDF Analytics dashboard](screenshots/dashboard.png)

### Stat cards

| Card | What it means |
|------|---------------|
| Total logged requests | All non-human, non-asset requests in the selected window |
| Known AI agents | Matched against a curated list of ~40 known agent UA fragments |
| Likely automated | No browser engine markers, not a known agent — probably a script or framework |
| Internal / monitors | WordPress core, uptime monitors, CDN health probes — excluded from earnings |
| Wanted markdown | Requests that included `Accept: text/markdown` |
| Estimated earned | What you'd have received if markdown was live and priced at your configured rate |
| Estimated missed | What inbound agents could have paid — the opportunity cost |

### Time windows

Switch between last 7, 30, or 90 days. Default is 30 days.

### Settings

Configure your preferred currency (sats via Lightning or USDC via Base) and the per-request rate used for estimated earnings calculations. The defaults are 1 sat and $0.001 USDC — broadly in line with MDF micropayment tier pricing.

This is also where you enable **"Offer markdown to agents"** — the toggle that turns on markdown serving for requests sending `Accept: text/markdown`.

### llms.txt serving

The plugin ships a generic, owner-editable `llms.txt` template in the plugin directory and serves it virtually at the site root (`/llms.txt`) — nothing is ever written to your WordPress installation's actual root directory. Edit the template's placeholder sections (site summary, key pages) to describe your own site; the "Machine-readable content" section at the bottom, which tells agents this site negotiates markdown via `Accept: text/markdown`, doesn't need editing.

**If your site already has a real, static `llms.txt` file at the web root:** the web server serves that file directly, before WordPress ever runs, so the plugin's own copy is silently shadowed and never seen — this is standard static-file precedence, not a bug. As of v0.1.8, the plugin detects this at activation (and keeps rechecking) and shows a dismissible admin notice explaining that your existing file takes priority, with a one-click copy of just the "Machine-readable content" snippet so you can add markdown-negotiation support to your existing file by hand if you want it. The plugin never reads, edits, or deletes your existing file.

Requests to `/llms.txt` (the plugin's own virtual copy) appear in the analytics dashboard alongside other agent traffic, classified through the same visitor classifier.

**On uninstall** (not deactivation — deactivating leaves everything in place), the plugin removes its own data: the database table, all generated markdown cache files, and its options. It never touches a web-root `llms.txt`, static or otherwise, since it never created one.

---

## Markdown serving

Since v0.1.4, the plugin pre-builds and caches a CommonMark version of each post and page. When "Offer markdown to agents" is enabled and a request sends `Accept: text/markdown`, the cached markdown is served at the same URL with a `Vary: Accept` header; all other requests receive HTML as normal, with no `Vary` header added.

Conversion is handled by the vendored `league/html-to-markdown` library.

### Known limitations

- **WP Super Cache — reverse race condition.** As of v0.1.7, the plugin sets `DONOTCACHEPAGE` before serving markdown, which stops WPSC from caching a markdown response under a key that could later be served to HTML requesters. The *reverse* direction is not yet fixed: if WPSC caches the **HTML** response for a URL first, markdown requests to that same URL can be blocked from ever reaching an agent, because WPSC's `wpsc_get_accept_header()` maps `text/markdown` to `text/html` internally and treats them as the same cache entry. Fixing this fully requires a change in WP Super Cache's own plugin extension directory, not just this plugin. If you run WP Super Cache, be aware the dashboard's "wanted markdown" figures may undercount on cached URLs.
- **HTML entity decoding.** Standard HTML entities (e.g. `&amp;`) are currently preserved as-is in converted markdown rather than decoded, so agents may see `&amp;` where a human reader would see `&`. This doesn't break parsing but is cosmetically imperfect.

---

## Agent classification

Visitors are classified into four types:

- **Type 2 — Known agent:** UA string matches a fragment from the curated list. Includes Claude, GPT, Gemini, Perplexity, common crawler bots, Python/Go/Node HTTP clients, and major agentic frameworks.
- **Type 1 — Likely automated:** No browser engine markers (`Mozilla/`, `WebKit`, `Gecko`, etc.) and not a known agent. Conservative heuristic — leans toward false negatives over false positives.
- **Type 3 — Internal/monitor:** Matches platform self-calls and monitoring tools. WordPress core, Uptime Kuma, UptimeRobot, Pingdom, and similar. Logged but excluded from all agent counts and earnings figures.
- **Type 0 — Human:** Has browser engine markers. Not logged unless they also send `Accept: text/markdown`.

Only types 1, 2, and 3 — plus any `Accept: text/markdown` requests — are written to the database. Ordinary human browser traffic is not logged, keeping the table lean.

---

## Roadmap

### Phase 2 — Wallet integration & paid markdown
Connect a wallet and start serving real `402` responses to agents that request markdown, with price-gated access enforced via the x402 payment rail. Blocked on the upstream payment-verification oracle reaching commercial launch — see [CHANGELOG.md](CHANGELOG.md) for status.

Markdown generation itself (previously listed as "Phase 3") already shipped in v0.1.6 — see [Markdown serving](#markdown-serving) above.

---

## Part of the MDF ecosystem

MDF (Markdown First) is an open web standards proposal that makes AI agents first-class content consumers via HTTP content negotiation. Same URL, same domain — agents that send `Accept: text/markdown` get clean markdown; browsers get HTML. Access policy is expressed through price using [x402](https://x402.org) (EVM/stablecoin) and [L402](https://github.com/lightning/blips) (Bitcoin/Lightning) payment rails.

- **Spec:** [github.com/bitcryptic-gw/mdf](https://github.com/bitcryptic-gw/mdf)
- **Reference implementation:** [github.com/bitcryptic-gw/mdf-reference-server](https://github.com/bitcryptic-gw/mdf-reference-server)
- **Live demo:** [mdf-demo.bitcryptic.com](https://mdf-demo.bitcryptic.com)

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

| Version | Date | Summary |
|---------|------|---------|
| 0.1.8 | 2026-09-01 | Added: uninstall hook removes the DB table, markdown cache directory, plugin options, and scheduled events. Added: activation-time detection + dismissible admin notice for a pre-existing static `/llms.txt`, with a copy-paste "Machine-readable content" snippet. Changed: bundled `llms.txt` replaced with a generic owner-editable template. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.7 | 2026-07-16 | Fix: `DONOTCACHEPAGE` set before serving markdown, preventing WP Super Cache from sharing a cache key between markdown and HTML responses for the same URL. Fix: page-builder shortcode expansion (e.g. Divi) now works correctly in WP-Cron context. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.6 | 2026-07-16 | Fix: backfill no longer counts failed conversions as processed. Added: writability check + admin notice for the markdown cache directory. Roadmap copy updated — markdown generation is shipped, not Phase 3. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.4 | 2026-07-09 | Vendored, namespaced `league/html-to-markdown`. Pre-build markdown cache pipeline with negotiation gating and `file_exists()` gate. `flock(LOCK_EX)` manifest locking. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.3 | 2026-06-10 | Added: plugin serves curated `llms.txt` at the site root (`/llms.txt`). Supports GET/HEAD, conditional requests, and 1-hour caching. Requests logged through existing classifier. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.2 | 2026-06-10 | Fix: known-agent snippets now show matched fragment (e.g. `Googlebot`) rather than raw UA prefix (`Mozilla`) |
| 0.1.1 | 2026-06-09 | Fix: WordPress core, Jetpack, and uptime monitors correctly classified as internal/monitor and excluded from earnings |
| 0.1.0 | 2026-06-08 | Initial release |

---

## Requirements

- WordPress 6.0+
- PHP 8.0+ (see `composer.json` for the precise constraint)
- MySQL 5.7+ or MariaDB 10.3+

No Composer or npm runtime dependency — the plugin ships its dependencies vendored.

---

## License

MIT — copyright Gary Walker (BitCryptic™) and Graham Hall (Slepner).
