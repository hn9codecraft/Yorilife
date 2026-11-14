-- Products rebuild: Product Type and Product Group masters, and the new
-- Product List fields. Additive only: no table or column is dropped and no
-- data is removed. The old catalogue terms, tags, badges, master sheet and
-- product columns (strip, pack, category) stay in the database.
--
-- Names are unique (case-insensitive, utf8mb4_unicode_ci) among records that
-- are not deleted; a deleted record keeps its row for history.
-- The product SKU uses the existing products.code column, which is already
-- unique among active products (products_active_code_unique).

CREATE TABLE product_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    deleted_at DATETIME NULL,
    active_name VARCHAR(100) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY product_types_active_name_unique (active_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    deleted_at DATETIME NULL,
    active_name VARCHAR(100) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY product_groups_active_name_unique (active_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
    ADD COLUMN product_type_id BIGINT UNSIGNED NULL AFTER category_id,
    ADD COLUMN product_group_id BIGINT UNSIGNED NULL AFTER product_type_id,
    ADD COLUMN packaging_unit VARCHAR(100) NULL AFTER pack,
    ADD COLUMN net_rate DECIMAL(12, 2) NULL AFTER pts,
    ADD KEY products_product_type_id_index (product_type_id),
    ADD KEY products_product_group_id_index (product_group_id),
    ADD CONSTRAINT products_product_type_id_foreign FOREIGN KEY (product_type_id) REFERENCES product_types (id),
    ADD CONSTRAINT products_product_group_id_foreign FOREIGN KEY (product_group_id) REFERENCES product_groups (id),
    ADD CONSTRAINT products_net_rate_check CHECK (net_rate IS NULL OR net_rate >= 0);