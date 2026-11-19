# TVTrack

A web app to track TV series and movies: search, follow shows, mark episodes as
watched, and keep a movie watchlist.
(English name TVTrack, Turkish name TVTakip; the `tvtakip` name persists in the domain, repo, and DB.)

- **Stack:** Plain PHP 8 + MySQL, vanilla JS/CSS
- **Canonical ids:** IMDB ids for shows, episodes, and movies — no third-party ids in the DB
- **Installable PWA**, mobile-friendly (hamburger nav), and **bilingual** — English
  (TVTrack) / Turkish (TVTakip), switchable via footer flags.
- **Footer switches:** light/dark theme, and on wide screens a top-bar or
  side-panel layout (header + footer combined into one left panel).

## How it works

- **All provider access is server-side** (`includes/importer.php`): the browser only
  ever sends IMDB ids to our JSON endpoints. The TMDB API key never leaves the server,
  and no provider data is accepted from clients (the shared cache can't be poisoned).
- **Search** (`api/search.php`) queries [TVmaze](https://www.tvmaze.com/api) (free, no
  key, fuzzy) and [TMDB](https://developer.themoviedb.org/) (free API key) and merges
  results by IMDB id, so shows missing from either provider still appear. Search is
  **public** (shows and movies), and visiting any not-yet-cached `/series/ttNNN` or
  `/movie/ttNNN` imports it on first visit — every visitor grows the shared catalog;
  tracking/watching stays login-only.
- **Freshness is scheduled:** a cron worker (`scripts/refresh_worker.php`, every
  minute) re-imports shows that are due — synced more than 12 h ago while an episode
  airs within two weeks, otherwise 48 h, or a week once ended or canceled (so renewals
  and status corrections still arrive); tracked and airing shows first.
- **"Refresh all shows"** (My Shows) is a background job: the button files a request
  in `refresh_requests`, the same worker re-imports every show the user tracks, and
  the page shows the progress from the database — closing the tab doesn't stop it.
- **Episode data** comes from TMDB, including each episode's IMDB id via its
  `external_ids` endpoint, with TVmaze as fallback for shows TMDB doesn't know
  (their episode IMDB ids stay empty and are backfilled on refresh once TMDB has them).
  Imports run when a show is tracked or first opened and land in the `shows` /
  `episodes` cache tables — everyone after reads our DB. Episodes are identified by
  show IMDB id + season + number; episode IMDB ids are deliberately not unique
  (IMDB lists two-part episodes under one id).
- **Three providers, combined** — each fills what the others lack:
  - *Air times:* TVmaze's exact per-episode times; for episodes it has none for
    (most streaming drops) the show's regular air time from
    [TheTVDB](https://thetvdb.com) (optional API key), in the show's home timezone.
  - *Announced episodes:* TheTVDB often lists upcoming episodes before TMDB does;
    those are added until TMDB lists them, and kept after they air.
  - *Episode numbering:* a few curated shows whose TMDB numbering is wrong (e.g. a
    skipped season) take their regular episodes from TheTVDB instead.
  - *Premiere / finale badges* on the calendar and Upcoming: finales from TMDB's
    episode type, with TheTVDB marking which one is the series finale.
  - *Turkish descriptions* for Turkish pages: TMDB translations, TheTVDB as fallback.
  - *Poster slider:* clicking a poster opens the poster plus up to 9 large backdrops
    (TMDB, text-free first; TheTVDB backgrounds top up shows with few), loaded one
    slide ahead as you browse.
  - *Trailers:* a "▶ Trailer" badge on show and movie pages plays the YouTube
    trailer in an overlay (Turkish one on Turkish pages when available; TMDB,
    TheTVDB filling missing languages), via the privacy-enhanced player, loaded
    only on click.
- **Rules:** unaired episodes can't be marked watched; untracking keeps watched
  history; show statuses are normalized to running/upcoming/ended/canceled.
- **Movies:** a single per-user list with a watched flag (a movie is one watchable
  unit — no per-episode state). TMDB-only import; search + add live on the
  My Movies page; marking watched auto-adds to the list and is gated on the
  release date. Public movie pages at `/movie/ttNNN` and a public catalog at
  `/movies`.
- **Accounts:** email login, display name shown in the header, self-service
  change-password. 30-day sessions plus a 60-day "remember me" token so a shared
  host's short session GC doesn't force frequent logins.
- **Admin** (emails listed in `ADMIN_EMAILS`): can set/remove a show's poster
  (shared cache) — the "no image" placeholder is admin-only. Everyone else is
  refused server-side.
- **i18n:** all strings live in one dictionary (`includes/i18n.php`), also handed
  to the frontend as `window.I18N`; language kept in a `lang` cookie, and public
  pages are crawlable per language under a `/tr/` prefix.

## Project structure

```
index.php          Calendar — each tracked show's next unwatched episode airing
                   today or earlier (guests get the public landing page)
myshows.php        Tracked shows in tabs (Running / Upcoming + Cancelled / Ended),
                   most-behind first
mymovies.php       Movie list: on-page search to add, To watch / Watched tabs
search.php         Search both providers (public; Track buttons when logged in)
series.php         Unified show page (/series/ttNNN): public SEO for guests,
                   interactive app when logged in; uncached ids import on visit
                   (show.php 301-redirects here)
movie.php          Public movie page (/movie/ttNNN) + add/watched buttons;
                   uncached ids import on visit
browse.php         Public show catalog: provider search bar + network/genre/
                   status/name filter tabs, network logo badges on posters
movies.php         Public movie catalog (/movies) with guest search
upcoming.php       Upcoming episodes (public catalog; tracked-only when logged in)
change-password.php  Self-service password change
login.php / register.php / logout.php   (email login, display name in header)
api/search.php     GET merged TVmaze+TMDB show search (server-side, public)
api/track.php      POST: track/untrack by IMDB id (imports server-side if uncached)
api/episodes.php   GET cached show+episodes+watched; POST imports/refreshes server-side
api/watch.php      POST toggle episode watched; bulk (un)watch per show or season
api/movies.php     GET movie search; POST add/remove/watch (single endpoint)
api/refresh.php    POST files a "Refresh all shows" request; GET reads its progress
scripts/refresh_worker.php  Cron worker: works off those requests and re-imports
                   shows that are due a refresh
api/image.php      POST set/remove a show poster (admin only)
includes/          db.php, auth.php, errors.php, i18n.php, http.php, importer.php,
                   refresh.php, network_logos.php, header.php (+ site_footer.php),
                   footer.php
assets/            css/style.css, js/app.js, icons/ (PWA + logo)
manifest.php, sw.js   Localized PWA manifest + service worker (static-asset cache)
sitemap.php        Dynamic sitemap (shows + movies, en + tr)
scripts/           CLI utilities: deploy.php (deploy.bat runs it), migrate.php,
                   import_popular.php, import_watchlist.php, backfill_images.php,
                   backfill_airstamps.php, make_sync.php, make_restore.php
migrations/        The MySQL schema as numbered migrations: 0001_baseline.sql
                   (users, shows, episodes, movies, user_shows, watched_episodes,
                   user_movies, remember_tokens, refresh_requests), then one
                   file per change — applied by scripts/migrate.php
config.sample.php  Copy to config.php: DB credentials, TMDB_API_KEY, TVDB_API_KEY
                   (optional), ADMIN_EMAILS
```

## Local development

Requires XAMPP (installed at `C:\xampp`, PHP 8.2 + MariaDB). One-time setup:

1. Create the database:
   `C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE tvtakip CHARACTER SET utf8mb4"`
2. `copy config.sample.php config.php` (defaults already match XAMPP: root, no password),
   set `TMDB_API_KEY` (free key from https://www.themoviedb.org/settings/api), and add
   your email to `ADMIN_EMAILS` if you want admin controls. Optionally set
   `TVDB_API_KEY` (https://thetvdb.com/dashboard/account/apikey — free under $50k/yr
   revenue, attribution required and shown in the footer) for streaming air times,
   early announced episodes, series-finale badges and extra Turkish descriptions;
   without it the site works on TMDB + TVmaze alone.
3. Create the tables: `C:\xampp\php\php.exe scripts\migrate.php`

Then run `start-dev.bat` — it starts MySQL if needed and serves the site at
http://localhost:8000.

## Schema changes

The schema lives in `migrations/` as numbered files; the `schema_migrations` table
records which ones a database has had. `php scripts/migrate.php` applies the pending
ones in order (`--status` only lists them) — the same command locally, on the server
over SSH, and on an empty database, where `0001_baseline.sql` creates everything.

To change the schema, add the next file — `NNNN_what_it_does.sql` with plain
statements, or `.php` returning a `function (PDO $pdo): void` when the change needs
code — and never edit one that has been applied:

- **One schema change per file.** MySQL commits each on the spot, so a file that
  fails halfway can't be rolled back (a file that only changes rows can, and is).
- **It must run on both MySQL 8.0 (live) and MariaDB 10.4 (local):** no
  `ADD COLUMN IF NOT EXISTS`; new tables end in
  `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci`.
- **Name new foreign keys** (`CONSTRAINT fk_… FOREIGN KEY …`). The baseline's are
  auto-named and differ from database to database, so a migration that drops one
  has to look its name up in `information_schema` first.
- No "down" migrations: undo with a new migration, or restore a dump.
- **Live gets a migration before the code that came with it** (see Deployment), so
  it has to leave the code that is live at that moment working: add first; drop or
  rename in a later deploy, once nothing deployed uses the old shape. For the same
  reason a `.php` migration uses the connection and `config.php`, not code that
  arrives in the same commit.

## Deployment

The live site is a git checkout of `main` on the server. Commit, push, then run

```
deploy.bat             (.\deploy in PowerShell; = C:\xampp\php\php.exe scripts\deploy.php)
```

It reaches the server as `ssh hostuno` (a `Host` alias in `~/.ssh/config`) and stops
with `DEPLOY FAILED` and exit code 1 at the first thing that is wrong:

1. **Here** — the checkout must be on `main` with nothing uncommitted (an untracked
   file counts: it would be missing on live) and nothing unpushed. If CSS/JS changed
   since the commit live runs, `ASSET_VERSION` and the `CACHE` name in `sw.js` must
   have changed too.
2. **On the server** — the live checkout must have no changes of its own and be able
   to fast-forward to exactly this commit. Pending migrations run first, while the
   old code still serves, after a dump of the database to
   `~/dbdumps/tvtakip-<date>-before-<commit>.sql.gz`. Only then the code is switched;
   if a migration fails, the old code stays live.
3. **From here** — about twenty requests to the live site as a guest: the public
   pages answer 200 and carry the commit's asset version, the login-gated ones turn
   a guest away, the assets and the service worker are served, and `.git/`,
   `scripts/` and `migrations/` are refused.

`deploy.bat --check` runs only step 3, against whatever commit live is on. Deploying
again when live is current changes nothing and repeats the checks. Pages behind the
login are not requested — look at those yourself after a deploy that touches them.

### Rolling back

The deploy names the commit live ran before (`Deploying to live, which runs …`);
`git reflog` in the live checkout lists the earlier ones.

1. Put that commit back — it takes a second, and files git doesn't track there
   (`config.php`) are left alone:
   `ssh hostuno "git -C domains/tvtakip.akifuygun.com/public_html reset --hard <commit>"`
2. `deploy.bat --check` confirms the site is healthy on it.
3. `main` still holds the bad commit, and the next deploy would put it back:
   `git revert` it (or fix it), push, deploy.

Migrations are not undone by this. They don't need to be: a migration has to leave
older code working, so the commit before runs on the newer schema (`migrate.php`
then mentions a migration it has no file for — harmless). When the schema change
itself is the problem, undo it with a new migration, or restore the dump the deploy
took just before migrating — that loses everything written since:
`ssh hostuno 'gunzip -c ~/dbdumps/<dump> | mysql <database>'`. The dump carries
`schema_migrations`, so the next deploy applies the migration again.

## Data sources

Show and movie data from [TMDB](https://www.themoviedb.org/),
[TVmaze](https://www.tvmaze.com/) and [TheTVDB](https://thetvdb.com) (metadata
provided by TheTVDB; please consider adding missing information or subscribing).
This product uses TMDB and the TMDB APIs but is not endorsed, certified, or
otherwise approved by TMDB. All three providers require attribution — the site
footer carries a "Data: TMDB · TVmaze · TheTVDB" line with links; keep it.
