# TVTrack

A web app to track TV series and movies: search, follow shows, mark episodes as
watched, and keep a movie watchlist. Live at https://tvtakip.akifuygun.com.
(English name TVTrack, Turkish name TVTakip; the `tvtakip` name persists in the domain, repo, and DB.)

- **Stack:** Plain PHP 8 + MySQL, vanilla JS/CSS — no Node, no build step, and no
  package the site needs: Composer only brings the development tools (PHPUnit,
  PHPStan), which never go to live
- **Canonical ids:** IMDB ids for shows, episodes, and movies. A title IMDB doesn't
  list goes by a provisional id until it does (below)
- **Installable PWA**, mobile-friendly (hamburger nav), and **bilingual** — English
  (TVTrack) / Turkish (TVTakip), switchable via footer flags.
- **Footer switches:** light/dark theme, and on wide screens a top-bar or
  side-panel layout (header + footer combined into one left panel).

## How it works

- **All provider access is server-side** (`includes/importer.php`): the browser only
  ever sends a title's id to our JSON endpoints. The TMDB API key never leaves the
  server, and no provider data is accepted from clients (the shared cache can't be
  poisoned).
- **Search is public** (`public/api/search.php` for shows, `public/api/movies.php`
  for movies). A show search asks [TVmaze](https://www.tvmaze.com/api) (free, no
  key, fuzzy) and [TMDB](https://developer.themoviedb.org/) (free API key) and merges
  the results by IMDB id, so shows missing from either provider still appear; "More
  results" loads TMDB's further pages, 20 titles each. Nothing is cached — every
  search asks the providers. Visiting any not-yet-cached `/series/ttNNN` or
  `/movie/ttNNN` imports it on first visit — every visitor grows the shared catalog;
  tracking/watching stays login-only.
- **Titles IMDB doesn't list** can be tracked too. They are stored under a
  provisional id — `tm` + the TMDB id, or `mz` + the TVmaze id for a show only
  TVmaze has — in the same column and on the same addresses (`/series/tm12345`).
  Every import asks the provider whether it links an IMDB id by now; once it does,
  the title moves to that id together with its tracking and watched marks, and the
  old address redirects.
- **Freshness is scheduled:** a cron worker (`scripts/refresh_worker.php`, every
  minute) re-imports shows that are due — synced more than 12 h ago while an episode
  airs within two weeks, otherwise 48 h, or a week once ended or canceled (so renewals
  and status corrections still arrive); tracked and airing shows first. No page view
  triggers a refresh. A movie is imported once and not refreshed — except, daily,
  one still under a provisional id.
- **The "Refresh" button on a show page** re-imports that one show at once, and is
  the only thing that also removes episodes the providers no longer list (never
  one marked watched).
- **"Refresh all shows"** (My Shows) is a background job: the button files a request
  in `refresh_requests`, the same worker re-imports every show the user tracks, and
  the page shows the progress from the database — closing the tab doesn't stop it.
- **Episode data** comes from TMDB, with TVmaze as fallback for shows TMDB doesn't
  know. Imports run when a show is tracked or first opened and land in the `shows` /
  `episodes` cache tables — everyone after reads our DB. Episodes are identified by
  show id + season + number; episode IMDB ids are deliberately not unique (IMDB
  lists two-part episodes under one id).
- **Episode IMDB ids** cost one TMDB call per episode (its `external_ids` endpoint;
  there is no bulk one). An import started by a page view looks up at most 120 and
  leaves a larger show's ids to the cron worker, which completes it at its next
  run. That TMDB has no id for an episode is remembered too, so it is asked again
  only now and then.
- **Three providers, combined** — each fills what the others lack:
  - *Air times:* TVmaze's exact per-episode times; for episodes it has none for
    (most streaming drops) the show's regular air time from
    [TheTVDB](https://thetvdb.com) (optional API key), in the show's home timezone.
    An episode a streaming service releases a day or two ahead of its broadcast is
    dated by that earlier release.
  - *Announced episodes:* TheTVDB often lists upcoming episodes before TMDB does;
    those are added until TMDB lists them, and kept after they air.
  - *Episode numbering:* a few curated shows whose TMDB numbering is wrong (e.g. a
    skipped season) take their regular episodes from TheTVDB instead.
  - *Premiere / finale badges* on the calendar and Upcoming: finales from TMDB's
    episode type, with TheTVDB marking which one is the series finale.
  - *Turkish descriptions and episode names* for Turkish pages: TMDB's
    translations (TheTVDB as fallback for a show's description); where there is
    none, the English text.
  - *Poster slider:* clicking a poster opens the poster plus up to 9 large backdrops
    (TMDB, text-free first; TheTVDB backgrounds top up shows with few), loaded one
    slide ahead as you browse.
  - *Trailers:* a "▶ Trailer" badge on show and movie pages plays the YouTube
    trailer in an overlay (Turkish one on Turkish pages when available; TMDB,
    TheTVDB filling missing languages), via the privacy-enhanced player, loaded
    only on click.
- **Rules:** unaired episodes can't be marked watched — by the exact air time where
  a provider gives one, else by the date, in the viewer's own time zone (the browser
  reports it; Istanbul until it has); untracking keeps watched history; show
  statuses are normalized to running/upcoming/ended/canceled.
- **Movies:** a single per-user list with a watched flag (a movie is one watchable
  unit — no per-episode state). TMDB-only import; search + add live on the
  My Movies page; marking watched auto-adds to the list and is gated on the
  release date. Public movie pages at `/movie/ttNNN` and a public catalog with
  its own search at `/movies`.
- **TV view:** `/tv` is the calendar for a television's browser, where the remote
  moves a pointer: big posters as tiles or a list, marked watched in place.
- **Accounts:** anyone can register; email login, display name shown in the
  header, self-service change-password and "Log out on all devices" on the same
  page. A session ends after two hours without a request, or when the
  browser closes; "remember me" (a 60-day token, ticked by default on the login
  form) then logs the browser back in unnoticed. Without it, logging in lasts
  for that browser session only. A forgotten password is reset by e-mail
  ("Forgot your password?" on the login and change-password pages): the link
  works once, for 60 minutes, and saving the new password logs the account out
  on all devices. The page never says whether an address has an account; one
  client gets 10 requests an hour, one account 3 links an hour.
- **Admin** (an account with the `admin` role — see "Admins" below): can
  set/remove a show's poster and set its primary network (shared cache) — the
  "no image" placeholder is admin-only. Everyone else is refused server-side.
  Admins alone also get per-episode "Watch" links, when `WATCH_SITES` in
  `config.php` names sites for them (`config.sample.php` has the format). When a
  site moves to another domain, the ❤ in the footer sets its new one; that is
  kept in the database (`settings` table), the paths stay `config.php`'s.
- **i18n:** all strings live in one dictionary (`includes/i18n.php`), also handed
  to the frontend as `window.I18N`; language kept in a `lang` cookie, and public
  pages are crawlable per language under a `/tr/` prefix.

## Project structure

The web server is given `public/` and nothing else. Everything a visitor must not
reach — the configuration, the include files, the scripts, the migrations, `.git` —
is beside it and so has no URL.

```
public/                The web root: a file's path in here is its URL
  index.php            Calendar — each tracked show's next unwatched episode airing
                       today or earlier; when there is none, what comes next
                       (guests get the public landing page)
  myshows.php          Tracked shows in tabs (Running / Upcoming + Cancelled / Ended),
                       most-behind first; the "Refresh all shows" button
  mymovies.php         Movie list: on-page search to add, To watch / Watched tabs
  search.php           Search both providers (public; Track buttons when logged in)
  series.php           Unified show page (/series/ttNNN): public SEO for guests,
                       interactive app when logged in; uncached ids import on visit
                       (show.php 301-redirects here)
  movie.php            Public movie page (/movie/ttNNN) + add/watched buttons;
                       uncached ids import on visit
  browse.php           Public show catalog (/browse): provider search bar + network/
                       genre/status/name filter tabs, network logo badges on posters
  movies.php           Public movie catalog (/movies) with guest search
  upcoming.php         Upcoming episodes as a month calendar (/upcoming): every
                       cached show's for a guest, the tracked shows' when logged in
  tv.php               The calendar's TV view (/tv), with its own assets/css/tv.css
                       and assets/js/tv.js
  change-password.php  Self-service password change; "Log out on all devices"
  login.php / register.php / logout.php   (email login, display name in header)
  forgot-password.php  Mails a link to set a new password (includes/password_reset.php)
  reset-password.php   Where that link leads: the new password, then every device
                       of the account is logged out
  api/search.php       GET merged TVmaze+TMDB show search, in pages (public)
  api/track.php        POST: track/untrack by id (imports server-side if uncached)
  api/episodes.php     GET cached show+episodes+watched; POST imports/refreshes server-side
  api/watch.php        POST toggle episode watched; bulk (un)watch per show or season
  api/movies.php       GET movie search, in pages (public); POST add/remove/watch
  api/refresh.php      POST files a "Refresh all shows" request; GET reads its progress
  api/image.php        POST set/remove a show poster (admin only)
  api/network.php      POST set a show's primary network (admin only)
  api/watch_slug.php, api/watch_base.php
                       POST a show's name, or a site's address, for the Watch links
                       (admin only)
  assets/              css/ (style.css, tv.css), js/ (app.js, tv.js), icons/ (PWA +
                       logo), networks/ (logos). CSS/JS URLs carry
                       ?v=<hash of the files' contents> (includes/assets.php), so an
                       edit is a new URL by itself — there is no version to bump
  manifest.php, sw.js  Localized PWA manifest + service worker (static-asset cache,
                       named after that same version)
  sitemap.php          Dynamic sitemap (/sitemap.xml: shows + movies, en + tr)
  robots.txt           Everything but /api/ may be crawled
  health.php           /health — what the uptime monitor asks ("Monitoring")
  .htaccess            HTTPS redirect, response headers, pretty URLs, asset caching
includes/              db.php, auth.php, errors.php, i18n.php, http.php, importer.php,
                       refresh.php, calendar.php, network_logos.php, assets.php,
                       mail.php, password_reset.php,
                       header.php (+ site_footer.php), footer.php
scripts/               Command-line only: deploy.php (deploy.bat runs it), check.php
                       (the static analysis and the tests; check.bat runs it), migrate.php,
                       refresh_worker.php (the cron worker: works off "Refresh all
                       shows" requests and re-imports shows that are due),
                       admin.php (who is an admin), backup_pull.php (copies the
                       server's backups to this machine) and backup_check.php
                       (on the server: mails when a backup is missing),
                       error_report.php (on the server: mails what is new in the
                       error logs), load_live.php (live's newest backup into this
                       machine's database), import_popular.php, import_watchlist.php,
                       backfill_images.php, backfill_airstamps.php,
                       backfill_names_tr.php. All but deploy.php, the two backup
                       scripts and error_report.php work on the database of the
                       checkout they run in: here, or on the server over SSH
migrations/            The MySQL schema as numbered migrations: 0001_baseline.sql
                       (users, shows, episodes, movies, user_shows, watched_episodes,
                       user_movies, remember_tokens, refresh_requests), then one
                       file per change — applied by scripts/migrate.php. Values
                       the site sets at run time (a Watch site's new domain) are
                       rows of its settings table, not files
tests/                 PHPUnit tests (below, "Tests and static analysis"): Unit/ (no
                       database), Integration/ (a database of their own), Support/
                       (stand-ins for the providers, the database's set-up),
                       bootstrap.php (the configuration the tests run with)
composer.json, composer.lock
                       The development tools, installed into vendor/ (not in git);
                       phpunit.xml.dist and phpstan.neon.dist configure them
dev/                   Local development: setup.ps1 (setup-dev.bat runs it) installs
                       live's PHP and MySQL versions in C:\tvtakip-dev, with php.ini
                       and my.ini, live's settings, and Composer
router.php             The pretty URLs for PHP's built-in server (local development)
start-dev.bat          Starts the local site (below), on what setup-dev.bat installs;
                       check.bat runs the static analysis and the tests, deploy.bat
                       deploys ("Deployment")
config.sample.php      Copy to config.php, here beside public/: DB credentials,
                       TMDB_API_KEY, TVDB_API_KEY (optional), SESSION_DIR and
                       MAIL_FROM (on a server), WATCH_SITES (optional)
```

## Local development

The site runs here on the PHP and MySQL versions live runs, set up like live's (PHP
8.3 and MySQL 8.0 at the moment), so code that would behave differently on live
does so here first. They are installed in `C:\tvtakip-dev`, beside XAMPP, which
only serves phpMyAdmin now. One-time setup:

1. `setup-dev.bat` asks live for its versions over `ssh hostuno` and installs them
   in `C:\tvtakip-dev` (about 1 GB):
   - `php\`: PHP for Windows from php.net, checked against the SHA-256 php.net
     publishes, with `dev\php.ini` copied in. That file has live's settings.
   - `mysql\`: MySQL from mysql.com, checked against MySQL's signature with the
     GnuPG that comes with Git for Windows, with `dev\my.ini` copied in. That file
     has live's server settings (a `sql_mode` that is not strict, the collations,
     and others). The server listens on this machine only.
   - `data\`: the database `tvtakip`, with live's default collation, and the
     account `tvtakip@localhost` (no password), which has every right on that
     database and none elsewhere, like live's account — and on `tvtakip_test`, the
     tests' own database.
   - `composer.phar`: Composer, the version `dev\setup.ps1` names, checked against
     Composer's signature. It installs the development tools `composer.lock` lists
     into the checkout's `vendor\` (`composer install`).

   Without SSH, name the versions: `setup-dev.bat -PhpVersion 8.3.37 -MysqlVersion 8.0.43`.
2. `copy config.sample.php config.php`. Its database settings are the ones step 1
   created. Set `TMDB_API_KEY` (free key from https://www.themoviedb.org/settings/api).
   Optionally set `TVDB_API_KEY` (https://thetvdb.com/dashboard/account/apikey — free under $50k/yr
   revenue, attribution required and shown in the footer) for streaming air times,
   early announced episodes, series-finale badges and extra Turkish descriptions;
   without it the site works on TMDB + TVmaze alone.
3. `setup-dev.bat` again. Now that `config.php` exists, it creates the tables
   (`scripts\migrate.php`).

Then run `start-dev.bat`. It starts MySQL in a minimized window of its own, which
keeps running until that window is closed, and starts XAMPP's Apache, when there is
one, for phpMyAdmin at http://localhost/phpmyadmin. Then it serves the site at
http://localhost:8000 with PHP's built-in server: `public/` is the web root and
`router.php` adds the pretty URLs. The server also gets the limits live's web PHP
(PHP-FPM) adds to its php.ini: `exec()`, `proc_open()` and similar functions are
disabled, files outside the checkout and `C:\tvtakip-dev\tmp` are out of reach, a
request may take 300 s, and output is buffered. The command-line scripts run without
those limits, there as here.

**Live's data:** `C:\tvtakip-dev\php\php.exe scripts\load_live.php` replaces the local
database with live's newest backup: the newest dump in the server's `~/dbdumps`,
either the nightly one or one a deploy made before a migration. It copies the dump
and compares it with the server's SHA-256, asks before it empties the database,
loads the dump, and then applies this checkout's pending migrations, as a deploy
would. This takes about 20 seconds. The accounts come along with their password
hashes: log in with the live password. To load another dump, name one from the
server or a `.sql.gz` on this machine, such as an off-site copy; `--yes` skips the
question. It only replaces a database on this machine: run on the server by mistake,
it refuses.

**When live's versions change** (hostUNO updates PHP when it sees fit), a deploy ends
with a `NOTE:` line. Stop the site and run `setup-dev.bat` again. It replaces `php\`
or `mysql\` and keeps `data\`, and a newer MySQL upgrades the data directory when it
starts. An older MySQL can't use that directory: delete `C:\tvtakip-dev\data`, then
run `setup-dev.bat` and `load_live.php`. After changing `dev\php.ini` or `dev\my.ini`,
run `setup-dev.bat` again too: it copies them, and a running MySQL reads its file
at the next start.

What stays different from live: Windows stores table names in lower case
(`lower_case_table_names` is 0 on live, so keep table names lower case there too);
MySQL's clock here is Windows' time zone, live's is CET (harmless while stored
times are compared with the database's own `NOW()` and `CURDATE()`); the PECL
extensions live has and PHP for Windows doesn't (APCu, Imagick, Redis and others,
none of which the site uses), and `pcntl` and `posix`; and PHP's built-in server in
place of live's nginx + Apache + PHP-FPM, which read `.htaccess` (router.php repeats
its rewrites and headers). XAMPP's MariaDB isn't used anymore, and its data stays in
`C:\xampp\mysql\data`. It mustn't run alongside: both want port 3306, and
`start-dev.bat` stops when it finds MariaDB there.

No mail leaves the local site: with `MAIL_FROM` empty, as `config.sample.php` has
it, a mail (the password reset link) is written to the console of the dev server
instead, link and all.

Register on the local site, then make that account an admin ("Admins"); a database
loaded from live has live's admins already. Nothing runs the refresh worker here —
there is no cron — so shows are only as fresh as their last import until
`C:\tvtakip-dev\php\php.exe scripts\refresh_worker.php` is run by hand; it also
completes the imports that left their episode IMDB ids to it.

### Tests and static analysis

```
check.bat              (= C:\tvtakip-dev\php\php.exe scripts\check.php)
```

runs both, one after the other, and fails when either does. `deploy.bat` runs it
first and deploys nothing unless both pass. The tests need the local MySQL running
(`start-dev.bat` starts it).

- **Static analysis:** PHPStan (`phpstan.neon.dist`) reads all the PHP — pages,
  includes, scripts, migrations, tests — at level 6: unknown functions and
  constants, wrong argument types and counts, and every function's parameter and
  return types written down. Not asked for: what an array holds
  (`array<string, mixed>` and the like). The configuration's constants come from
  `config.sample.php`. Alone: `C:\tvtakip-dev\php\php.exe vendor\bin\phpstan analyse`.
- **Tests:** PHPUnit (`phpunit.xml.dist`), about 240 of them in a second or two.
  `tests\Unit\` checks the importer's functions that need neither a database nor
  a provider: finale flags, what TheTVDB may add, numbering and air-date
  comparisons, air times across time zones, Turkish names, trailers and images.
  `tests\Integration\` imports shows and movies into a database of their own,
  `tvtakip_test`, with `tests\Support\FakeProviders.php` answering for TMDB,
  TVmaze and TheTVDB: what each provider contributes, what a later import keeps
  or clears, pruning, provisional ids moving to their IMDB id, the episode-id
  lookups — and the "has it aired" checks, run by MySQL at a fixed moment, and the
  calendar. Alone: `C:\tvtakip-dev\php\php.exe vendor\bin\phpunit` (or with
  `--filter <name>`). The tests never use `config.php`, this machine's database or
  the network: `tests\bootstrap.php` sets their configuration, and every test run
  empties `tvtakip_test` and builds it from `migrations\`.

A change to how the importer combines providers deserves a test in
`ImportShowTest.php`: describe what each provider says with `FakeProviders`, import,
and compare what was stored. Pages and JavaScript have no tests: check those by
using the running site. `node --check public\assets\js\app.js` catches a syntax error
in the script.

The development tools are Composer packages, listed in `composer.json` and pinned
in `composer.lock`. `setup-dev.bat` installs them; to move to newer versions:
`C:\tvtakip-dev\php\php.exe C:\tvtakip-dev\composer.phar update`, then commit
`composer.lock`. Nothing of Composer's goes to live: `vendor\` is not in git, and the
site requires no package. (hostUNO does have Composer, 2.8.10 on 2026-11-20, an old
build of the host's own, if the site ever needs a package: the deploy would then run
`composer install --no-dev` on the server.)

## Admins

Who is an admin is stored with the account (`users.role`) and changed only from the
command line — there is no page for it, and nothing in `config.php`:

```
C:\tvtakip-dev\php\php.exe scripts\admin.php                   list the admins
C:\tvtakip-dev\php\php.exe scripts\admin.php add <email>       make that account an admin
C:\tvtakip-dev\php\php.exe scripts\admin.php remove <email>    back to a regular user
```

The account has to exist first (register on the site). A change applies at that
account's next request, without a new login. On the live site the same script runs
over SSH:

```
ssh hostuno "php domains/tvtakip.akifuygun.com/app/scripts/admin.php"
```

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
- **New tables end in `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci`.**
  The existing columns are `utf8mb4_general_ci`, and a foreign key needs the same
  collation on both sides. Without the clause a table gets the database's default,
  which is `utf8mb4_unicode_ci` on live (and locally, set up the same way).
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

The site is hosted at hostUNO, a shared host with SSH and cron (PHP 8.3 as PHP-FPM,
MySQL 8.0). The live site is a git checkout of `main` on the server, of which the
web server is given only `public/` (see "What the web server serves"). Commit, push,
then run

```
deploy.bat             (.\deploy in PowerShell; = C:\tvtakip-dev\php\php.exe scripts\deploy.php)
```

It reaches the server as `ssh hostuno` (a `Host` alias in `~/.ssh/config`) and stops
with `DEPLOY FAILED` and exit code 1 at the first thing that is wrong:

1. **Here** — the checkout must be on `main` with nothing uncommitted (an untracked
   file counts: it would be missing on live) and nothing unpushed, and the static
   analysis and the tests must pass on it (`check.bat`, "Tests and static
   analysis": the local MySQL has to be running).
2. **On the server** — the live checkout must have no changes of its own and be able
   to fast-forward to exactly this commit. Pending migrations run first, while the
   old code still serves, after a dump of the database to
   `~/dbdumps/tvtakip-<date>-before-<commit>.sql.gz`. Only then the code is switched;
   if a migration fails, the old code stays live.
3. **From here** — about thirty requests to the live site as a guest: the public
   pages answer 200 and carry the asset version of the commit's CSS/JS (live works
   it out from its own files, so that also says those are the commit's),
   `/health` reports the database answering, the
   login-gated ones turn a guest away, the assets and the service worker are
   served, a directory doesn't list its files (403), and nothing beside `public/`
   has a URL (`includes/`, `scripts/`, `migrations/`: 404; a `/.git` path is a 403
   from the host itself, which refuses that name wherever it is asked for). Every
   answer but a 403 must carry the response headers the commit's
   `public/.htaccess` sets (see "HTTPS and response headers").

`deploy.bat --check` runs only step 3, against whatever commit live is on. Deploying
again when live is current changes nothing and repeats the checks. Pages behind the
login are not requested — look at those yourself after a deploy that touches them.
Both end with a line comparing live's PHP and MySQL versions with this machine's.
It starts with `NOTE:` when they differ: then run `setup-dev.bat` ("Local
development").

### Rolling back

The deploy names the commit live ran before (`Deploying to live, which runs …`);
`git reflog` in the live checkout lists the earlier ones.

1. Put that commit back — it takes a second, and files git doesn't track there
   (`config.php`) are left alone:
   `ssh hostuno "git -C domains/tvtakip.akifuygun.com/app reset --hard <commit>"`
2. `deploy.bat --check` confirms the site is healthy on it.
3. `main` still holds the bad commit, and the next deploy would put it back:
   `git revert` it (or fix it), push, deploy.

Migrations are not undone by this. They don't need to be: a migration has to leave
older code working, so the commit before runs on the newer schema (`migrate.php`
then mentions a migration it has no file for — harmless). When the schema change
itself is the problem, undo it with a new migration, or restore the dump the deploy
took just before migrating (next section) — that loses everything written since.
The dump carries `schema_migrations`, so the next deploy applies the migration again.

Not this way to a commit from before `public/` existed (2026-11-19): the web root
would lose its pages and the whole site answer 404.

### What the web server serves

The host serves `~/domains/<domain>/public_html` for a domain. For this site that
is a link into the checkout, which sits beside it:

```
~/domains/tvtakip.akifuygun.com/
    app/               the git checkout — config.php, includes/, scripts/, .git, …
    app/public/        the pages, api/ and assets/
    public_html  ->  app/public
    sessions/          the site's private directory: session files (below), PHP's
                       error log ("Monitoring")
    logs/              the host's access log and the web server's error log
```

So a request can only ever reach a file of `public/`; that `config.php` or `.git`
stay private no longer depends on a deny rule being read. Three things on the
server belong to this arrangement:

- **`open_basedir`.** The host lets PHP open files only below the directories on a
  per-domain list, which names `public_html` — but through the link the files are
  elsewhere, and the includes are outside it anyway. So the checkout is on the list,
  next to the session directory (`devil www list -v` shows it; `devil` puts its own
  defaults — `public_html`, `/tmp`, … — in front of what it is given, and PHP picks
  a change up a minute or two later):
  `ssh hostuno "devil www options tvtakip.akifuygun.com php_openbasedir /usr/home/akifuygun/domains/tvtakip.akifuygun.com/app:/usr/home/akifuygun/domains/tvtakip.akifuygun.com/sessions"`
  The option replaces the whole list each time: leave a directory out and every
  page answers 500.
- **Files that exist only there**, which git ignores: `app/config.php` (mode 600)
  and Google's site-verification file `app/public/google….html`.
- **Cron** runs the refresh worker, the nightly backup check and the morning's
  error report by their paths in the checkout
  (`php …/app/scripts/refresh_worker.php`, `…/backup_check.php`,
  `…/error_report.php`; `crontab -l`).

On a new server: clone the repository to `app`, replace the `public_html` the host
created with the link (`ln -s app/public public_html`, in the domain's directory),
write `app/config.php`, set the option above, run `php app/scripts/migrate.php`, and
add the cron lines. When it replaces a server that is gone, the copy kept off it has
the rest ("Backups and restoring"): the database dump to load before `migrate.php`,
and in the newest archive `config.php`, the cron lines and the old host's settings.

### Session files

PHP keeps sessions as files named after the session id. Its default place for them
is the server's `/tmp`, which every account on a shared server can list — so live
keeps them in a directory of its own, outside the web root and closed to everyone
else (mode 700):

- `SESSION_DIR` in live's `config.php` names it:
  `/usr/home/akifuygun/domains/tvtakip.akifuygun.com/sessions`.
- PHP may only open what is on the domain's `open_basedir` list, so the directory
  is on it, together with the checkout — the command is under "What the web server
  serves" above.

When the directory can't be used, every page answers 500; the site never falls back
to `/tmp`. The reason is then not to be read anywhere — PHP's error log is a file in
this very directory ("Monitoring") — so look at the two things above. Deleting the
`sess_…` files logs nobody out who ticked "remember me". Locally `SESSION_DIR` stays
empty: the sessions are then in `C:\tvtakip-dev\tmp` (`dev\php.ini`), on a machine
with one user.

The same directory holds what else the site keeps for itself:

- `tvtrack_tvdb_…`, the login token TheTVDB issues for a month, shared by the web
  requests and the cron worker. Deleting it costs one new login at the next import.
- `php-error.log` (and `.1`, the one before) and `error-report.json`: PHP's error
  log and how far it has been mailed ("Monitoring").

### HTTPS and response headers

The site is HTTPS-only. The host's front-end server answers every plain-HTTP
request with a redirect (the domain's "Force SSL" option, `devil www list -v`), and
`public/.htaccess` has the same redirect for a server without that option. Whether a
request came over HTTPS is the server's own word (`HTTPS=on`) and nothing else: an
`X-Forwarded-Proto` header is not believed, because any client can send one. So
behind a proxy that ends TLS and hands the request on as plain HTTP, the server
must be set up to report HTTPS itself — otherwise the redirect loops and the
cookies lose their `Secure` flag.

`.htaccess` also sends these with every response (pages, API answers, assets):

| Header | Value | What it does |
| --- | --- | --- |
| `Strict-Transport-Security` | `max-age=31536000` | A browser that has visited once uses HTTPS only for the next year, and offers no way past a certificate error. |
| `X-Content-Type-Options` | `nosniff` | A response is only ever treated as the type it declares. |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Another site is told the address of this site, never the page. |
| `X-Frame-Options`, `Content-Security-Policy` | `DENY`, `frame-ancestors 'none'` | No page may show this site in a frame. |

`Strict-Transport-Security` deliberately covers `tvtakip.akifuygun.com` alone. It
has no `includeSubDomains` and no `preload`: preloading works only from
`akifuygun.com` itself and would force HTTPS on every present and future name under
it, for months after a change of mind. To take the rule back, send `max-age=0` for
a while — a browser drops it at its next visit.

The host is not a plain Apache — nginx answers the requests — and it honours
`.htaccess` only in part: a directive it doesn't act on is skipped without an error
(`Header always set` is one — hence plain `Header set`). Try a new directive on
live before relying on it; for the headers, the deploy's checks do that on every
run. It also lists a directory's files unless told otherwise, which is what
`Options -Indexes` in `public/.htaccess` is for.

### Mail

The site mails one thing to its users, the password reset link. PHP's `mail()`
hands it to the server's mail system (Exim), which signs it with the domain's DKIM
key and sends it from an address the SPF record of `akifuygun.com` names.
`MAIL_FROM` in live's `config.php` is the sender, `noreply@akifuygun.com`. Replies
and bounces go to that address, so they land in the `me@akifuygun.com` mailbox
(catch-all); its forwarding rule passes the replies on to Gmail and keeps the
bounces in the hostUNO inbox.

The domain also publishes a DMARC record (`_dmarc.akifuygun.com`, `v=DMARC1; p=none`,
since 2026-11-20): receivers see a policy, none is enforced, and no reports are
asked for. Arriving is still another matter: Gmail filed the first backup alerts
from this server as spam, though the first reset mail reached the inbox. When
someone says the mail never came, have them search their spam folder first. A mail the server could not hand over is a line in PHP's error log, so it
shows up in the next morning's error report ("Monitoring").

## Backups and restoring

The live database is dumped whole, on the server, into `~/dbdumps`:

- every night at 04:15, by cron: `tvtakip-YYYYMMDD.sql.gz`;
- by a deploy, just before it applies a migration:
  `tvtakip-<date>-before-<commit>.sql.gz`.

The nightly job deletes every `tvtakip-*.sql.gz` there that is older than 14 days.

In the commands below `<database>` is `DB_NAME` in live's `config.php`. They are
written for cmd and PowerShell and work in both as typed.

### The copy off the server

Those dumps are on the same server as the database, in the same account: lose that
and both are gone. So a scheduled task on the development machine copies them off
the server once a day:

```
C:\tvtakip-dev\php\php.exe scripts\backup_pull.php
```

It can be run by hand at any time, and fills `Backups\hostuno` in the user's profile
(for another folder, name it as the argument):

- `dbdumps\` — every dump the server has, each compared with the server's SHA-256.
  Kept for 90 days.
- `files\hostuno-YYYYMMDD.tar.gz` — one a day, kept as long: what exists on the
  server alone. In it, `tvtakip/` holds the files git ignores in the live checkout
  (`config.php`, Google's verification file), `cv/` the akifuygun.com site, `mail/`
  the mailbox's forwarding rule, `crontab.txt` the cron jobs and `host.txt` the
  host's settings as its `devil` tool lists them: the sites with their options, the
  certificates, the databases, the DNS records, the mailboxes. `tar -xzf <file>`
  unpacks one.
- `pull.log` — a line per run, times in UTC.

The code is on GitHub; with it, a dump and one of those archives, the site can be
set up again on another server ("What the web server serves" has the steps).

That folder is one disk in one machine. A folder that a cloud drive synchronises,
given to the task as its argument, would be a third place — the dumps hold the
accounts' password hashes, and `config.php` the API keys and the database password.

The task is set up once per machine, in PowerShell (`ssh hostuno` has to work there
without asking for anything):

```powershell
Register-ScheduledTask -TaskName 'TVTrack backup pull' `
  -Action (New-ScheduledTaskAction -Execute 'conhost.exe' -Argument '--headless C:\tvtakip-dev\php\php.exe C:\Users\Akif\Desktop\tvtakip\scripts\backup_pull.php') `
  -Trigger (New-ScheduledTaskTrigger -Daily -At 10:00) `
  -Settings (New-ScheduledTaskSettingsSet -StartWhenAvailable -RunOnlyIfNetworkAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 30))
```

It runs at 10:00, or as soon after that as the machine is switched on and online.
`conhost --headless` keeps a console window from opening; because of it Task
Scheduler shows the result 0 whatever happened — how a run went is in `pull.log`.

### When a backup is missing

Each side watches the other:

- **The pull** shows a message box on the development machine, and writes `PROBLEM`
  to `pull.log`, when it could not copy everything, and when the server's newest
  nightly dump is older than a day or not a whole dump.
- **The server** runs `scripts/backup_check.php` by cron at 04:45, half an hour
  after the dump, and mails `me@akifuygun.com`: every night the nightly dump is
  missing or not whole, and when the last complete pull is four days back — that
  night, then once a week, since the machine may simply be switched off. A pull that
  copied everything leaves `~/dbdumps/off-site.txt` on the server, and that file's
  age is what the check goes by. Its lines are in `~/logs/tvtakip-backup.log`.

That mail is forwarded to Gmail like everything sent to the address, and Gmail put
the first ones into Spam (2026-11-20) — although the server signs them (DKIM) and
sends them from an address the SPF record names. So the Gmail account needs a
filter, or an alert goes unseen: From `me@akifuygun.com`, "Never send it to Spam".
No subject in it: the error reports ("Monitoring") come from the same address
under another one.

The check by hand prints the state as it is now and mails nobody:

```
ssh hostuno "php domains/tvtakip.akifuygun.com/app/scripts/backup_check.php"
```

When there is no nightly dump, `ssh hostuno "crontab -l"` has to list the
`mysqldump` line, and "A dump right now" below shows the error it runs into. When a
pull fails, run it by hand and read what it says.

### A dump right now

Before changing data on live by hand, and before a restore:

```
ssh hostuno "mysqldump --single-transaction --no-tablespaces <database> | gzip > dbdumps/tvtakip-YYYYMMDD-manual.sql.gz"
```

### Restoring live

A dump holds every table, and loading it drops and recreates each one: the whole
database goes back to the moment of the dump, and everything written since is lost.

1. Choose the dump and check that it is complete — its last line must read
   `-- Dump completed on …`:
   ```
   ssh hostuno "ls -lh dbdumps"
   ssh hostuno "gunzip -c dbdumps/tvtakip-YYYYMMDD.sql.gz | tail -1"
   ```
   One the server no longer has goes back up from the copy off the server first:
   `scp <folder>\dbdumps\tvtakip-YYYYMMDD.sql.gz hostuno:dbdumps/`
2. Dump the database as it is now (above), so that the restore itself can be undone.
3. Load the dump:
   ```
   ssh hostuno "lockf -k -t 600 domains/tvtakip.akifuygun.com/app/scripts/refresh_worker.lock sh -c 'gunzip -c dbdumps/tvtakip-YYYYMMDD.sql.gz | mysql <database>'"
   ```
   `lockf` holds the refresh worker's lock while the dump loads, so the worker
   doesn't import into half-restored tables: it waits up to ten minutes for a run
   in progress to end (`already locked` means it gave up — nothing was changed, run
   it again), and the runs cron starts meanwhile exit at once. The site itself
   stays up; a page that reads a table being reloaded answers an error.
4. A dump from before a migration also brings back the older schema. Apply what the
   live code needs, then check the site:
   ```
   ssh hostuno "php domains/tvtakip.akifuygun.com/app/scripts/migrate.php"
   deploy.bat --check
   ```
   A dump from before 2026-11-19, when the admin role moved into the database, comes
   back without an admin: `admin.php` lists them and `admin.php add <email>` makes
   one ("Admins").

When only some rows are lost, don't put live back for them: load the dump into the
scratch database on the server (next section) and copy those rows over — the live
database's user reads both, so one statement can select from `m3558_drill.<table>`
into the live table.

### The restore drill

A backup is worth what a restore from it gives. The server has a second, empty
database to try that in, `m3558_drill`, open to the same MySQL user as the live one:

```
ssh hostuno "gunzip -c dbdumps/tvtakip-YYYYMMDD.sql.gz | mysql m3558_drill"
ssh hostuno "mysql m3558_drill -e 'SELECT COUNT(*) FROM shows; SELECT COUNT(*) FROM watched_episodes; SELECT version FROM schema_migrations'"
```

Empty it afterwards — it is a copy of every account's data:

```
ssh hostuno "mysqldump --no-data --no-tablespaces m3558_drill | grep ^DROP | mysql --init-command='SET FOREIGN_KEY_CHECKS=0' m3558_drill"
```

Drilled on 2026-11-19 with that night's dump (7 MB; 36 MB of SQL, 1,160 shows,
269,563 episodes): it loaded in 9 seconds, and a dump taken of `m3558_drill` right
after was the nightly one line for line — but for the database's name, the time, and
a `CHARACTER SET utf8mb4` that MySQL 8 spells out on the columns of a reloaded
table. The three migrations made since that dump then applied in 0.1 seconds, and
the copy had no admin until `admin.php add` (step 4 above, both).

