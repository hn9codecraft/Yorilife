-- CSV import history, row errors, per-record change snapshots (for rollback)
-- and an audit log. Additive only: no existing table or row is changed.

CREATE TABLE imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(40) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    backup_sha256 CHAR(64) NULL,
    status ENUM('previewed', 'completed', 'failed', 'rolled_back', 'partially_rolled_back') NOT NULL DEFAULT 'previewed',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    preview JSON NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_by BIGINT UNSIGNED NULL,
    imported_at DATETIME NULL,
    rolled_back_by BIGINT UNSIGNED NULL,
    rolled_back_at DATETIME NULL,
    rollback_summary JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY imports_status_index (status),
    KEY imports_uploaded_at_index (uploaded_at),
    CONSTRAINT imports_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users (id),
    CONSTRAINT imports_imported_by_foreign FOREIGN KEY (imported_by) REFERENCES users (id),
    CONSTRAINT imports_rolled_back_by_foreign FOREIGN KEY (rolled_back_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_errors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    field VARCHAR(100) NOT NULL,
    message VARCHAR(500) NOT NULL,
    row_data JSON NULL,
    PRIMARY KEY (id),
    KEY import_errors_import_id_index (import_id, line_number),
    CONSTRAINT import_errors_import_id_foreign FOREIGN KEY (import_id) REFERENCES imports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    record_table VARCHAR(40) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    record_code VARCHAR(50) NULL,
    action ENUM('created', 'updated') NOT NULL,
    before_data JSON NULL,
    after_data JSON NOT NULL,
    rollback_status ENUM('pending', 'restored', 'deleted', 'skipped') NOT NULL DEFAULT 'pending',
    rollback_note VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY import_changes_import_id_index (import_id),
    KEY import_changes_record_index (record_table, record_id),
    CONSTRAINT import_changes_import_id_foreign FOREIGN KEY (import_id) REFERENCES imports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id BIGINT UNSIGNED NOT NULL,
    action ENUM('upload', 'import', 'rollback', 'backup_download', 'file_download', 'errors_download') NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY import_audit_logs_import_id_index (import_id, created_at),
    CONSTRAINT import_audit_logs_import_id_foreign FOREIGN KEY (import_id) REFERENCES imports (id),
    CONSTRAINT import_audit_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
