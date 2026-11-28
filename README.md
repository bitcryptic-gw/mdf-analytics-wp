# MDF Analytics for WordPress

MDF Analytics gives AI agents a clean markdown version of each post and page on your WordPress site, at the same URL. An agent that sends `Accept: text/markdown` gets a pre-built CommonMark version of the page; browsers, search engines and human visitors get exactly the page they get today. It also shows you which agents are visiting, and which of them are asking for markdown.

**Nothing changes for your visitors.** No new URLs, no redirects, no templates to rebuild. Adopting MDF only adds a markdown representation for agents that ask for one — the designed page stays the page.

**Nothing leaves your site.** No external API calls, no analytics beacons, no phoning home. All data is stored in your WordPress database and purged after 90 days. The one HTTP request the plugin makes is a loopback self-test to your own site, described under [Negotiation self-test](#negotiation-self-test).

Automated clients are now a large share of page requests. In June 2026, Cloudflare Radar measured bots at **57.5% of HTML page requests** across Cloudflare's network against 42.5% from humans — the first time automated requests crossed into the majority. That figure counts HTML page requests, not all internet traffic ([Cloudflare Radar traffic](https://radar.cloudflare.com/traffic)). Most of those clients get HTML built for human eyes and strip it back down themselves. MDF Analytics gives them the tidy version instead, and shows you the traffic while you decide what to do with it.

MDF Analytics is part of the [MDF (Markdown First)](https://github.com/bitcryptic-gw/mdf) ecosystem — see [Part of the MDF ecosystem](#part-of-the-mdf-ecosystem).

---

## Install

The plugin is **multi-file** — it ships a vendored, namespaced copy of `league/html-to-markdown` in `vendor/`, which `mdf-analytics.php` requires to load.

> **Do not** upload `mdf-analytics.php` on its own. Without the `vendor/` directory alongside it, WordPress will fatal-error on activation.

### Recommended: install from a packaged release

1. Go to the [Releases page](https://github.com/bitcryptic-gw/mdf-analytics-wp/releases) and download the release asset for the version you want (named `mdf-analytics-<version>.zip`). **Download the named release asset, not the auto-generated "Source code (zip)" link.**
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Activate**.
4. Find **MDF Analytics** in the left admin menu.

### Alternative: install from the source archive

**Code ▸ Download ZIP** on this repository also produces a working install — `vendor/` is source-committed, so no Composer step is required. Note that GitHub names the source archive after the repository and branch, so it extracts to a plugin directory called `mdf-analytics-wp-main/` rather than `mdf-analytics/`. This matters if you later need to locate the plugin's bundled `llms.txt` (see [llms.txt serving](#llmstxt-serving)) or reference the plugin path in server config.

---

## Turn it on

The plugin starts logging immediately after activation. To also serve markdown to agents, go to **Settings** and enable **"Offer markdown to agents."**

> **Markdown does not start serving instantly.** Enabling the toggle queues a background backfill that converts your published posts and pages to markdown. Until that backfill completes — typically a few minutes on a small site, longer on large ones — your pages will continue to return HTML even to agents sending `Accept: text/markdown`. This is by design: markdown is never served for a URL until a pre-built file exists for it. The Settings page shows a coverage line ("N of M published posts/pages have a cached markdown version") so you can watch progress.

---

## Check it works

Once the backfill has finished, ask for a page as an agent would:

```bash
curl -sI -H 'Accept: text/markdown' https://example.com/some-post/
```

The response should carry (the status line and header order vary by server):

```
HTTP/2 200
content-type: text/markdown; charset=utf-8
vary: Accept
```

If you get `text/html` and no `vary: Accept`, one of three things is happening: markdown offering is off, that URL has no cached markdown yet (backfill still running, or the content was published after the backfill), or something in front of WordPress — a page cache or a CDN — is answering with a stored HTML page before the plugin runs. The third case is what the negotiation self-test exists to catch; see [Negotiation self-test](#negotiation-self-test) and [Page caches](#page-caches).

To see whether agents are actually asking, open the dashboard.

The capability document is at the same root. Once negotiation is confirmed it should be there; while the site is not serving markdown it deliberately answers 404:

```bash
curl -s https://example.com/mdf.json
```

---

## Dashboard

![MDF Analytics dashboard](screenshots/dashboard.png)

### Stat cards

| Card | What it means |
|------|---------------|
| Total logged requests | All logged requests in the selected window. Ordinary human browser traffic and asset requests are not logged |
| Known AI agents | Matched against a curated list of known agent UA fragments — AI assistants, agentic frameworks, search crawlers, common HTTP client libraries |
| Likely automated | No browser engine markers, not a known agent — probably a script or framework |
| Internal / monitors | WordPress core, uptime monitors, CDN health probes — logged, but excluded from all agent counts and earnings figures |
| Wanted markdown | Requests that included `Accept: text/markdown` |
| Estimated earned | **Estimate only** — markdown requests in the window multiplied by your configured rate. No payment is taken |
| Estimated missed | **Estimate only** — agent requests that did not ask for markdown, multiplied by your configured rate. An opportunity-cost figure, not money owed |

### Time windows

Switch between last 7, 30, or 90 days. Default is 30 days.

Below the cards are a daily trend chart of inbound agent traffic, a **Top agents** table, a separate **Internal / monitors** table (so uptime checkers and WordPress core calls don't inflate your agent counts), and a **Top markdown-requested paths** table.

---

## Settings

Configure the currency used for earnings estimates (sats via Lightning, or USDC via Base) and the per-request rate the estimates multiply by. The defaults are 1 sat and $0.001 USDC — broadly in line with MDF micropayment tier pricing. These values only affect the two estimate cards; nothing is charged.

This is also where you enable **"Offer markdown to agents"** — the toggle that turns on markdown serving for requests sending `Accept: text/markdown`. When enabled, a coverage status line below the toggle reports backfill progress while it runs, and cached-vs-published counts once it completes.

Below that, a **negotiation status** tells you whether markdown actually reaches clients, and whether the machine-readable claim in the bundled `/llms.txt` is being **published** or **withheld** — see [Negotiation self-test](#negotiation-self-test). The same block also states whether cached HTML can carry `X-MDF-Version` on this site, and why not when it cannot — see [Cached HTML and X-MDF-Version](#cached-html-and-x-mdf-version).

The same page also has an **llms.txt editor** — see [llms.txt serving](#llmstxt-serving).

---

## Negotiation self-test

The self-test probes up to three qualifying **clean-path** URLs (front page first, then the most recently modified published, publicly viewable, password-free content with a non-empty cached `.md`). Query-string permalinks are excluded, because a query string makes the page cache stand aside and would test the wrong path. Each URL is confirmed publicly reachable, then requested in **both** `Accept-Encoding` variants — the default (gzip) and `identity` — because the page-cache key differs by encoding.

Outcomes:

- **working** — every reachable probe in both encodings returned markdown, so the claim is published.
- **blocked** — one or more probes returned HTML. A page cache is serving cached pages to agents; the URL and encoding are named and the claim is withheld.
- **unknown** — the loopback failed or no probe URL was reachable. Normal on many hosts, never reported as blocked, claim withheld.

On a WooCommerce site the self-test also sends **negative probes**: the cart, checkout and account URLs and one account endpoint are requested in both encodings with `Accept: text/markdown`. Those pages must never answer with markdown, so any markdown response there reports **blocked**, naming the URL and encoding — the claim is withheld regardless of what the positive probes found.

The test runs on activation, when the toggle is switched on, once a day, and from the **Re-test now** button; it never runs on a normal page load.

When offering is on but the self-test cannot confirm (**unknown** — common on hosts that block loopback HTTP), the page offers an **"I have verified markdown negotiation myself"** checkbox, next to the exact `curl` command to run against your own URL and the response to expect (`content-type: text/markdown` and `Vary: Accept`). Ticking it publishes the claim on your authority; it is cleared automatically if you switch markdown offering off or if a later self-test reports blocked. The status always states plainly whether the claim is published or withheld, and why.

---

## llms.txt serving

The plugin ships a generic `llms.txt` template in the plugin directory, used as the **default template**, and serves it virtually at the site root (`/llms.txt`) — nothing is ever written to your WordPress installation's actual root directory.

**To customise it, use the "llms.txt" editor on the plugin's Settings page.** The editor is prefilled with the current effective content — your saved custom content if you have any, otherwise the bundled default template. Saving stores your content in the database, so it survives plugin upgrades; a "Reset to default" button discards your saved content and reverts to the bundled template. The "Machine-readable content" section at the bottom of the template, which tells agents this site negotiates markdown via `Accept: text/markdown`, doesn't need editing. Changes are picked up immediately by the virtual handler, though served copies may be cached by browsers and proxies for up to an hour.

The bundled file in the plugin directory is the read-only default template. Editing it directly is no longer the supported path: **those edits are overwritten when you upgrade the plugin.** If you customised the bundled file under an older version, paste that content into the editor.

The bundled default's "Machine-readable content" section is served **only when markdown offering is enabled and negotiation is confirmed** — by the self-test (`working`) or by your own override. In every other case (offering off, **blocked**, or unconfirmed **unknown**) the bundled default is served with that section replaced by a neutral, attribution-only "About this file" section, because the plugin will not assert a capability the site has not been shown to have. **Content you saved in the editor is always served verbatim** in every state; the plugin surfaces any mismatch as an admin warning instead of altering your text. When the claim is confirmed, the bundled template is served byte-for-byte unchanged.

On a WooCommerce site whose claim is published, the bundled default template also carries a short **Session-only pages** section, stating that the cart, checkout and account pages and their endpoints are served as HTML only and never as markdown — so the file never implies markdown is available for a page that has none. This applies to the bundled default; content you saved in the editor is served verbatim in every state, as above.

**If your site already has a real, static `llms.txt` file at the web root:** the web server serves that file directly, before WordPress ever runs, so the plugin's own copy — default or saved — is silently shadowed and never seen. This is standard static-file precedence, not a bug. The plugin detects this at activation (and keeps rechecking) and shows a dismissible admin notice explaining that your existing file takes priority, with a one-click copy of just the "Machine-readable content" snippet so you can add markdown-negotiation support to your existing file by hand if you want it. The same warning appears inside the llms.txt editor. The plugin never reads, edits, or deletes your existing file.

Requests to `/llms.txt` (the plugin's own virtual copy) appear in the analytics dashboard alongside other agent traffic, classified through the same visitor classifier.

**On uninstall** (not deactivation — deactivating leaves everything in place), the plugin removes its own data: the database table, all generated markdown cache files, and its options (including any saved llms.txt content). It never touches a web-root `llms.txt`, static or otherwise, since it never created one.

---

## /mdf.json

MDF's canonical discovery document. The plugin serves a **free-only** capability document virtually at the site root (`/mdf.json`) — never written to your installation's root directory — so agents can learn what the site offers before they fetch anything.

```json
{
    "mdf_version": "0.3.1",
    "site": "https://example.com",
    "name": "Example Site",
    "pricing": { "default": { "rails": [] } },
    "formats": { "dialect": "commonmark" },
    "llms_txt": "https://example.com/llms.txt"
}
```

An empty `rails` list means open access: everything is free. There are no priced sections, no payment block, no auth endpoint and no feed declaration — the plugin takes no payment, and it does not assert content signals (training or search consent) on your behalf.

**It is published only when the site is actually serving markdown**: markdown offering on, and negotiation confirmed by the self-test (`working`) or by your own override. In every other state — offering off, **blocked**, or unconfirmed **unknown** — `/mdf.json` answers **404** with a short JSON reason rather than advertising a capability the site has not been shown to have. The Settings page states which state the document is in, and why.

The document is a capability and mechanism declaration, not a coverage index: it does not say which URLs currently have markdown, and it never should. Per-URL availability is answered by the request itself — see [What is never served as markdown](#what-is-never-served-as-markdown).

**If your site already has a real `mdf.json` at the web root**, your web server serves that file before WordPress runs and it takes priority. The plugin detects this at activation (and keeps rechecking) and shows a dismissible admin notice. It never reads, edits or deletes your file.

Like `/llms.txt`, the response is `GET`/`HEAD` only, carries `X-Content-Type-Options: nosniff`, `Content-Length` and `Last-Modified`, answers `If-Modified-Since` with `304`, and is publicly cacheable for an hour. Requests are logged through the same visitor classifier.

---

## Markdown serving

The plugin pre-builds and caches a CommonMark version of each post and page. When "Offer markdown to agents" is enabled and a request sends `Accept: text/markdown`, the cached markdown is served at the same URL with a `Vary: Accept` header and `X-MDF-Version: 1`; all other requests receive HTML as normal, with no `Vary` header added. The HTML at a URL that does have a markdown variant carries `X-MDF-Version: 1` too, as MDF 0.3.1 requires for both representations — including when WP Super Cache standard mode answers it from the page cache. See [Cached HTML and X-MDF-Version](#cached-html-and-x-mdf-version).

Conversion is handled by the vendored `league/html-to-markdown` library. Responses support conditional GET (`If-Modified-Since`) and HEAD, and are marked publicly cacheable for an hour.

### What is never served as markdown

Some pages and objects are excluded on purpose. For each of these, a markdown request gets the normal HTML response and no `Vary: Accept` — which is what the MDF specification requires where no markdown representation exists:

- **Non-singular views.** Archives, the blog index and other listing views always get HTML. Only a single post, page or public custom post type can have a markdown variant.
- **Zero-byte variants.** If an object's body converts to nothing, the empty file is not served.
- **WooCommerce cart, checkout and account pages**, including their endpoints (`order-pay`, `order-received`, every account endpoint), **and the shop page**. See below.
- **Password-protected content.**
- **Post types that are not publicly viewable.**
- **Pages a third party has marked uncacheable** by defining `DONOTCACHEPAGE`. The bundled WP Super Cache adapter defines that constant on its own markdown requests to bypass the page cache, so it also defines `MDF_WPSC_MARKDOWN_BYPASS` in the same step; that marker is what stops the adapter's own define being mistaken for a third-party one.

### Transactional pages (WooCommerce)

On a WooCommerce site the cart, checkout (with its `order-pay` and `order-received` endpoints) and account area (with every account endpoint) are **never** offered as markdown. They are excluded from conversion, the backfill, `save_post` rebuilds, the cached-ID listing and the manifest, and from serving — by request conditionals and by page ID. A relocated page is caught by scanning page content for the cart/checkout/account shortcode or block. The shop page is excluded as well: its body is empty and its listing is generated at request time.

On upgrade from 0.1.13 the activation hook deletes any `.md` file already cached for those pages and records what it removed in the `mdf_exclusion_cleanup` option.

### Known limitations

- **Escaped characters in the raw markdown.** Conversion writes a literal ampersand as `&amp;`, and `<` / `>` as `&lt;` / `&gt;`. That is correct markdown — it renders identically in a browser and avoids an unescaped `&` being read as the start of an entity — but an agent reading the raw text sees the escape where a human sees `&`. Other HTML entities such as `&eacute;` or `&hellip;` are decoded to the character they represent.
- **Page caches.** A page cache that serves stored HTML before WordPress runs can answer a markdown request with HTML. WP Super Cache in its standard mode is handled by the bundled adapter, which also adds `X-MDF-Version` to that cached HTML for URLs that have a markdown variant. WP Super Cache's Expert mode, other page caches and CDNs serve cached HTML without the header, and the plugin cannot add it there. See [Page caches](#page-caches), and use the [negotiation self-test](#negotiation-self-test) to see whether markdown is actually reaching agents on your setup.

---

## Page caches

Serving markdown depends on the request reaching PHP. A page cache that answers from disk before WordPress loads will hand an agent the stored HTML, and the plugin never gets a chance to negotiate.

### WP Super Cache adapter

WP Super Cache's read path runs in `advanced-cache.php` before normal plugins load, so `mdf_maybe_serve_markdown()` never runs for a URL whose HTML is already cached. WP Super Cache supports loading arbitrary files early: `wpsc_add_plugin()` stores a path relative to `ABSPATH` in its `wpsc_plugins` setting, and `wp-cache-phase1.php` includes every registered file during the cache phase.

MDF Analytics ships a small adapter, `mdf-supercache-adapter.php`, **inside its own plugin directory**, and registers it by path when markdown offering is enabled and WP Super Cache is present and caching. Because the file lives in the MDF plugin, it survives WP Super Cache upgrades (unlike a file dropped in WP Super Cache's own `plugins/` directory, which its admin page warns is wiped on upgrade) and is removed cleanly on deactivation and uninstall.

The adapter hooks WP Super Cache's `cache_init` action and, when the request is a GET/HEAD carrying `Accept: text/markdown` (case-insensitive), sets WP Super Cache's `$cache_enabled = false` and defines `DONOTCACHEPAGE`. WP Super Cache's phase 1 checks `$cache_enabled` immediately after `cache_init` and returns before serving anything, and `wp_cache_postload()` returns before the output-buffer callback is installed — so a markdown request **neither reads nor writes** the page cache. That is the point. An earlier design gave markdown requests their own cache key via a marker; a marked request that fell through to normal HTML rendering (no cached `.md` yet) was cached under the markdown key and then served to every later markdown request. A total bypass cannot do that, needs no marker and no separate key, and is independent of encoding. Requests that are not markdown are touched in no way, so browser behaviour is bit-for-bit identical; missing, non-string and oversized `Accept` values are treated as "not markdown"; GET and HEAD only. Marker-keyed entries left behind by earlier versions are never read again and expire on their own — no cleanup is required.

### Cached HTML and `X-MDF-Version`

MDF 0.3.1 requires `X-MDF-Version` on the HTML served at a URL where markdown is available. A page-cache hit is answered without loading WordPress, so the plugin's own header code cannot run on that path.

In WP Super Cache's standard mode the bundled adapter covers it. The plugin keeps a small marker store in `wp-content/mdf-cache-markers/`:

- one empty marker file for every URL that has a servable markdown variant, named by the SHA-256 of the normalised request path, and
- one `confirmed` flag that exists only while the site's claim is confirmed — the same gate as `/mdf.json`.

During WP Super Cache's phase 1 the adapter makes two `stat()` calls, reads nothing, and adds `X-MDF-Version: 1` to a GET/HEAD request that is not asking for markdown only when both files are present. The marker store is deliberately outside both the uploads directory (which can be relocated) and WP Super Cache's cache directory (which "Delete cache" wipes).

Markers follow the variant's lifecycle: a marker is written when a conversion produces a non-empty variant, and removed when the variant stops being servable — a zero-byte result, an exclusion, unpublish, trash, delete, a password being added, a post type becoming non-viewable, or a slug or permalink change. They are rebuilt on a permalink-structure change and on the 0.1.15 → 0.1.16 upgrade.

If the marker store is not readable, or the site is not in WP Super Cache standard mode with the adapter registered, cached HTML simply carries no header — a withheld header, never a wrong one. The Settings page states which case applies and why.

Plain (query-string) permalinks put the object's identity in the query string, which the marker key does not carry, so those URLs get no marker and their cached HTML carries no header. That is deliberate: the alternative would let every post claim the front page's path.

### Expert (mod_rewrite) mode

If WP Super Cache is configured in Expert mode, Apache serves the cached HTML file directly from `.htaccess` before any PHP runs, so no adapter can fix it. The plugin detects this and says so in the Settings status rather than claiming a fix. Expert mode also means cached HTML carries no `X-MDF-Version`, for the same reason. To handle markdown requests yourself, add a condition that skips the supercache rewrite when the `Accept` header asks for markdown — for example, add the following to **each** `RewriteCond` block that serves a `supercache` file, before the existing `RewriteRule`:

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

## Paid markdown (planned)

Today the plugin serves markdown free of charge. Charging agents for markdown, by answering with an MDF `402` and accepting payment over x402, is planned but not scheduled. The [reference server](https://github.com/bitcryptic-gw/mdf-reference-server) shows what a priced MDF site looks like now.

---

## Part of the MDF ecosystem

MDF (Markdown First) is an open web standards proposal that makes AI agents first-class content consumers via HTTP content negotiation. Same URL, same domain — agents that send `Accept: text/markdown` get clean markdown; browsers get HTML. Where a site charges for markdown, access policy is expressed as price: the site answers with a `402` whose body is an x402 v2 `PaymentRequired` object, and MDF's own fields travel under `extensions.mdf`. Two settlement rails are defined: **USDC on Base**, a stablecoin rail (x402's `exact` scheme on an EVM network), and **Bitcoin over Lightning**, offered as an entry in the 402's `accepts[]` array.

This plugin implements MDF's content negotiation, both discovery documents, and the protocol header: markdown on request, `/llms.txt` with a machine-readable claim, a free-only `/mdf.json` capability document, and `X-MDF-Version` on both representations. A site running it with markdown enabled and negotiation confirmed is **MDF 0.3.1 conformant for free content** — every MUST that applies to a free-only site. It does not take payment — see [Paid markdown (planned)](#paid-markdown-planned).

- **Project site:** [markdownfirst.com](https://markdownfirst.com)
- **Spec:** [github.com/bitcryptic-gw/mdf](https://github.com/bitcryptic-gw/mdf)
- **Reference implementation:** [github.com/bitcryptic-gw/mdf-reference-server](https://github.com/bitcryptic-gw/mdf-reference-server)
- **Live demo:** [mdf-demo.bitcryptic.com](https://mdf-demo.bitcryptic.com)

---

## Tests

`tests/` runs without PHPUnit and without a WordPress install: WordPress
functions are stubbed, and the serve path is exercised through PHP's built-in
web server so real response headers can be asserted.

```bash
php tests/unit.php
```

The suite does not start WordPress, WooCommerce or a page cache, so it does not
prove negotiation works end to end on any particular host. If you run a page
cache, a CDN, or WooCommerce, check it on a staging copy of your own site first,
then confirm with the [negotiation self-test](#negotiation-self-test).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

| Version | Date | Summary |
|---------|------|---------|
| 0.1.16 | 2026-11-28 | Added: `X-MDF-Version: 1` on cached HTML in WP Super Cache standard mode, via per-URL path markers read by the bundled adapter. Settings states whether cached-HTML header coverage is active, and why not when it is not. Fixed: every plugin-written file is 0644 and every directory 0755, independent of the caller's umask, with an upgrade that normalises the existing cache tree. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.15 | 2026-11-23 | Added: a free-only `/mdf.json` capability document (MDF 0.3.1), published only when markdown offering is on and negotiation is confirmed, 404 otherwise, with static-file shadow detection. Added: `X-MDF-Version: 1` on markdown responses and on the HTML at URLs that have a variant. Fixed: a delayed re-test after activation when the WP Super Cache adapter was just registered, so a stale compiled config cannot withhold the claim for a day. Changed: admin copy now says free markdown today and paid markdown over x402 planned; plugin header declares WordPress 6.0 / PHP 8.0; attribution is BitCryptic™ and Slepner. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.14 | 2026-10-25 | Added: WooCommerce cart, checkout (with `order-pay`/`order-received`), account (with every account endpoint) and shop pages are never offered as markdown, with upgrade cleanup of any variant already cached. Added: self-test negative probes on those URLs in both encodings — any markdown answer reports `blocked`. Added: llms.txt "Session-only pages" section on Woo sites. Fixed: non-singular views (archives) could be answered with an unrelated post's markdown; zero-byte variants are no longer served. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.13 | 2026-09-20 | Fixed: the WP Super Cache adapter is now a total bypass — on a markdown request it sets `$cache_enabled = false` and defines `DONOTCACHEPAGE`, so the request neither reads nor writes the page cache. The previous marker-keyed design could cache an HTML fallback under the markdown key and then serve it to later markdown requests. Fixed: self-test excludes query-string permalinks and probes both gzip and `identity` encodings. See [CHANGELOG.md](CHANGELOG.md). |
| 0.1.12 | 2026-09-20 | Fixed: the machine-readable `/llms.txt` claim is now published only when offering is enabled **and** negotiation is confirmed (self-test or owner override) — previously it was suppressed only on `blocked`, so offer-off sites published a false claim. Fixed: self-test URL selection now skips non-public post types, empty/missing `.md`, and unreachable URLs, probes up to three, and reports a markdown/HTML split as `blocked`. Added: owner confirmation override with a one-line per-state reason. See [CHANGELOG.md](CHANGELOG.md). |

Older releases (0.1.0 through 0.1.11) are listed in [CHANGELOG.md](CHANGELOG.md).

The `v0.1.5` tag exists, but it is not a 0.1.5 release: it points at `f8a8f74`, the follow-up commit that corrected the `MDF_VERSION` constant and README to match 0.1.4, and the plugin inside it declares 0.1.4. No 0.1.5 build was ever published, which is why the changelog goes from 0.1.4 to 0.1.6.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+

No Composer or npm runtime dependency — the plugin ships its dependencies vendored.

---

## License

MIT — copyright [BitCryptic™](https://bitcryptic.com) and [Slepner](https://slepner.com.au). See [LICENSE](LICENSE).