### Live's data in the local database

```
C:\tvtakip-dev\php\php.exe scripts\load_live.php [--yes] [<dump>]
```

This replaces the local database with live's newest dump, or with the one named,
and then applies this checkout's pending migrations ("Local development"). Accounts
come along, so log in with the live password afterwards. Don't pipe a dump through
PowerShell 5.1 into `mysql.exe` instead: its pipe turns every non-ASCII character
into `?` on the way from one program to the next. The script passes the bytes on
unchanged.

## Monitoring

Three things tell when the live site is in trouble, without anyone looking at it:
a health check for an uptime monitor to ask, the monitor itself, and a daily mail
of what is new in the error logs.

### The health check

`https://tvtakip.akifuygun.com/health` answers `200` with `{"status":"ok"}` when
PHP runs and the database answers, and `503` with `{"status":"error"}` when the
database doesn't. It starts no session, asks no provider and is never cached, and
it gives the database five seconds to accept the connection — so the answer is
quick either way. Why it failed goes to PHP's error log (below), not into the
answer. The deploy's checks ask it too.

### The uptime monitor

A service outside the server asks two addresses every few minutes and mails when
one of them stops answering `200`, and again when it is back:

| Address | What an alert for it says |
| --- | --- |
| `https://tvtakip.akifuygun.com/health` | PHP or the database is down — or, when the other one alerts too, the server, its network, the DNS or the certificate. |
| `https://tvtakip.akifuygun.com/` | A visitor doesn't get the home page. With `/health` still fine, it is the code: look at the error log. |

