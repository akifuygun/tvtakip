-- Values the site sets at run time and keeps across deploys (settings() and
-- set_setting() in includes/db.php), as opposed to config.php's, which only
-- change by hand. A row per value, under a dotted name: "watch_base.<site>" is
-- the origin an admin gave a Watch site (the footer ❤ → api/watch_base.php).
CREATE TABLE settings (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
