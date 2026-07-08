# tvtakip

A web app to track TV series: search shows, follow them, and mark episodes as watched.

- **Live:** https://tvtakip.akifuygun.com
- **Stack:** Plain PHP 8 + MySQL (chosen for InfinityFree hosting), vanilla JS/CSS
- **Series data:** [TVmaze API](https://www.tvmaze.com/api) — free, no API key, called from the browser

## How it works

- Show search and episode data come straight from TVmaze in the browser (`assets/js/app.js`).
- Our backend only stores what's ours: user accounts, which shows a user tracks, and which episodes they've watched (`api/*.php`).

## Project structure

```
index.php          Dashboard — your tracked shows (or welcome page)
search.php         Search TVmaze and track shows
show.php           Show detail + episode checklist
login.php / register.php / logout.php
api/track.php      POST: track/untrack a show
api/watch.php      GET watched episodes, POST toggle watched
includes/          db.php, auth.php, header.php, footer.php
assets/            css/style.css, js/app.js
schema.sql         MySQL schema
config.sample.php  Copy to config.php and fill in DB credentials
```

## Local development

Requires XAMPP (installed at `C:\xampp`, PHP 8.2 + MariaDB). One-time setup:

1. Create the database and import the schema:
   `C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE tvtakip CHARACTER SET utf8mb4"` then
   `C:\xampp\mysql\bin\mysql.exe -u root tvtakip < schema.sql`
2. `copy config.sample.php config.php` (defaults already match XAMPP: root, no password).

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