That service is [UptimeRobot](https://uptimerobot.com/), on its free plan (a `HEAD`
request to each address every 5 minutes), in an account of the site owner's since
2026-11-20. Any uptime service would do; setting it up again takes two steps:

1. Sign up with the Gmail address — not `me@akifuygun.com`: that mailbox, and the
   DNS it is found by, are at the same host as the site, so an alert about the host
   might not arrive while it is down.
2. Add two monitors of the type "HTTP(s)", one per address above, each alerting that
   e-mail contact. Nothing else needs setting: no answer and an answer with an
   error status both count as down, and `/health` reports trouble as `503`.

The monitor's requests are in the host's access log
(`~/domains/tvtakip.akifuygun.com/logs/access/<date>.log`) under its own user agent.

### The error logs

- **PHP's**: `php-error.log` in the site's private directory (`SESSION_DIR`; on live
  `~/domains/tvtakip.akifuygun.com/sessions/php-error.log`). The pages, the API and
  the cron scripts all write to it: PHP's warnings and notices, and the lines the
  code writes itself, marked `[tvtrack]` — an exception nothing caught (the visitor
  got a plain "Something went wrong" page with status 500), an import the refresh
  worker could not make (`refresh worker: FAIL …`), a `/health` that found the
  database down. Each line starts with its time and the time zone that is in: the
  visitor's for a page, the server's for the rest.
  The site keeps this file itself (`includes/errors.php`) because what PHP logs by
  the host's defaults arrives in no file the account can read — until 2026-11-20
  these lines went that way and were lost. Without `SESSION_DIR`, on a development
  machine, they go to the console of the dev server or of the script instead.
