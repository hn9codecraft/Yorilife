-- Product module: group/category link, year-specific commercials, product-level scheme.
-- Forward-only. Does not delete rows or drop the old year scheme columns.
-- A product whose financial years disagree on scheme is left with a NULL
-- product scheme. Those year values stay on master_sheet_entries for review.

ALTER TABLE catalogue_terms
    ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER kind,
    ADD KEY catalogue_terms_parent_id_index (parent_id),
    ADD CONSTRAINT catalogue_terms_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES catalogue_terms (id);

ALTER TABLE products
    ADD COLUMN scheme VARCHAR(50) NULL AFTER category_id,
    ADD COLUMN scheme_buy_qty INT UNSIGNED NULL AFTER scheme,
    ADD COLUMN scheme_free_qty INT UNSIGNED NULL AFTER scheme_buy_qty;

ALTER TABLE master_sheet_entries
    ADD COLUMN strip_pack VARCHAR(50) NULL AFTER inclusion,
    ADD COLUMN mrp DECIMAL(14, 2) NULL AFTER strip_pack,
    ADD COLUMN ptr DECIMAL(14, 2) NULL AFTER mrp,
    ADD COLUMN pts DECIMAL(14, 2) NULL AFTER ptr,
    MODIFY COLUMN inclusion ENUM('included', 'excluded') NULL;

ALTER TABLE master_sheet_entries
    ADD CONSTRAINT master_sheet_entries_prices_check CHECK (
        (mrp IS NULL OR mrp >= 0)
        AND (ptr IS NULL OR ptr >= 0)
        AND (pts IS NULL OR pts >= 0)
    );

UPDATE products AS p
INNER JOIN (
    SELECT product_id,
           MIN(scheme) AS scheme,
           MIN(scheme_buy_qty) AS scheme_buy_qty,
           MIN(scheme_free_qty) AS scheme_free_qty
    FROM master_sheet_entries
    WHERE scheme IS NOT NULL AND scheme <> ''
    GROUP BY product_id
    HAVING COUNT(DISTINCT scheme) = 1
) AS agreed ON agreed.product_id = p.id
SET p.scheme = agreed.scheme,
    p.scheme_buy_qty = agreed.scheme_buy_qty,
    p.scheme_free_qty = agreed.scheme_free_qty
WHERE p.scheme IS NULL;
