-- Phase 2: hash chain, settings history, account lock, archive flags, list indexes.
-- Additive only. No row is deleted.

ALTER TABLE audit_logs
    ADD COLUMN prev_hash CHAR(64) NULL AFTER details,
    ADD COLUMN row_hash CHAR(64) NULL AFTER prev_hash,
    ADD COLUMN archived_at DATETIME NULL AFTER row_hash,
    ADD KEY audit_logs_archived_occurred_index (archived_at, occurred_at, id),
    ADD KEY audit_logs_module_occurred_index (module, occurred_at, id);

ALTER TABLE entity_changes
    ADD COLUMN archived_at DATETIME NULL AFTER request_id,
    ADD KEY entity_changes_archived_changed_index (archived_at, changed_at, id),
    ADD KEY entity_changes_type_changed_index (entity_type, changed_at, id);

ALTER TABLE product_changes
    ADD COLUMN archived_at DATETIME NULL,
    ADD KEY product_changes_archived_changed_index (archived_at, changed_at, id);

ALTER TABLE master_sheet_changes
    ADD COLUMN archived_at DATETIME NULL,
    ADD KEY master_sheet_changes_archived_changed_index (archived_at, changed_at, id);

ALTER TABLE announcement_changes
    ADD COLUMN archived_at DATETIME NULL,
    ADD KEY announcement_changes_archived_changed_index (archived_at, changed_at, id);

ALTER TABLE import_audit_logs
    ADD KEY import_audit_logs_created_at_index (created_at, id);

CREATE TABLE audit_chain_head (
    id TINYINT UNSIGNED NOT NULL,
    last_log_id BIGINT UNSIGNED NULL,
    last_hash CHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO audit_chain_head (id, last_log_id, last_hash, updated_at) VALUES
    (1, NULL, '0000000000000000000000000000000000000000000000000000000000000000', UTC_TIMESTAMP());

CREATE TABLE setting_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    request_id CHAR(36) NULL,
    PRIMARY KEY (id),
    KEY setting_versions_key_index (setting_key, id),
    KEY setting_versions_changed_at_index (changed_at),
    CONSTRAINT setting_versions_changed_by_foreign FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    MODIFY status ENUM('active', 'disabled', 'locked') NOT NULL DEFAULT 'active';

INSERT INTO settings (setting_key, setting_value, group_name, label, updated_by) VALUES
    ('general.support_email', '', 'general', 'Support email', NULL),
    ('general.records_per_page', '50', 'general', 'Default list size', NULL),
    ('security.password_min_length', '12', 'security', 'Minimum password length', NULL),
    ('security.password_require_uppercase', '0', 'security', 'Password requires an uppercase letter', NULL),
    ('security.password_require_lowercase', '0', 'security', 'Password requires a lowercase letter', NULL),
    ('security.password_require_number', '0', 'security', 'Password requires a number', NULL),
    ('security.password_require_symbol', '0', 'security', 'Password requires a symbol', NULL),
    ('security.session_idle_minutes', '30', 'security', 'Idle timeout (minutes)', NULL),
    ('security.session_absolute_hours', '12', 'security', 'Absolute session lifetime (hours)', NULL),
    ('security.lockout_max_failures', '5', 'security', 'Failed attempts before lockout', NULL),
    ('security.lockout_window_minutes', '15', 'security', 'Lockout window (minutes)', NULL),
    ('retention.audit_log_days', '365', 'retention', 'Archive audit logs older than (days)', NULL),
    ('retention.change_history_days', '365', 'retention', 'Archive change history older than (days)', NULL);
