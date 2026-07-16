# Changelog

All notable changes to MDF Analytics will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- Prevent WP Super Cache from caching markdown responses by defining
  `DONOTCACHEPAGE` in `mdf_maybe_serve_markdown()` before serving output.
  WPSC internally maps `text/markdown` to `text/html` in `wpsc_get_accept_header()`,
  which caused markdown and HTML responses for the same URL to share a cache
  key — whichever representation was cached first would be served to ALL
  subsequent requests regardless of their `Accept` header.  This fixes the
  "markdown gets cached and served to HTML requesters" direction.
- Page-builder shortcode expansion (e.g. Divi's `[et_pb_*]` shortcodes) now works
  in WP-Cron context by establishing proper singular-post query context and
  force-loading page-builder modules that gate on `is_singular()`/`get_the_ID()`.

### Known limitation
- The reverse WP Super Cache race condition (HTML cached first, blocking
  markdown requests) is not fixed by this release.  It requires a change in
  WP Super Cache's own plugin extension directory to teach `wpsc_get_accept_header()`
  about `text/markdown`.  This is tracked as a separate operational task.

## [0.1.6] - 2026-07-16

### Fixed
- Backfill batch processor (`mdf_cron_backfill_batch()`) no longer counts a post as
  processed when its conversion/write actually failed — `mdf_backfill_processed` now
  reflects successful conversions only, not attempts. Previously the counter incremented
  unconditionally regardless of the return value of `mdf_convert_post()`, which could
  make the admin UI report a completed backfill with zero cache files actually written.
- Added a writability check for the markdown cache directory (`wp-content/uploads/mdf-cache/posts/`)
  before attempting any write, in both the batch processor and `mdf_convert_post()` directly.
  If the directory is not writable by the web server user, the failure is now recorded and
  surfaced as a visible admin notice on the Settings page (`mdf_cache_writable_error` option),
  instead of failing silently. The notice self-clears once the directory becomes writable again.
- `mdf_create_cache_dirs()` now also attempts `chmod 0775` on the cache base and `posts/`
  directories at creation time, as a best-effort mitigation for installs where the directory
  ends up owned by a different user than the one serving requests.

### Changed
- Settings page copy: "posts" replaced with "content" in the markdown-offering toggle
  description and backfill progress status line, since the pipeline covers pages and
  custom post types as well as posts.
- Settings page "Phase 3 (roadmap)" description updated to "Phase 3 — shipped" since
  the CommonMark auto-generation pipeline is now live. Phase 2 (wallet/earning) remains
  the only future roadmap item.

### Known limitation
- If plugin activation runs as a different user than the web server process (e.g. root during
  a Docker build step, vs. `www-data` serving real requests), the `chmod` added above may not
  be sufficient to make the directory writable, since it changes permission mode but not
  ownership. The runtime writability check and admin notice are the actual safety net in that
  case. A cleaner fix (explicit ownership correction, or moving directory creation to first-request
  time under the serving user) may be considered for a future release if this recurs.

## [0.1.4] - 2026-07-09

### Added
- Vendored `league/html-to-markdown` (v5.1.1) with namespace scoping (`MdfAnalytics\Vendor\League\HTMLToMarkdown`) to eliminate class-redeclaration collisions if another plugin also vendors the same upstream library. No Composer required at runtime — the library is source-committed. Scoped via `humbug/php-scoper`, built by `build-vendor.sh`.
- Pre-build markdown cache pipeline: all published posts are converted to CommonMark on save (via WP-Cron background job) and served to clients that send `Accept: text/markdown`. Markdown is never served for a URL until a pre-built `.md` file actually exists for it — no live-conversion fallback.
- Cache directory at `wp-content/uploads/mdf-cache/` with flat `posts/` layout, per-post `{post_id}.md` + `{post_id}.meta.json` sidecars, and a `manifest.json` aggregate.
- Settings toggle "Offer markdown to agents" — enabling it auto-queues a full backfill of all published posts. Disabling stops markdown serving immediately.
- `save_post` hook computes content hash (SHA-256 of `the_content()` post-filter output) and skips rebuilds when unchanged. Atomic temp-file + rename writes keep the old `.md` servable throughout a rebuild.
- `template_redirect` negotiation gating: checks `file_exists()` against the cached `.md` (single filesystem stat, no sidecar JSON read at request time), sets `Vary: Accept` and `Content-Type: text/markdown` only when cached content exists.
- Backfill uses batched WP-Cron (`mdf_backfill_batch`) to avoid flooding the cron queue; admin notice shows progress during backfill.

### Fixed
- Manifest read-modify-write race: `manifest.json` updates are now wrapped with `flock(LOCK_EX)`, preventing concurrent WP-Cron-driven writes (e.g. overlapping `mdf_markdown_rebuild` and `mdf_backfill_batch` events) from silently clobbering each other. The lock provides advisory exclusion around `mdf_manifest_record_result()` and the backfill-completion path; the existing atomic temp-file + rename is retained for reader safety.

---

## [0.1.3] - 2026-06-10

### Added
- Plugin now serves a curated `llms.txt` at the site root (`/llms.txt`), sourced from the file shipped in the plugin directory. Supports GET/HEAD, `Last-Modified`/`If-Modified-Since` conditional requests, and 1-hour public caching. Requests are logged through the existing analytics classifier.

---

## [0.1.2] — 2026-06-10

### Fixed

- Type-2 (known agent) UA snippet now stores the matched agent fragment rather than the first token of the raw UA string. Bots that send `Mozilla/5.0 (compatible; Googlebot/2.1; ...)` style UAs now display as `Googlebot` in the dashboard rather than the misleading `Mozilla` prefix. Affects all standard search and AI crawler UAs. Historical rows are unaffected and will age out within the 90-day retention window.

### Changed

- `mdf_classify_ua()` and `mdf_ua_snippet()` consolidated into a single `mdf_classify_ua_with_snippet()` function that performs classification and snippet extraction in one pass, eliminating redundant UA string traversal.

---

## [0.1.1] — 2026-06-09

### Fixed

- WordPress core self-calls (`WordPress/`), Jetpack, and uptime monitors (UptimeRobot, Pingdom, etc.) now correctly classified as type-3 (internal/monitor) and excluded from agent counts and estimated earnings. Sites running v0.1.0 will have these requests miscategorised as type-1 or type-2 in historical data; rows clear automatically within 90 days, or truncate `wp_mdf_requests` manually to reset immediately.

---

## [0.1.0] — 2026-06-08

### Added

- Initial release.
- Passive request logging on WordPress shutdown hook — no content modification, no payment processing.
- Visitor classification: type-2 known agent (40+ UA fragments covering AI assistants, agentic frameworks, search crawlers, and generic HTTP clients), type-1 likely automated (heuristic — no browser engine markers), type-3 internal/monitor (WordPress platform calls, uptime monitors, CDN health probes), type-0 human (not logged unless `Accept: text/markdown` present).
- `wants_markdown` flag logged per request when `Accept: text/markdown` is present in the request headers.
- 90-day retention via daily WP-Cron purge.
- Admin dashboard with 7/30/90-day window selector, seven stat cards (total requests, known agents, likely automated, internal/monitors, wanted markdown, estimated earned, estimated missed), daily bar chart, top agents table, internal/monitors table, top markdown-requested paths table.
- Settings page: currency selector (sats / USDC), configurable per-request rate for earnings estimates.
- Phase 2 callout CTA in dashboard footer.
- No IP addresses stored. No external HTTP requests. No dependencies beyond WordPress core.

[Unreleased]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/bitcryptic-gw/mdf-analytics-wp/releases/tag/v0.1.0
