-- What a user may do: 'user', or 'admin' (the shared-cache controls — posters,
-- a show's primary network — and the Watch links). Only scripts/admin.php
-- changes it.
ALTER TABLE users
    ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER session_version;
