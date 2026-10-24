-- Sign-in attempts, used to throttle password guessing.
-- The identity is stored as a SHA-256 hash, so typed-in (possibly mistyped
-- or sensitive) identifiers are not kept in clear text.

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY login_attempts_identifier_index (identifier_hash, attempted_at),
    KEY login_attempts_ip_index (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
