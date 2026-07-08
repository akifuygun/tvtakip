-- tvtakip database schema
-- Import via phpMyAdmin (InfinityFree) or: mysql -u root tvtakip < schema.sql

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shows a user follows. TVmaze remains the source of truth for show data;
-- name/image/status are cached here so the dashboard renders without API calls.
CREATE TABLE IF NOT EXISTS user_shows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tvmaze_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    image_url VARCHAR(500) DEFAULT NULL,
    status VARCHAR(50) DEFAULT NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_show (user_id, tvmaze_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watched_episodes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tvmaze_show_id INT UNSIGNED NOT NULL,
    tvmaze_episode_id INT UNSIGNED NOT NULL,
    season SMALLINT UNSIGNED NOT NULL,
    episode SMALLINT UNSIGNED NOT NULL,
    watched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_episode (user_id, tvmaze_episode_id),
    KEY idx_user_show (user_id, tvmaze_show_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
