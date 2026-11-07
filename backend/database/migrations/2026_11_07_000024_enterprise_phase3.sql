-- Phase 3: authenticator app, personal API tokens, retention archives,
-- privacy records, and a session generation counter.
-- Additive only. No existing row is deleted.

ALTER TABLE users
    ADD COLUMN auth_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE login_attempts
    ADD COLUMN archived_at DATETIME NULL AFTER attempted_at,
    ADD KEY login_attempts_archived_attempted_index (archived_at, attempted_at);

ALTER TABLE import_audit_logs
    ADD COLUMN archived_at DATETIME NULL AFTER created_at,
    ADD KEY import_audit_logs_archived_created_index (archived_at, created_at);

CREATE TABLE user_totp (
    user_id BIGINT UNSIGNED NOT NULL,
    secret VARCHAR(64) NOT NULL,
    enabled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT user_totp_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY user_recovery_codes_user_hash_unique (user_id, code_hash),
    KEY user_recovery_codes_user_index (user_id, used_at),
    CONSTRAINT user_recovery_codes_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY api_tokens_hash_unique (token_hash),
    KEY api_tokens_user_index (user_id, revoked_at, expires_at),
    CONSTRAINT api_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
    id VARCHAR(128) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY user_sessions_user_index (user_id),
    CONSTRAINT user_sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE privacy_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_type VARCHAR(40) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(40) NOT NULL,
    granted TINYINT(1) NOT NULL,
    note VARCHAR(255) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY privacy_consents_subject_index (subject_type, subject_id, recorded_at),
    KEY privacy_consents_archived_index (archived_at, recorded_at),
    CONSTRAINT privacy_consents_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE privacy_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_type VARCHAR(40) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('export', 'correction', 'anonymize') NOT NULL,
    status ENUM('open', 'in_progress', 'completed', 'rejected') NOT NULL DEFAULT 'open',
    details TEXT NULL,
    resolution TEXT NULL,
    requested_by BIGINT UNSIGNED NULL,
    resolved_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY privacy_requests_subject_index (subject_type, subject_id, requested_at),
    KEY privacy_requests_status_index (status, requested_at),
    KEY privacy_requests_archived_index (archived_at, requested_at),
    CONSTRAINT privacy_requests_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT privacy_requests_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, group_name, label, updated_by) VALUES
    ('security.totp_required_super_admin', '0', 'security', 'Require an authenticator app for Super Admin', NULL),
    ('security.totp_required_admin', '0', 'security', 'Require an authenticator app for Admin', NULL),
    ('security.totp_required_sales_manager', '0', 'security', 'Require an authenticator app for Sales Manager', NULL),
    ('security.totp_required_sales_user', '0', 'security', 'Require an authenticator app for Sales User', NULL),
    ('retention.import_audit_days', '365', 'retention', 'Archive import audit logs older than (days)', NULL),
    ('retention.login_attempt_days', '365', 'retention', 'Archive sign-in attempts older than (days)', NULL),
    ('retention.privacy_record_days', '365', 'retention', 'Archive privacy records older than (days)', NULL);
