# tvtakip

A web app to track TV series: search shows, follow them, and mark episodes as watched.

- **Live:** https://tvtakip.akifuygun.com
- **Stack:** Plain PHP 8 + MySQL (chosen for InfinityFree hosting), vanilla JS/CSS
- **Canonical ids:** IMDB ids for both shows and episodes — no third-party ids in the DB

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

## Project structure

```
index.php          Calendar — next unwatched aired episode of each tracked show
myshows.php        Tracked shows grouped by status (Running / Upcoming / Ended)
search.php         Search both providers and track shows
show.php           Show detail + per-season episode toggles
login.php / register.php / logout.php   (email login, display name in header)
api/search.php     GET merged TVmaze+TMDB search (server-side)
api/track.php      POST: track/untrack by IMDB id (imports server-side if uncached)
api/episodes.php   GET cached show+episodes+watched; POST imports/refreshes server-side
api/watch.php      POST toggle episode watched; bulk (un)watch per show or season
includes/          db.php, auth.php, http.php, importer.php, header.php, footer.php
assets/            css/style.css, js/app.js
scripts/           CLI utilities: import_watchlist.php (wipe + Trakt JSON import),
                   backfill_images.php
schema.sql         MySQL schema
config.sample.php  Copy to config.php and fill in DB credentials + TMDB key
```

## Local development

Requires XAMPP (installed at `C:\xampp`, PHP 8.2 + MariaDB). One-time setup:

1. Create the database and import the schema:
   `C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE tvtakip CHARACTER SET utf8mb4"` then
   `C:\xampp\mysql\bin\mysql.exe -u root tvtakip < schema.sql`
2. `copy config.sample.php config.php` (defaults already match XAMPP: root, no password)
   and set `TMDB_API_KEY` (free key from https://www.themoviedb.org/settings/api).

Then run `start-dev.bat` — it starts MySQL if needed and serves the site at
http://localhost:8000.

## Deploying to InfinityFree

1. In the InfinityFree control panel, create a **MySQL database**. Note the host
   (`sqlXXX.infinityfree.com`), database name (`epiz_XXXX_tvtakip`), username and password.
2. Open **phpMyAdmin** from the control panel and import `schema.sql` into that database.
3. Create `config.php` (copy of `config.sample.php`) with those credentials.
   **Never commit `config.php`** — it is gitignored.
4. Upload everything in this repo (including `config.php`, excluding `.git`) into `htdocs/`
   using the online file manager or FTP (FileZilla; credentials in the control panel).
5. Point the subdomain `tvtakip.akifuygun.com` at the account (Subdomains / CNAME per
   InfinityFree docs) and enable free SSL in the control panel.
