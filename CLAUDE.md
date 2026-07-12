# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A TV-series tracker. Plain **PHP 8 + MySQL (MariaDB)**, vanilla JS/CSS, **no Composer/Node build step** — the stack is dictated by the host (InfinityFree free tier: PHP+MySQL only, no SSH, FTP-only deploys). Display name is **TVTrack** (English) / **TVTakip** (Turkish); the `tvtakip` name persists in the domain, DB name, repo, and folder. Live at `tvtakip.akifuygun.com`.

## Local development

Requires XAMPP at `C:\xampp` (PHP 8.2 + MariaDB). MySQL is started standalone, not as a service.

- **Run the app:** `start-dev.bat` — starts MySQL + Apache (phpMyAdmin) if needed and serves at `http://localhost:8000` via `php -S localhost:8000 router.php`.
- **`router.php` is mandatory for the dev server** — it mirrors the `.htaccess` rewrites (`/series/ttNNN`, `/browse`, `/upcoming`, `/tr/...`, `/sitemap.xml`). Plain `php -S` without it will not resolve pretty URLs.
- **DB setup:** create db `tvtakip`, import `schema.sql`; `copy config.sample.php config.php` (defaults match XAMPP: root / no password) and set `TMDB_API_KEY` + `ADMIN_EMAILS`.
- **There is no test suite.** Verify by driving the running site (curl/Invoke-WebRequest against `localhost:8000`, or a browser). Syntax-check JS with `node --check assets/js/app.js`. There is no linter.
- Test login used during development: `akifuygun@gmail.com`. MySQL often needs a few seconds after launch (InnoDB crash recovery) before it accepts connections.

## Deployment (InfinityFree specifics — read before deploying)

- **Code deploys = FTP upload of changed files** into the subdomain docroot `tvtakip.akifuygun.com/htdocs/` (creds in the InfinityFree panel; not in the repo). There is no git-based deploy. `config.php` is gitignored and lives only on each environment.
- **No remote MySQL.** Schema changes and data loads cannot be run from your machine against live. Do them with a **temporary, token-guarded PHP script** uploaded to the docroot, opened once in a browser, then deleted (see prior `migrate.php` / `sync_run.php` patterns — build them under `deploy/`, which is gitignored). `make_sync.php` generates an idempotent `INSERT ... ON DUPLICATE KEY UPDATE` dump of the shows/episodes cache for this; a resumable runner executes it in time-boxed slices because requests are killed after ~30s.
- **The host runs an anti-bot JS challenge**, so `curl`/non-browser fetches of live pages get an interstitial, not the page — you cannot verify live pages from the CLI; the user must open URLs in a browser. Real search-engine crawlers are whitelisted.
- **`curl_multi_*` is disabled** on the host even though `curl_multi_init` exists — `includes/http.php` falls back to sequential fetches (do not assume concurrency works live).
- HTTPS is forced via `.htaccess` (checks `X-Forwarded-Proto`, InfinityFree terminates SSL at its proxy).

## Architecture

**IMDB ids are the canonical keys** for both shows and episodes — no TVmaze/TMDB ids are ever stored (explicit product requirement). `episodes.imdb_id` is deliberately **not unique** (IMDB shares one id across two-part episodes); `(show_imdb_id, season, number)` is the only episode identity.

**Data model** (`schema.sql`): `shows` + `episodes` are a *shared cache* (populated from providers, same for all users); `users`, `user_shows`, `watched_episodes`, `remember_tokens` are per-user.

**All provider access is server-side** (`includes/importer.php` is the single import path). Clients only ever send IMDB ids to the JSON endpoints — the TMDB key never reaches the browser and the shared cache can't be poisoned by clients. `import_show()`:
- Fetches from **TMDB first** (it has per-episode IMDB ids via `/external_ids`), **TVmaze as fallback** for shows TMDB lacks.
- **Two-phase & resumable:** commits base show+episodes immediately (usable at once, even for huge shows), then backfills episode IMDB ids in small batches per import/refresh — because the host kills long requests. `db_live()` reconnects before writes since long provider fetches outlast idle MySQL connections.
- **Air times:** TVmaze `airstamp` (UTC) is stored in `episodes.airstamp`; TMDB has none, so TMDB imports get enriched with one TVmaze call. Upserts use `COALESCE(VALUES(...), ...)` so a provider with less data never erases better data. **Never `REPLACE INTO episodes`** — the FK cascade would delete watched history.

**Aired-gating** (whether an episode can be marked watched / shows on the calendar) uses the exact UTC `airstamp` when known, date-only fallback otherwise — via `aired_sql()` in `auth.php` (it appends one positional param; callers pass `today()`). Timezone is **per-user**: the browser reports its zone in a `tz` cookie, `app_timezone()` validates it (Istanbul fallback for crawlers/first hits). Do not reintroduce date-only aired checks.

**Pages** — public (indexable) vs app (login-gated):
- `series.php` (`/series/ttNNN`) is the **unified show page**: guests get server-rendered read-only content (SEO); logged-in users get the interactive app — `app.js` fills `#show-detail`, while the read-only markup is wrapped in `<noscript>` as the no-JS fallback. `show.php` is a 301 redirect here.
- Public server-rendered: `browse.php`, `upcoming.php`, `series.php`, `sitemap.php`, plus the guest landing in `index.php`. App pages (`index.php` calendar when logged in, `myshows.php`, `search.php`, auth pages) set `$noindex = true`.
- `/upcoming` is dual-purpose: full public catalog for guests, tracked-shows-only for logged-in users.

**i18n** (`includes/i18n.php`): one `t()` dictionary with `en` + `tr` arrays; `t()` also supports `%s`/`%d` via sprintf. The dictionary is emitted to the frontend as `window.I18N`, and `app.js` has its own `t()`. **When adding any user-facing string, add the key to BOTH the `en` and `tr` arrays and call `t()`** (PHP or JS) — never hardcode. Public pages are crawlable per language via a `/tr/` URL prefix; `current_lang()` honors the prefix over the cookie; the header emits hreflang alternates. `app_name()` returns TVTrack/TVTakip.

**Auth** (`includes/auth.php`): email login + 30-day sessions + 60-day remember-me tokens (selector/validator, sha256 of validator, rotates on use, all exception-guarded so a missing table degrades gracefully). Admin is config-based (`ADMIN_EMAILS`, not a DB column — chosen to avoid a live migration); `is_admin()` checks the session email; the header emits `<meta name="is-admin">` for the frontend. CSRF: forms use a hidden field, JSON APIs use the `X-CSRF-Token` header (`read_json_post()` enforces it).

**Frontend** (`assets/js/app.js`, single file): vanilla JS, `el()` DOM builder, per-page `init*()` functions run unconditionally (each no-ops if its root element is absent). **All fetch/asset URLs must be root-absolute (`/api/...`, `/assets/...`)** — pages render under subpaths like `/series/ttNNN`, so relative URLs resolve wrong; `apiPost`/`apiGet` root-anchor their argument for this reason.

**PWA:** `manifest.webmanifest` + `sw.js` (service worker). The SW does stale-while-revalidate for same-origin static assets only (never HTML/`/api/`). **Bump the `CACHE` constant in `sw.js` whenever you change a cached asset (CSS/JS/icons)** or clients keep the stale copy.

## CLI utilities (`scripts/`, run locally)

`import_popular.php` (ensure N most-popular TMDB shows are cached), `import_watchlist.php` (wipe + import a Trakt-style JSON — destructive), `backfill_images.php`, `backfill_airstamps.php`, `make_sync.php` (generate the live-sync SQL). All bootstrap `includes/importer.php` or `includes/auth.php`.
