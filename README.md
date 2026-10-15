# TVTrack

A web app to track TV series and movies: search, follow shows, mark episodes as
watched, and keep a movie watchlist.
(English name TVTrack, Turkish name TVTakip; the `tvtakip` name persists in the domain, repo, and DB.)

- **Stack:** Plain PHP 8 + MySQL, vanilla JS/CSS
- **Canonical ids:** IMDB ids for shows, episodes, and movies — no third-party ids in the DB
- **Installable PWA**, mobile-friendly (hamburger nav), and **bilingual** — English
  (TVTrack) / Turkish (TVTakip), switchable via footer flags.

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
- **Freshness is cron-free** (the free host has no cron): every calendar visit
  refreshes the viewer's stale tracked shows (airing ones first) and re-renders in
  place, and a per-session background ping refreshes stale shows site-wide.
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
    those are added (future only) until TMDB lists them.
  - *Premiere / finale badges* on the calendar and Upcoming: finales from TMDB's
    episode type, with TheTVDB marking which one is the series finale.
  - *Turkish descriptions* for Turkish pages: TMDB translations, TheTVDB as fallback.
  - *Poster slider:* clicking a poster opens the poster plus up to 9 large backdrops
    (TMDB, text-free first; TheTVDB backgrounds top up shows with few), loaded one
    slide ahead as you browse.
- **Rules:** unaired episodes can't be marked watched; untracking keeps watched
  history; show statuses are normalized to running/upcoming/ended/canceled.
- **Movies:** a single per-user list with a watched flag (a movie is one watchable
  unit — no per-episode state). TMDB-only import; search + add live on the
  My Movies page; marking watched auto-adds to the list and is gated on the
  release date. Public movie pages at `/movie/ttNNN` and a public catalog at
  `/movies`.
- **Accounts:** email login, display name shown in the header, self-service
  change-password. 30-day sessions plus a 60-day "remember me" token so the free
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
api/tick.php       Cron-free freshness: refreshes stale shows (own tracked first)
api/image.php      POST set/remove a show poster (admin only)
includes/          db.php, auth.php, errors.php, i18n.php, http.php, importer.php,
                   network_logos.php, header.php, footer.php
assets/            css/style.css, js/app.js, icons/ (PWA + logo)
manifest.php, sw.js   Localized PWA manifest + service worker (static-asset cache)
sitemap.php        Dynamic sitemap (shows + movies, en + tr)
scripts/           CLI utilities: import_popular.php, import_watchlist.php,
                   backfill_images.php, backfill_airstamps.php, make_sync.php
schema.sql         MySQL schema (users, shows, episodes, movies, user_shows,
                   watched_episodes, user_movies, remember_tokens)
config.sample.php  Copy to config.php: DB credentials, TMDB_API_KEY, TVDB_API_KEY
                   (optional), ADMIN_EMAILS
```

## Local development

Requires XAMPP (installed at `C:\xampp`, PHP 8.2 + MariaDB). One-time setup:

1. Create the database and import the schema:
   `C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE tvtakip CHARACTER SET utf8mb4"` then
   `C:\xampp\mysql\bin\mysql.exe -u root tvtakip < schema.sql`
2. `copy config.sample.php config.php` (defaults already match XAMPP: root, no password),
   set `TMDB_API_KEY` (free key from https://www.themoviedb.org/settings/api), and add
   your email to `ADMIN_EMAILS` if you want admin controls. Optionally set
   `TVDB_API_KEY` (https://thetvdb.com/dashboard/account/apikey — free under $50k/yr
   revenue, attribution required and shown in the footer) for streaming air times,
   early announced episodes, series-finale badges and extra Turkish descriptions;
   without it the site works on TMDB + TVmaze alone.

Then run `start-dev.bat` — it starts MySQL if needed and serves the site at
http://localhost:8000.

## Data sources

Show and movie data from [TMDB](https://www.themoviedb.org/),
[TVmaze](https://www.tvmaze.com/) and [TheTVDB](https://thetvdb.com) (metadata
provided by TheTVDB; please consider adding missing information or subscribing).
This product uses TMDB and the TMDB APIs but is not endorsed, certified, or
otherwise approved by TMDB. All three providers require attribution — the site
footer carries it (TMDB logo + notice, TVmaze and TheTVDB links); keep it.
