-- Stockist form update. Additive only: nothing is dropped and no row is removed.
--  * headquarters_id (the older Sales Office headquarters) becomes optional.
--    It stays for sales entries and the hierarchy view; new stockists pick a
--    headquarter from the State / Headquarter list instead.
--  * location_headquarters_id links a stockist to the Headquarter list.
--  * assigned_employee_id links a stockist to the Sales Office employee list.
--  * Drug, Food and FSSAI licence numbers, each with a valid-up-to date.
-- The code column keeps its existing unique index (case-insensitive collation).

ALTER TABLE stockists
    MODIFY COLUMN headquarters_id BIGINT UNSIGNED NULL,
    ADD COLUMN location_headquarters_id BIGINT UNSIGNED NULL AFTER headquarters_id,
    ADD COLUMN assigned_employee_id BIGINT UNSIGNED NULL AFTER location_headquarters_id,
    ADD COLUMN drug_licence_no VARCHAR(100) NULL AFTER email,
    ADD COLUMN drug_licence_valid_upto DATE NULL AFTER drug_licence_no,
    ADD COLUMN food_licence_no VARCHAR(100) NULL AFTER drug_licence_valid_upto,
    ADD COLUMN food_licence_valid_upto DATE NULL AFTER food_licence_no,
    ADD COLUMN fssai_licence_no VARCHAR(100) NULL AFTER food_licence_valid_upto,
    ADD COLUMN fssai_licence_valid_upto DATE NULL AFTER fssai_licence_no,
    ADD KEY stockists_location_headquarters_id_index (location_headquarters_id),
    ADD KEY stockists_assigned_employee_id_index (assigned_employee_id),
    ADD CONSTRAINT stockists_location_headquarters_id_foreign FOREIGN KEY (location_headquarters_id) REFERENCES location_headquarters (id) ON DELETE SET NULL,
    ADD CONSTRAINT stockists_assigned_employee_id_foreign FOREIGN KEY (assigned_employee_id) REFERENCES sales_employees (id) ON DELETE SET NULL;

-- Existing stockists take the Headquarter-list office their older headquarters points at.
UPDATE stockists s
INNER JOIN headquarters h ON h.id = s.headquarters_id
SET s.location_headquarters_id = h.location_headquarters_id
WHERE s.location_headquarters_id IS NULL AND h.location_headquarters_id IS NOT NULL;