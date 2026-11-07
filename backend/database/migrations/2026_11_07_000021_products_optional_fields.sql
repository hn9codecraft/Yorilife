-- Products module: optional classification fields and product-level commercials.
-- Additive only. Soft-delete and unique names stay as they are.
-- Group Category / Product Category links are optional; prices live on products
-- for the Product List (Master Sheet year prices stay on master_sheet_entries).

ALTER TABLE catalogue_terms
    ADD COLUMN description VARCHAR(500) NULL AFTER name,
    ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER description;

ALTER TABLE products
    ADD COLUMN strip VARCHAR(50) NULL AFTER category_id,
    ADD COLUMN pack VARCHAR(50) NULL AFTER strip,
    ADD COLUMN mrp DECIMAL(14, 2) NULL AFTER pack,
    ADD COLUMN ptr DECIMAL(14, 2) NULL AFTER mrp,
    ADD COLUMN pts DECIMAL(14, 2) NULL AFTER ptr;

ALTER TABLE products
    ADD CONSTRAINT products_list_prices_check CHECK (
        (mrp IS NULL OR mrp >= 0)
        AND (ptr IS NULL OR ptr >= 0)
        AND (pts IS NULL OR pts >= 0)
    );
