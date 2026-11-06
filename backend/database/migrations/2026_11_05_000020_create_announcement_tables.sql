-- Announcements (admin side). Additive only: new tables, no existing table or row
-- is changed. Times are UTC. Read / acknowledge columns and notification_outbox
-- stay unused until the employee app is connected.

CREATE TABLE employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(20) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    location_id BIGINT UNSIGNED NULL,
    headquarters_id BIGINT UNSIGNED NULL,
    role VARCHAR(100) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY employees_email_unique (email),
    KEY employees_status_index (status),
    KEY employees_location_id_index (location_id),
    KEY employees_headquarters_id_index (headquarters_id),
    CONSTRAINT employees_location_id_foreign FOREIGN KEY (location_id) REFERENCES locations (id),
    CONSTRAINT employees_headquarters_id_foreign FOREIGN KEY (headquarters_id) REFERENCES headquarters (id),
    CONSTRAINT employees_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT employees_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    type ENUM('general', 'hr', 'policy', 'holiday', 'sales', 'urgent') NOT NULL DEFAULT 'general',
    priority ENUM('normal', 'important', 'urgent') NOT NULL DEFAULT 'normal',
    status ENUM('draft', 'scheduled', 'published', 'expired', 'recalled') NOT NULL DEFAULT 'draft',
    banner_attachment_id BIGINT UNSIGNED NULL,
    requires_ack TINYINT(1) NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_updated TINYINT(1) NOT NULL DEFAULT 0,
    audience_all TINYINT(1) NOT NULL DEFAULT 0,
    publish_at DATETIME NULL,
    published_at DATETIME NULL,
    expires_at DATETIME NULL,
    recalled_at DATETIME NULL,
    recall_reason VARCHAR(500) NULL,
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    published_by BIGINT UNSIGNED NULL,
    recalled_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY announcements_status_index (status, publish_at),
    KEY announcements_expires_at_index (expires_at),
    CONSTRAINT announcements_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT announcements_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT announcements_published_by_foreign FOREIGN KEY (published_by) REFERENCES users (id),
    CONSTRAINT announcements_recalled_by_foreign FOREIGN KEY (recalled_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcement_audience_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    rule_type ENUM('location', 'headquarters', 'manager_level', 'role', 'employee') NOT NULL,
    rule_value VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY announcement_audience_rules_unique (announcement_id, rule_type, rule_value),
    CONSTRAINT announcement_audience_rules_announcement_id_foreign FOREIGN KEY (announcement_id) REFERENCES announcements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcement_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    recipient_type ENUM('manager', 'user', 'employee') NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    recipient_name VARCHAR(150) NOT NULL,
    recipient_contact VARCHAR(254) NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    acknowledged_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY announcement_recipients_unique (announcement_id, recipient_type, recipient_id),
    KEY announcement_recipients_person_index (recipient_type, recipient_id),
    CONSTRAINT announcement_recipients_announcement_id_foreign FOREIGN KEY (announcement_id) REFERENCES announcements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcement_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('banner', 'file') NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY announcement_attachments_announcement_id_index (announcement_id, kind),
    CONSTRAINT announcement_attachments_announcement_id_foreign FOREIGN KEY (announcement_id) REFERENCES announcements (id),
    CONSTRAINT announcement_attachments_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcement_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('general', 'hr', 'policy', 'holiday', 'sales', 'urgent') NOT NULL DEFAULT 'general',
    priority ENUM('normal', 'important', 'urgent') NOT NULL DEFAULT 'normal',
    title VARCHAR(150) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    requires_ack TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    CONSTRAINT announcement_templates_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT announcement_templates_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcement_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    action ENUM('created', 'updated', 'scheduled', 'unscheduled', 'published', 'pinned', 'unpinned', 'recalled', 'expired', 'attachment_added', 'attachment_removed', 'deleted') NOT NULL,
    changes JSON NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY announcement_changes_announcement_id_index (announcement_id, changed_at),
    CONSTRAINT announcement_changes_announcement_id_foreign FOREIGN KEY (announcement_id) REFERENCES announcements (id),
    CONSTRAINT announcement_changes_changed_by_foreign FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event VARCHAR(50) NOT NULL,
    announcement_id BIGINT UNSIGNED NOT NULL,
    recipient_type ENUM('manager', 'user', 'employee') NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending', 'sent', 'skipped') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY notification_outbox_status_index (status, created_at),
    KEY notification_outbox_announcement_id_index (announcement_id),
    CONSTRAINT notification_outbox_announcement_id_foreign FOREIGN KEY (announcement_id) REFERENCES announcements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;