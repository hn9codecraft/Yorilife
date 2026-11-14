-- Bonus Calc (Sales Management). Additive only: new tables, no existing
-- table or row is changed. Times are UTC.
--
-- bonus_calc_rows is the financial-year list of employees. Removing someone
-- sets deleted_at; the sales employee is never deleted from here.
-- The same employee can be on the list once per financial year (active rows).
--
-- bonus_calc_lines stores one product row per financial year, employee,
-- calendar month, stockist and product. Rates are copied from the product
-- at the first save and are not rewritten, so a later price change does not
-- alter a month that was already saved. bonus_amount is product sales
-- times that stored net rate, rounded half up to two decimal places.

CREATE TABLE bonus_calc_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    financial_year CHAR(7) NOT NULL,
    sales_employee_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    active_employee_year VARCHAR(32)
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, CONCAT(financial_year, '#', sales_employee_id), NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY bonus_calc_rows_active_employee_year_unique (active_employee_year),
    KEY bonus_calc_rows_year_index (financial_year, deleted_at),
    KEY bonus_calc_rows_sales_employee_id_index (sales_employee_id),
    CONSTRAINT bonus_calc_rows_sales_employee_id_foreign FOREIGN KEY (sales_employee_id) REFERENCES sales_employees (id),
    CONSTRAINT bonus_calc_rows_year_check CHECK (financial_year REGEXP '^[0-9]{4}-[0-9]{2}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bonus_calc_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    financial_year CHAR(7) NOT NULL,
    sales_employee_id BIGINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    stockist_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    packaging_unit VARCHAR(100) NULL,
    mrp DECIMAL(12, 2) NULL,
    ptr DECIMAL(12, 2) NULL,
    pts DECIMAL(12, 2) NULL,
    scheme VARCHAR(50) NULL,
    net_rate DECIMAL(12, 2) NULL,
    opening_qty DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    new_purchase_qty DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    product_sales_qty DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    bonus_amount DECIMAL(16, 2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bonus_calc_lines_slot_unique (financial_year, sales_employee_id, month, stockist_id, product_id),
    KEY bonus_calc_lines_month_index (financial_year, sales_employee_id, month),
    KEY bonus_calc_lines_stockist_id_index (stockist_id),
    KEY bonus_calc_lines_product_id_index (product_id),
    CONSTRAINT bonus_calc_lines_sales_employee_id_foreign FOREIGN KEY (sales_employee_id) REFERENCES sales_employees (id),
    CONSTRAINT bonus_calc_lines_stockist_id_foreign FOREIGN KEY (stockist_id) REFERENCES stockists (id),
    CONSTRAINT bonus_calc_lines_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT bonus_calc_lines_year_check CHECK (financial_year REGEXP '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT bonus_calc_lines_month_check CHECK (month BETWEEN 1 AND 12),
    CONSTRAINT bonus_calc_lines_opening_qty_check CHECK (opening_qty >= 0),
    CONSTRAINT bonus_calc_lines_new_purchase_qty_check CHECK (new_purchase_qty >= 0),
    CONSTRAINT bonus_calc_lines_product_sales_qty_check CHECK (product_sales_qty >= 0),
    CONSTRAINT bonus_calc_lines_net_rate_check CHECK (net_rate IS NULL OR net_rate >= 0),
    CONSTRAINT bonus_calc_lines_mrp_check CHECK (mrp IS NULL OR mrp >= 0),
    CONSTRAINT bonus_calc_lines_ptr_check CHECK (ptr IS NULL OR ptr >= 0),
    CONSTRAINT bonus_calc_lines_pts_check CHECK (pts IS NULL OR pts >= 0),
    CONSTRAINT bonus_calc_lines_bonus_amount_check CHECK (bonus_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
