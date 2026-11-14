-- Sales Office employee list. Additive only: new tables, no existing table
-- or row is changed. The older line-manager tables (managers, manager_states)
-- stay in place. Times are UTC. Every employee column is optional.

CREATE TABLE sales_employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NULL,
    employee_code VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(254) NULL,
    designation VARCHAR(10) NULL,
    location_id BIGINT UNSIGNED NULL,
    location_headquarters_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY sales_employees_designation_index (designation),
    KEY sales_employees_location_id_index (location_id),
    KEY sales_employees_location_headquarters_id_index (location_headquarters_id),
    KEY sales_employees_deleted_at_index (deleted_at),
    CONSTRAINT sales_employees_designation_check CHECK (
        designation IS NULL OR designation IN ('GM', 'ZSM', 'DSM', 'RBM', 'DBM', 'ABM', 'BO')
    ),
    CONSTRAINT sales_employees_location_id_foreign FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL,
    CONSTRAINT sales_employees_location_headquarters_id_foreign FOREIGN KEY (location_headquarters_id) REFERENCES location_headquarters (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One optional manager per designation above the employee. The slots are
-- independent: a row is stored only when that level was chosen.
CREATE TABLE sales_employee_managers (
    employee_id BIGINT UNSIGNED NOT NULL,
    manager_designation VARCHAR(10) NOT NULL,
    manager_employee_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (employee_id, manager_designation),
    KEY sales_employee_managers_manager_index (manager_employee_id),
    CONSTRAINT sales_employee_managers_designation_check CHECK (
        manager_designation IN ('GM', 'ZSM', 'DSM', 'RBM', 'DBM', 'ABM', 'BO')
    ),
    CONSTRAINT sales_employee_managers_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES sales_employees (id),
    CONSTRAINT sales_employee_managers_manager_employee_id_foreign FOREIGN KEY (manager_employee_id) REFERENCES sales_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
