# MDF Analytics for WordPress

A WordPress plugin that gives you visibility into AI agent traffic hitting your site — before you do anything else.

Part of the [MDF (Markdown First)](https://github.com/bitcryptic-gw/mdf) ecosystem.

---

## The problem

AI agents now represent [over 57% of web traffic](https://blog.cloudflare.com/application-security-2024/). Most of them are silently scraping your content, burning tokens to parse HTML, and moving on — with no signal to you and no value exchange in either direction.

MDF proposes a better model: serve clean markdown directly to agents via HTTP content negotiation, with access policy expressed through price. Before you commit to that, you need to know what you're working with.

That's what this plugin does.

---

## What it does

MDF Analytics is a **passive observer**. It intercepts requests to your WordPress site, classifies each visitor as a known AI agent, likely automated client, internal/monitor, or human browser, and logs the relevant data. It does not modify any content, serve any markdown, or handle any payments.

The dashboard shows you:

- How many AI agents are hitting your site, and which ones
- Whether any are already sending `Accept: text/markdown` headers
- What you would have earned if you'd been serving paid markdown content
- A daily trend chart of inbound agent traffic
- A separate table for internal/monitor traffic (uptime checkers, WordPress core calls) so they don't inflate your agent counts

**Nothing leaves your site.** No external API calls, no analytics beacons, no phoning home. All data is stored in your WordPress database and purged after 90 days.

---

## Installation

1. Download `mdf-analytics.php` from this repository
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Upload the file and activate
4. Find **MDF Analytics** in the left admin menu

That's it. The plugin starts logging immediately.

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

### llms.txt serving

The plugin ships a curated `llms.txt` file in the plugin directory and serves it at the site root (`/llms.txt`). Site owners can edit the `llms.txt` file in the plugin directory to customise the content. Requests to `/llms.txt` appear in the analytics dashboard alongside other agent traffic, classified through the same visitor classifier.

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

### Phase 2 — Wallet integration
Connect a Lightning wallet (via Alby) or a Base USDC wallet and start serving real 402 responses to agents that request markdown. The plugin begins earning from the traffic the dashboard is already showing you.

### Phase 3 — Markdown generation
Auto-generate CommonMark from your WordPress post and page content. No manual authoring required — flip one toggle and every piece of content has a markdown version available for sale.

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
| 0.1.4 | 2026-07-09 | Vendored, namespaced `league/html-to-markdown`. Pre-build markdown cache pipeline with negotiation gating and `file_exists()` gate. `flock(LOCK_EX)` manifest locking. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.3 | 2026-06-10 | Added: plugin serves curated `llms.txt` at the site root (`/llms.txt`). Supports GET/HEAD, conditional requests, and 1-hour caching. Requests logged through existing classifier. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.2 | 2026-06-10 | Fix: known-agent snippets now show matched fragment (e.g. `Googlebot`) rather than raw UA prefix (`Mozilla`) |
| 0.1.1 | 2026-06-09 | Fix: WordPress core, Jetpack, and uptime monitors correctly classified as internal/monitor and excluded from earnings |
| 0.1.0 | 2026-06-08 | Initial release |

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+

No additional dependencies. No Composer. No npm.

---

## License

MIT — copyright Gary Walker (BitCryptic™) and Graham Hall (Slepner).
