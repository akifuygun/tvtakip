-- tvtakip database schema (v2 — IMDB-keyed)
-- Import via phpMyAdmin (InfinityFree) or: mysql -u root tvtakip < schema.sql
--
-- IMDB ids are the canonical identity for shows. Episodes are identified by
-- (show_imdb_id, season, number) because a few episodes (specials, unaired)
-- have no IMDB id yet; the episode's imdb_id is stored whenever IMDB has one.
-- TVmaze (search) and TMDB (episode data) are fetch layers only — none of
-- their ids are stored.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,      -- login identifier
    display_name VARCHAR(100) NOT NULL,      -- first and/or last name, shown in the header
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shared cache of show metadata, populated on first track / first visit.
CREATE TABLE IF NOT EXISTS shows (
    imdb_id VARCHAR(12) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    image_url VARCHAR(500) DEFAULT NULL,
    status VARCHAR(50) DEFAULT NULL,  -- canonical: running|ended|canceled|upcoming|unknown (normalize_show_status)
    overview TEXT DEFAULT NULL,
    premiered DATE DEFAULT NULL,
    synced_at TIMESTAMP NULL DEFAULT NULL,  -- set when a full episode import completed
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shared cache of episodes per show.
CREATE TABLE IF NOT EXISTS episodes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    show_imdb_id VARCHAR(12) NOT NULL,
    imdb_id VARCHAR(12) DEFAULT NULL,
    season SMALLINT UNSIGNED NOT NULL,
    number SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    airdate DATE DEFAULT NULL,
    airstamp DATETIME DEFAULT NULL,  -- exact air time in UTC (TVmaze), when known
    -- (show, season, number) is the ONLY identity. imdb_id is deliberately
    -- NOT unique: IMDB lists two-part episodes under one id, and a second
    -- unique key would make upserts ambiguous (corrupting the wrong row).
    UNIQUE KEY uq_show_episode (show_imdb_id, season, number),
    KEY idx_show_airdate (show_imdb_id, airdate),
    KEY idx_airdate (airdate),  -- public /upcoming page filters by airdate alone
    FOREIGN KEY (show_imdb_id) REFERENCES shows(imdb_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_shows (
    user_id INT UNSIGNED NOT NULL,
    show_imdb_id VARCHAR(12) NOT NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, show_imdb_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (show_imdb_id) REFERENCES shows(imdb_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Persistent "remember me" login tokens (selector/validator pattern).
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector CHAR(32) NOT NULL,
    validator_hash CHAR(64) NOT NULL,   -- sha256 of the validator, never the raw value
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_selector (selector),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watched_episodes (
    user_id INT UNSIGNED NOT NULL,
    episode_id INT UNSIGNED NOT NULL,
    watched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, episode_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