- **The web server's**: `~/domains/tvtakip.akifuygun.com/logs/error.log`, the host's
  own — requests it refused or could not hand to PHP.

Every morning at 06:00 cron runs `scripts/error_report.php me@akifuygun.com` on the
server. It mails what the two logs gained since the last report, and nothing when
they gained nothing; its one line per run is in `~/logs/tvtakip-errors.log`. In the
mail, lines that say the same thing are one entry with a count and the first and
last time, and at most 100 different entries per log are listed. Left out are the
web server's lines that report the site working as meant (`EXPECTED_LINES` in the
script: a directory's file list being refused, which every deploy check asks for
twice). What has been reported is remembered in `error-report.json` beside PHP's
log; a report that could not be mailed is repeated the next morning with what came
since. PHP's log is moved to `php-error.log.1` once it is past 1 MB.

The mail comes from `me@akifuygun.com` like the backup alerts, so the Gmail filter
described under "When a backup is missing" has to cover it.

By hand, the script prints what is new since the last mail and marks nothing as
reported; the log itself is a text file:

```
ssh hostuno "php domains/tvtakip.akifuygun.com/app/scripts/error_report.php"
ssh hostuno "tail -n 50 domains/tvtakip.akifuygun.com/sessions/php-error.log"
```

What none of this catches: an error PHP raises before `includes/errors.php` has
run — a syntax error in a page's own file or in `includes/auth.php` — is shown to
the visitor by the host's defaults instead of being logged. The deploy's checks
find that kind at once, since every page then fails.

## Data sources

Show and movie data from [TMDB](https://www.themoviedb.org/),
[TVmaze](https://www.tvmaze.com/) and [TheTVDB](https://thetvdb.com) (metadata
provided by TheTVDB; please consider adding missing information or subscribing).
This product uses TMDB and the TMDB APIs but is not endorsed, certified, or
otherwise approved by TMDB. All three providers require attribution — the site
footer carries a "Data: TMDB · TVmaze · TheTVDB" line with links; keep it.
