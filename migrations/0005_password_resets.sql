-- "Forgot password" (includes/password_reset.php): one row per request, kept
-- for a day. The rows of the last hour are its rate limits — per client
-- address, and per account. A row with a user_id mailed that account a link;
-- its token_hash (sha256 of the token in the link) is cleared once the link is
-- used, or the password is changed another way, and is good for
-- RESET_LIFETIME minutes until then. user_id is NULL when nothing was mailed:
-- no account has the address, or it was sent enough links that hour.
CREATE TABLE password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    ip VARCHAR(45) NOT NULL,     -- the client's address; an IPv6 one as its /64 network
    token_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_ip_created (ip, created_at),
    KEY idx_user_created (user_id, created_at),
    KEY idx_created (created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
