# Changelog

All notable changes to MDF Analytics will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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

[Unreleased]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.3...HEAD
[0.1.3]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/bitcryptic-gw/mdf-analytics-wp/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/bitcryptic-gw/mdf-analytics-wp/releases/tag/v0.1.0
