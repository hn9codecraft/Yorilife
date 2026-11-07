-- Company-wide audit log, unified field-level change history, and settings
-- (read-only in Phase 1). Additive only: no existing table or row is changed.

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at DATETIME NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    actor_label VARCHAR(150) NULL,
    module VARCHAR(40) NOT NULL,
    action VARCHAR(80) NOT NULL,
    result ENUM('ok', 'denied', 'failed') NOT NULL DEFAULT 'ok',
    target_type VARCHAR(40) NULL,
    target_id BIGINT UNSIGNED NULL,
    target_label VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    request_id CHAR(36) NULL,
    details JSON NULL,
    PRIMARY KEY (id),
    KEY audit_logs_occurred_at_index (occurred_at),
    KEY audit_logs_actor_occurred_index (actor_id, occurred_at),
    KEY audit_logs_module_action_index (module, action, occurred_at),
    KEY audit_logs_result_index (result, occurred_at),
    KEY audit_logs_target_index (target_type, target_id),
    CONSTRAINT audit_logs_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entity_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    entity_label VARCHAR(255) NULL,
    field VARCHAR(100) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'app',
    request_id CHAR(36) NULL,
    PRIMARY KEY (id),
    KEY entity_changes_entity_index (entity_type, entity_id, changed_at),
    KEY entity_changes_changed_at_index (changed_at),
    KEY entity_changes_changed_by_index (changed_by, changed_at),
    CONSTRAINT entity_changes_changed_by_foreign FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    group_name VARCHAR(40) NOT NULL,
    label VARCHAR(150) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    KEY settings_group_name_index (group_name),
    CONSTRAINT settings_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, group_name, label, updated_by) VALUES
    ('company.name', 'Yorilife', 'company', 'Company name', NULL),
    ('company.address', '', 'company', 'Company address', NULL),
    ('company.gstin', '', 'company', 'GSTIN', NULL),
    ('company.logo_path', '', 'company', 'Logo path', NULL);
