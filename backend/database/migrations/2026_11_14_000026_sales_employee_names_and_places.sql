-- Sales Office employees: first and last name, unique contact fields among
-- active rows, and many states and headquarters. Nothing is dropped.
-- The old name, location_id, and location_headquarters_id columns stay.
-- New saves write the join tables and keep name as the full name.

ALTER TABLE sales_employees
    MODIFY name VARCHAR(301) NULL,
    ADD COLUMN first_name VARCHAR(150) NULL AFTER name,
    ADD COLUMN last_name VARCHAR(150) NULL AFTER first_name;

-- First word of the stored name becomes the first name. The rest, if any,
-- becomes the last name. "Bina Rao Officer" -> Bina / Rao Officer.
UPDATE sales_employees
SET
    first_name = NULLIF(SUBSTRING_INDEX(TRIM(name), ' ', 1), ''),
    last_name = NULLIF(TRIM(SUBSTRING(TRIM(name), CHAR_LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', 1)) + 1)), '')
WHERE name IS NOT NULL AND TRIM(name) <> '';

-- Blank and soft-deleted rows stay out of the unique indexes (NULL is not a duplicate).
-- Email is folded to lower case. Employee code keeps its letter case. Phone is trimmed.
ALTER TABLE sales_employees
    ADD COLUMN active_employee_code VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
        GENERATED ALWAYS AS (
            CASE
                WHEN deleted_at IS NULL AND employee_code IS NOT NULL AND employee_code <> ''
                THEN employee_code
                ELSE NULL
            END
        ) STORED,
    ADD COLUMN active_phone VARCHAR(30)
        GENERATED ALWAYS AS (
            CASE
                WHEN deleted_at IS NULL AND phone IS NOT NULL AND TRIM(phone) <> ''
                THEN TRIM(phone)
                ELSE NULL
            END
        ) STORED,
    ADD COLUMN active_email VARCHAR(254)
        GENERATED ALWAYS AS (
            CASE
                WHEN deleted_at IS NULL AND email IS NOT NULL AND TRIM(email) <> ''
                THEN LOWER(TRIM(email))
                ELSE NULL
            END
        ) STORED,
    ADD UNIQUE KEY sales_employees_active_employee_code_unique (active_employee_code),
    ADD UNIQUE KEY sales_employees_active_phone_unique (active_phone),
    ADD UNIQUE KEY sales_employees_active_email_unique (active_email);

CREATE TABLE sales_employee_states (
    employee_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (employee_id, location_id),
    KEY sales_employee_states_location_index (location_id),
    CONSTRAINT sales_employee_states_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES sales_employees (id),
    CONSTRAINT sales_employee_states_location_id_foreign FOREIGN KEY (location_id) REFERENCES locations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_employee_headquarters (
    employee_id BIGINT UNSIGNED NOT NULL,
    location_headquarters_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (employee_id, location_headquarters_id),
    KEY sales_employee_headquarters_hq_index (location_headquarters_id),
    CONSTRAINT sales_employee_headquarters_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES sales_employees (id),
    CONSTRAINT sales_employee_headquarters_hq_id_foreign FOREIGN KEY (location_headquarters_id) REFERENCES location_headquarters (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sales_employee_states (employee_id, location_id)
SELECT id, location_id
FROM sales_employees
WHERE deleted_at IS NULL AND location_id IS NOT NULL;

INSERT INTO sales_employee_headquarters (employee_id, location_headquarters_id)
SELECT id, location_headquarters_id
FROM sales_employees
WHERE deleted_at IS NULL AND location_headquarters_id IS NOT NULL;
