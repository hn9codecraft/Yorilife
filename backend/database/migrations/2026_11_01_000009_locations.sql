-- Location states for 3rd line managers. Headquarters keep their own state text.
-- Existing manager rows are not rewritten. Assigned state names, if any, are kept
-- by linking them to a location row.

CREATE TABLE locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY locations_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO locations (name) VALUES
('Gujarat'),
('Andhra Pradesh'),
('Arunachal Pradesh'),
('Assam'),
('Bihar'),
('Chhattisgarh');

INSERT IGNORE INTO locations (name)
SELECT DISTINCT state FROM manager_states;

ALTER TABLE manager_states
    ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER manager_id;

UPDATE manager_states ms
INNER JOIN locations l ON l.name = ms.state
SET ms.location_id = l.id;

ALTER TABLE manager_states
    DROP PRIMARY KEY,
    DROP COLUMN state,
    MODIFY location_id BIGINT UNSIGNED NOT NULL,
    ADD PRIMARY KEY (manager_id, location_id),
    ADD CONSTRAINT manager_states_location_id_foreign FOREIGN KEY (location_id) REFERENCES locations (id);

ALTER TABLE managers
    ADD COLUMN email VARCHAR(254) NULL AFTER code,
    ADD COLUMN phone VARCHAR(20) NULL AFTER email,
    ADD UNIQUE KEY managers_email_unique (email);
