# MDF Analytics for WordPress

A WordPress plugin that gives you visibility into AI agent traffic hitting your site — and, since v0.1.4, lets you start serving clean markdown to agents that ask for it.

Part of the [MDF (Markdown First)](https://github.com/bitcryptic-gw/mdf) ecosystem.

---

## The problem

Automated clients now account for the majority of web traffic. In June 2026, Cloudflare Radar data showed bots generating 57.5% of HTML requests across Cloudflare's network, against 42.5% from humans — the first time automated requests crossed into the majority. AI agents are the fastest-growing slice of that traffic. Most of them are silently scraping your content, burning tokens to parse HTML, and moving on — with no signal to you and no value exchange in either direction.

MDF proposes a better model: serve clean markdown directly to agents via HTTP content negotiation, with access policy expressed through price. This plugin gives you visibility into that traffic first, and an opt-in path to start serving markdown.

---

## What it does

MDF Analytics classifies every request to your WordPress site — known AI agent, likely automated client, internal/monitor, or human browser — and logs the relevant data. As of v0.1.4, it can also **serve markdown directly**: turn on "Offer markdown to agents" in Settings, and requests sending `Accept: text/markdown` receive a pre-generated CommonMark version of the page instead of HTML, at the same URL (`Vary: Accept`). Browsers are unaffected.

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

### Recommended: install from a packaged release

1. Go to the [Releases page](https://github.com/bitcryptic-gw/mdf-analytics-wp/releases) and download the release asset for the version you want (e.g. `mdf-analytics-0.1.10.zip`). **Download the named release asset, not the auto-generated "Source code (zip)" link.**
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Activate**.
4. Find **MDF Analytics** in the left admin menu.

### Alternative: install from the source archive

**Code ▸ Download ZIP** on this repository also produces a working install — `vendor/` is source-committed, so no Composer step is required. Note that GitHub names the source archive after the repository and branch, so it extracts to a plugin directory called `mdf-analytics-wp-main/` rather than `mdf-analytics/`. This matters if you later need to locate the plugin's bundled `llms.txt` (see below) or reference the plugin path in server config.

### After activation

The plugin starts logging immediately. To also serve markdown to agents, go to **Settings** and enable **"Offer markdown to agents."**

> **Markdown does not start serving instantly.** Enabling the toggle queues a background backfill that converts your published posts and pages to markdown. Until that backfill completes — typically a few minutes on a small site, longer on large ones — your pages will continue to return HTML even to agents sending `Accept: text/markdown`. This is by design: markdown is never served for a URL until a pre-built file exists for it. The Settings page shows a coverage line ("N of M published posts/pages have a cached markdown version") so you can watch progress.

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

This is also where you enable **"Offer markdown to agents"** — the toggle that turns on markdown serving for requests sending `Accept: text/markdown`. When enabled, a coverage status line below the toggle reports backfill progress while it runs, and cached-vs-published counts once it completes.

Below that, a **negotiation status** tells you whether markdown actually reaches clients, and whether the machine-readable claim in the bundled `/llms.txt` is being **published** or **withheld**. The self-test probes up to three qualifying URLs (front page first, then the most recently modified published, publicly viewable, password-free content with a non-empty cached `.md`), confirms each is publicly reachable, and requests it with `Accept: text/markdown`. Outcomes: **working** (every reachable probe returned markdown, so the claim is published), **blocked** (one or more probes returned HTML — a page cache is serving cached pages to agents; if some probes returned markdown and others HTML the split is named, and the claim is withheld), and **unknown** (the loopback failed or no probe URL was reachable — normal on many hosts, never reported as blocked, claim withheld). The test runs on activation, when the toggle is switched on, once a day, and from the **Re-test now** button; it never runs on a normal page load.

When offering is on but the self-test cannot confirm (**unknown** — common on hosts that block loopback HTTP), the page offers an **"I have verified markdown negotiation myself"** checkbox, next to the exact `curl` command to run against your own URL and the response to expect (`content-type: text/markdown` and `Vary: Accept`). Ticking it publishes the claim on your authority; it is cleared automatically if you switch markdown offering off or if a later self-test reports blocked. The status always states plainly whether the claim is published or withheld, and why.

The same page also has an **llms.txt editor** (as of v0.1.10) — see [llms.txt serving](#llmstxt-serving) below.

### llms.txt serving

The plugin ships a generic `llms.txt` template in the plugin directory, used as the **default template**, and serves it virtually at the site root (`/llms.txt`) — nothing is ever written to your WordPress installation's actual root directory.

**To customise it, use the "llms.txt" editor on the plugin's Settings page** (as of v0.1.10). The editor is prefilled with the current effective content — your saved custom content if you have any, otherwise the bundled default template. Saving stores your content in the database, so it survives plugin upgrades; a "Reset to default" button discards your saved content and reverts to the bundled template. The "Machine-readable content" section at the bottom of the template, which tells agents this site negotiates markdown via `Accept: text/markdown`, doesn't need editing. Changes are picked up immediately by the virtual handler, though served copies may be cached by browsers and proxies for up to an hour.

The bundled file in the plugin directory is the read-only default template. Editing it directly is no longer the supported path and **those edits are overwritten when you upgrade the plugin** — copy any customisation you made to the bundled file before upgrading to v0.1.10, then paste it into the editor.

The bundled default's "Machine-readable content" section is served **only when markdown offering is enabled and negotiation is confirmed** — by the self-test (`working`) or by your own override. In every other case (offering off, **blocked**, or unconfirmed **unknown**) the bundled default is served with that section replaced by a neutral, attribution-only "About this file" section, because the plugin will not assert a capability the site has not been shown to have. **Content you saved in the editor is always served verbatim** in every state; the plugin surfaces any mismatch as an admin warning instead of altering your text. When the claim is confirmed, the bundled template is served byte-for-byte unchanged.

**If your site already has a real, static `llms.txt` file at the web root:** the web server serves that file directly, before WordPress ever runs, so the plugin's own copy — default or saved — is silently shadowed and never seen — this is standard static-file precedence, not a bug. As of v0.1.8, the plugin detects this at activation (and keeps rechecking) and shows a dismissible admin notice explaining that your existing file takes priority, with a one-click copy of just the "Machine-readable content" snippet so you can add markdown-negotiation support to your existing file by hand if you want it. The same warning appears inside the llms.txt editor. The plugin never reads, edits, or deletes your existing file.

Requests to `/llms.txt` (the plugin's own virtual copy) appear in the analytics dashboard alongside other agent traffic, classified through the same visitor classifier.

**On uninstall** (not deactivation — deactivating leaves everything in place), the plugin removes its own data: the database table, all generated markdown cache files, and its options (including any saved llms.txt content). It never touches a web-root `llms.txt`, static or otherwise, since it never created one.


---

## Markdown serving

Since v0.1.4, the plugin pre-builds and caches a CommonMark version of each post and page. When "Offer markdown to agents" is enabled and a request sends `Accept: text/markdown`, the cached markdown is served at the same URL with a `Vary: Accept` header; all other requests receive HTML as normal, with no `Vary` header added.

Conversion is handled by the vendored `league/html-to-markdown` library.

### Known limitations

- **WP Super Cache — reverse race condition (addressable, see below).** As of v0.1.7, the plugin sets `DONOTCACHEPAGE` before serving markdown, which stops WPSC from caching a markdown response under a key that could later be served to HTML requesters. The *reverse* direction — WPSC caching the **HTML** response first and then serving it to `Accept: text/markdown` requests, because `wpsc_get_accept_header()` maps `text/markdown` to `text/html` — is addressed by the bundled adapter described in [WP Super Cache adapter](#wp-super-cache-adapter), except in WP Super Cache's Expert (mod_rewrite) mode. If you run WP Super Cache in that mode, or run another page cache or CDN, use the negotiation self-test on the Settings page to see whether markdown is actually reaching agents.
- **HTML entity decoding.** Standard HTML entities (e.g. `&amp;`) are currently preserved as-is in converted markdown rather than decoded, so agents may see `&amp;` where a human reader would see `&`. This doesn't break parsing but is cosmetically imperfect.

### WP Super Cache adapter

WP Super Cache's read path runs in `advanced-cache.php` before normal plugins load, so `mdf_maybe_serve_markdown()` never runs for a URL whose HTML is already cached. WP Super Cache supports loading arbitrary files early: `wpsc_add_plugin()` stores a path relative to `ABSPATH` in its `wpsc_plugins` setting, and `wp-cache-phase1.php` includes every registered file during the cache phase.

MDF Analytics ships a small adapter, `mdf-supercache-adapter.php`, **inside its own plugin directory**, and registers it by path when markdown offering is enabled and WP Super Cache is present and caching. Because the file lives in the MDF plugin, it survives WP Super Cache upgrades (unlike a file dropped in WP Super Cache's own `plugins/` directory, which its admin page warns is wiped on upgrade) and is removed cleanly on deactivation and uninstall.

The adapter hooks WP Super Cache's `wp_cache_get_cookies_values` cache action. When — and only when — the request carries `Accept: text/markdown` (case-insensitive), it appends a short fixed literal marker. This value is concatenated into the cache key, so markdown requests get their own cache bucket, **and** a non-empty value makes WP Super Cache's static supercache gate stand aside ("Cookies found. Cannot serve a supercache file."), so the request reaches PHP and the markdown is served with `Vary: Accept`. No part of the client-supplied header is ever interpolated into the cache key; a missing, malformed, or non-string `Accept` is treated as "not markdown", and a non-markdown request is returned completely unchanged, so browser behaviour is bit-for-bit identical.

**Expert (mod_rewrite) mode.** If WP Super Cache is configured in Expert mode, Apache serves the cached HTML file directly from `.htaccess` before any PHP runs, so no adapter can fix it. The plugin detects this and says so in the Settings status rather than claiming a fix. To handle markdown requests yourself, add a condition that skips the supercache rewrite when the `Accept` header asks for markdown — for example, add the following to **each** `RewriteCond` block that serves a `supercache` file, before the existing `RewriteRule`:

```apache
RewriteCond %{HTTP:Accept} !text/markdown [NC]
```

The plugin never writes to `.htaccess`; this is a manual, optional change. This adapter targets WP Super Cache only — it does not attempt to work around LiteSpeed Cache, W3 Total Cache, WP Rocket, or a CDN. For those (and for Expert mode), the negotiation self-test is what tells you the truth.

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

Markdown generation itself (previously listed as "Phase 3") already shipped in v0.1.4 — see [Markdown serving](#markdown-serving) above.

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
| 0.1.12 | 2026-09-20 | Fixed: the machine-readable `/llms.txt` claim is now published only when offering is enabled **and** negotiation is confirmed (self-test or owner override) — previously it was suppressed only on `blocked`, so offer-off sites published a false claim. Fixed: self-test URL selection now skips non-public post types, empty/missing `.md`, and unreachable URLs, probes up to three, and reports a markdown/HTML split as `blocked`. Added: owner confirmation override with a one-line per-state reason. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.11 | 2026-09-20 | Added: negotiation self-test (working/blocked/unknown) stored in `mdf_negotiation_status`; honest `/llms.txt` that drops the markdown-negotiation claim when blocked while never altering saved custom content; bundled WP Super Cache adapter registered by path via `wpsc_plugins`, fixing the reverse race in standard mode with an honest Expert (mod_rewrite) caveat. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.10 | 2026-09-19 | Added: in-admin llms.txt editor on the Settings page — content is stored in the database (option `mdf_llms_txt`) and survives plugin upgrades; the bundled file becomes the read-only default template; a static-webroot warning is shown in the editor. Changed: `/llms.txt` now serves the stored option when set, falling back to the bundled default otherwise. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.9 | 2026-09-01 | Fix: backfill and per-post rebuild recovery when the scheduled cron event is lost to a race with another scheduler — scheduling is now verified and retried, with a self-heal recheck on existing crons and admin page loads. Added: persistent markdown-coverage status line on the Settings page. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.8 | 2026-09-01 | Added: uninstall hook removes the DB table, markdown cache directory, plugin options, and scheduled events. Added: activation-time detection + dismissible admin notice for a pre-existing static `/llms.txt`, with a copy-paste "Machine-readable content" snippet. Changed: bundled `llms.txt` replaced with a generic owner-editable template. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.7 | 2026-07-16 | Fix: `DONOTCACHEPAGE` set before serving markdown, preventing WP Super Cache from sharing a cache key between markdown and HTML responses for the same URL. Fix: page-builder shortcode expansion (e.g. Divi) now works correctly in WP-Cron context. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.6 | 2026-07-16 | Fix: backfill no longer counts failed conversions as processed. Added: writability check + admin notice for the markdown cache directory. Roadmap copy updated — markdown generation is shipped, not Phase 3. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.4 | 2026-07-09 | Vendored, namespaced `league/html-to-markdown`. Pre-build markdown cache pipeline with negotiation gating and `file_exists()` gate. `flock(LOCK_EX)` manifest locking. Markdown serving goes live in this release. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.3 | 2026-06-10 | Added: plugin serves curated `llms.txt` at the site root (`/llms.txt`). Supports GET/HEAD, conditional requests, and 1-hour caching. Requests logged through existing classifier. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.2 | 2026-06-10 | Fix: known-agent snippets now show matched fragment (e.g. `Googlebot`) rather than raw UA prefix (`Mozilla`) |
| 0.1.1 | 2026-06-09 | Fix: WordPress core, Jetpack, and uptime monitors correctly classified as internal/monitor and excluded from earnings |
| 0.1.0 | 2026-06-08 | Initial release |

There is no v0.1.5 — the version was skipped.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+

No Composer or npm runtime dependency — the plugin ships its dependencies vendored.

---

## License

MIT — copyright Gary Walker (BitCryptic™) and Graham Hall (Slepner).
