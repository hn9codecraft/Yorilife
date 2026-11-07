-- Phase 3: authenticator 2FA, device API tokens, retention archive columns,
-- and DPDP consent / request records. Additive only. No row is deleted.

ALTER TABLE users
    ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN totp_secret VARCHAR(64) NULL AFTER session_epoch,
    ADD COLUMN totp_pending_secret VARCHAR(64) NULL AFTER totp_secret,
    ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_pending_secret,
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_enabled_at,
    ADD COLUMN anonymised_at DATETIME NULL AFTER totp_last_step;

ALTER TABLE employees
    ADD COLUMN anonymised_at DATETIME NULL AFTER deleted_at;

ALTER TABLE login_attempts
    ADD COLUMN cleared_at DATETIME NULL AFTER attempted_at,
    ADD COLUMN archived_at DATETIME NULL AFTER cleared_at,
    ADD KEY login_attempts_archived_index (archived_at, attempted_at);

ALTER TABLE import_audit_logs
    ADD COLUMN archived_at DATETIME NULL AFTER created_at,
    ADD KEY import_audit_logs_archived_index (archived_at, created_at, id);

CREATE TABLE user_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY user_recovery_codes_user_index (user_id, used_at),
    CONSTRAINT user_recovery_codes_user_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose ENUM('totp', 'enroll') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY auth_challenges_token_hash_unique (token_hash),
    KEY auth_challenges_user_index (user_id, expires_at),
    CONSTRAINT auth_challenges_user_foreign FOREIGN KEY (user_id) REFERENCES users (id)
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
    created_ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY api_tokens_token_hash_unique (token_hash),
    KEY api_tokens_user_index (user_id, revoked_at, expires_at),
    CONSTRAINT api_tokens_user_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE privacy_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_type ENUM('user', 'employee') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(80) NOT NULL,
    status ENUM('granted', 'withdrawn') NOT NULL,
    notice_version VARCHAR(40) NULL,
    channel VARCHAR(40) NOT NULL,
    notes VARCHAR(500) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL,
    withdrawn_at DATETIME NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY privacy_consents_subject_index (subject_type, subject_id, recorded_at),
    KEY privacy_consents_archived_index (archived_at, recorded_at),
    CONSTRAINT privacy_consents_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE privacy_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('export', 'correction', 'anonymize') NOT NULL,
    subject_type ENUM('user', 'employee') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open', 'fulfilled', 'rejected') NOT NULL DEFAULT 'open',
    detail TEXT NULL,
    outcome VARCHAR(500) NULL,
    requested_by BIGINT UNSIGNED NULL,
    handled_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL,
    fulfilled_at DATETIME NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY privacy_requests_subject_index (subject_type, subject_id, requested_at),
    KEY privacy_requests_status_index (status, requested_at),
    KEY privacy_requests_archived_index (archived_at, requested_at),
    CONSTRAINT privacy_requests_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT privacy_requests_handled_by_foreign FOREIGN KEY (handled_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, group_name, label, updated_by) VALUES
    ('security.totp_required_super_admin', '0', 'security', 'Require authenticator for Super Admin', NULL),
    ('security.totp_required_admin', '0', 'security', 'Require authenticator for Admin', NULL),
    ('security.totp_required_sales_manager', '0', 'security', 'Require authenticator for Sales Manager', NULL),
    ('security.totp_required_sales_user', '0', 'security', 'Require authenticator for Sales User', NULL),
    ('security.api_token_default_days', '90', 'security', 'Device sign-in key lifetime (days)', NULL),
    ('retention.import_audit_days', '365', 'retention', 'Archive import audit older than (days)', NULL),
    ('retention.login_attempt_days', '90', 'retention', 'Archive sign-in attempts older than (days)', NULL),
    ('retention.consent_days', '365', 'retention', 'Archive consent records older than (days)', NULL),
    ('retention.privacy_request_days', '365', 'retention', 'Archive privacy requests older than (days)', NULL);
