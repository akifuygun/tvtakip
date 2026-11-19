-- The validator a remember-me token had before its last rotation (its sha256),
-- and when that rotation was: for a moment after it the old one still logs in
-- (REMEMBER_GRACE, includes/auth.php), so requests sent together with the same
-- cookie don't lock each other out.
ALTER TABLE remember_tokens
    ADD COLUMN prev_validator_hash CHAR(64) NULL AFTER validator_hash,
    ADD COLUMN rotated_at DATETIME NULL AFTER prev_validator_hash;
