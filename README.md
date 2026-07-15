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
  results by IMDB id, so shows missing from either provider still appear.
- **Episode data** comes from TMDB, including each episode's IMDB id via its
  `external_ids` endpoint, with TVmaze as fallback for shows TMDB doesn't know
  (their episode IMDB ids stay empty and are backfilled on refresh once TMDB has them).
  Imports run when a show is tracked or first opened and land in the `shows` /
  `episodes` cache tables — everyone after reads our DB. Episodes are identified by
  show IMDB id + season + number; episode IMDB ids are deliberately not unique
  (IMDB lists two-part episodes under one id).
- **Rules:** unaired episodes can't be marked watched; untracking keeps watched
  history; show statuses are normalized to running/upcoming/ended/canceled.
- **Movies:** a single per-user list with a watched flag (a movie is one watchable
  unit — no per-episode state). TMDB-only import; search + add live on the
  My Movies page; marking watched auto-adds to the list and is gated on the
  release date. Public movie pages at `/movie/ttNNN`.
- **Accounts:** email login, display name shown in the header, self-service
  change-password. 30-day sessions plus a 60-day "remember me" token so the free
  host's short session GC doesn't force frequent logins.
- **Admin** (emails listed in `ADMIN_EMAILS`): can set/remove a show's poster
  (shared cache) — the "no image" placeholder is admin-only. Everyone else is
  refused server-side.
- **i18n:** all strings live in one dictionary (`includes/i18n.php`), also handed
  to the frontend as `window.I18N`; language kept in a `lang` cookie.

## Project structure

```
index.php          Calendar — next unwatched aired episode of each tracked show
                   (guests get the public landing page)
myshows.php        Tracked shows grouped by status (Running / Upcoming / Ended)
mymovies.php       Movie list: on-page search to add, To watch / Watched groups
search.php         Search both providers and track shows
series.php         Unified show page (/series/ttNNN): public SEO for guests,
                   interactive app when logged in (show.php 301-redirects here)
movie.php          Public movie page (/movie/ttNNN) + add/watched buttons
browse.php         Public catalog with network/genre/status filters
upcoming.php       Upcoming episodes (public catalog; tracked-only when logged in)
change-password.php  Self-service password change
login.php / register.php / logout.php   (email login, display name in header)
api/search.php     GET merged TVmaze+TMDB show search (server-side)
api/track.php      POST: track/untrack by IMDB id (imports server-side if uncached)
api/episodes.php   GET cached show+episodes+watched; POST imports/refreshes server-side
api/watch.php      POST toggle episode watched; bulk (un)watch per show or season
api/movies.php     GET movie search; POST add/remove/watch (single endpoint)
api/tick.php       Cron-free freshness: refreshes the most-stale running show
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
config.sample.php  Copy to config.php: DB credentials, TMDB_API_KEY, ADMIN_EMAILS
```

## Local development

Requires XAMPP (installed at `C:\xampp`, PHP 8.2 + MariaDB). One-time setup:

1. Create the database and import the schema:
   `C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE tvtakip CHARACTER SET utf8mb4"` then
   `C:\xampp\mysql\bin\mysql.exe -u root tvtakip < schema.sql`
2. `copy config.sample.php config.php` (defaults already match XAMPP: root, no password),
   set `TMDB_API_KEY` (free key from https://www.themoviedb.org/settings/api), and add
   your email to `ADMIN_EMAILS` if you want admin controls.

Then run `start-dev.bat` — it starts MySQL if needed and serves the site at
http://localhost:8000.
