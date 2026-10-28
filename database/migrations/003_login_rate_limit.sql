CREATE TABLE IF NOT EXISTS auth_login_attempts (
    ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_auth_login_attempts_updated (updated_at)
) ENGINE=InnoDB;
