-- Sprint 4 business data. Forward-only: nothing here drops Sprint 3 tables.
-- DATETIME values are UTC (the connection sets time_zone = '+00:00').
-- Soft-deleted rows stay in the table. Headquarters, managers, stockists and
-- sales entries are not deletable in this sprint.

CREATE TABLE headquarters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY headquarters_code_unique (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE managers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level TINYINT NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    parent_headquarters_id BIGINT UNSIGNED NULL,
    parent_manager_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY managers_code_unique (code),
    KEY managers_parent_headquarters_id_index (parent_headquarters_id),
    KEY managers_parent_manager_id_index (parent_manager_id),
    CONSTRAINT managers_level_check CHECK (level IN (1, 2, 3)),
    CONSTRAINT managers_parent_check CHECK (
        (level = 1 AND parent_headquarters_id IS NOT NULL AND parent_manager_id IS NULL)
        OR (level = 2 AND parent_headquarters_id IS NULL AND parent_manager_id IS NOT NULL)
        OR (level = 3 AND (
            (parent_headquarters_id IS NOT NULL AND parent_manager_id IS NULL)
            OR (parent_headquarters_id IS NULL AND parent_manager_id IS NOT NULL)
        ))
    ),
    CONSTRAINT managers_parent_headquarters_id_foreign FOREIGN KEY (parent_headquarters_id) REFERENCES headquarters (id),
    CONSTRAINT managers_parent_manager_id_foreign FOREIGN KEY (parent_manager_id) REFERENCES managers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stockists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    headquarters_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY stockists_code_unique (code),
    KEY stockists_headquarters_id_index (headquarters_id),
    CONSTRAINT stockists_headquarters_id_foreign FOREIGN KEY (headquarters_id) REFERENCES headquarters (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE catalogue_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('group_category', 'category', 'tag', 'badge') NOT NULL,
    name VARCHAR(100) NOT NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY catalogue_terms_kind_name_unique (kind, name),
    KEY catalogue_terms_kind_index (kind, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(50) NULL,
    category_id BIGINT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    -- Unique only while the product is active. Several deleted products may share a code.
    active_code VARCHAR(50) AS (IF(deleted_at IS NULL, code, NULL)) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY products_active_code_unique (active_code),
    KEY products_category_id_index (category_id),
    CONSTRAINT products_category_id_foreign FOREIGN KEY (category_id) REFERENCES catalogue_terms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_terms (
    product_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, term_id),
    KEY product_terms_term_id_index (term_id),
    CONSTRAINT product_terms_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT product_terms_term_id_foreign FOREIGN KEY (term_id) REFERENCES catalogue_terms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    action ENUM('created', 'updated', 'deleted', 'restored') NOT NULL,
    changes JSON NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY product_changes_product_id_index (product_id, changed_at),
    CONSTRAINT product_changes_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT product_changes_changed_by_foreign FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- April–March financial years. Seeded rows have created_by NULL and are kept
-- when the test database is wiped. A year created in the app starts with no entries.
CREATE TABLE financial_years (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(9) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY financial_years_label_unique (label),
    CONSTRAINT financial_years_span_check CHECK (ends_on > starts_on),
    CONSTRAINT financial_years_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO financial_years (label, starts_on, ends_on, created_by) VALUES
    ('2024-2025', '2024-04-01', '2025-03-31', NULL),
    ('2025-2026', '2025-04-01', '2026-03-31', NULL),
    ('2026-2027', '2026-04-01', '2027-03-31', NULL);

CREATE TABLE master_sheet_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    year_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    inclusion ENUM('included', 'excluded') NOT NULL,
    scheme VARCHAR(50) NULL,
    scheme_buy_qty INT UNSIGNED NULL,
    scheme_free_qty INT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY master_sheet_entries_year_product_unique (year_id, product_id),
    KEY master_sheet_entries_product_id_index (product_id),
    CONSTRAINT master_sheet_entries_year_id_foreign FOREIGN KEY (year_id) REFERENCES financial_years (id),
    CONSTRAINT master_sheet_entries_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT master_sheet_entries_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE master_sheet_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    year_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    field VARCHAR(50) NOT NULL,
    old_value VARCHAR(255) NULL,
    new_value VARCHAR(255) NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY master_sheet_changes_product_index (product_id, changed_at),
    CONSTRAINT master_sheet_changes_year_id_foreign FOREIGN KEY (year_id) REFERENCES financial_years (id),
    CONSTRAINT master_sheet_changes_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT master_sheet_changes_changed_by_foreign FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_month DATE NOT NULL,
    headquarters_id BIGINT UNSIGNED NOT NULL,
    stockist_id BIGINT UNSIGNED NOT NULL,
    achievement_value DECIMAL(14, 2) NULL,
    bonus DECIMAL(14, 2) NULL,
    deduction DECIMAL(14, 2) NULL,
    final_value DECIMAL(14, 2) NULL,
    entered_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY sales_entries_stockist_month_unique (stockist_id, sales_month),
    KEY sales_entries_headquarters_id_index (headquarters_id),
    KEY sales_entries_month_index (sales_month),
    CONSTRAINT sales_entries_amounts_check CHECK (
        (achievement_value IS NULL OR achievement_value >= 0)
        AND (bonus IS NULL OR bonus >= 0)
        AND (deduction IS NULL OR deduction >= 0)
        AND (final_value IS NULL OR final_value >= 0)
    ),
    CONSTRAINT sales_entries_headquarters_id_foreign FOREIGN KEY (headquarters_id) REFERENCES headquarters (id),
    CONSTRAINT sales_entries_stockist_id_foreign FOREIGN KEY (stockist_id) REFERENCES stockists (id),
    CONSTRAINT sales_entries_entered_by_foreign FOREIGN KEY (entered_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
