-- Sales entries move to the Headquarter List; Sales Office forms become optional.
-- Additive only: nothing is dropped and no row is removed.
--  * sales_entries.location_headquarters_id: the stockist's Headquarter (State /
--    Headquarter List) at the time of the entry. The older headquarters_id stays
--    for existing rows and becomes optional.
--  * stockists.name and locations.code may be left blank.
--  (location_headquarters already has optional code, address, pincode, phone and email.)

ALTER TABLE sales_entries
    MODIFY COLUMN headquarters_id BIGINT UNSIGNED NULL,
    ADD COLUMN location_headquarters_id BIGINT UNSIGNED NULL AFTER headquarters_id,
    ADD KEY sales_entries_location_headquarters_id_index (location_headquarters_id),
    ADD CONSTRAINT sales_entries_location_headquarters_id_foreign FOREIGN KEY (location_headquarters_id) REFERENCES location_headquarters (id) ON DELETE SET NULL;

-- Existing entries take the Headquarter their stockist points at, or the one behind the older headquarters.
UPDATE sales_entries se
INNER JOIN stockists s ON s.id = se.stockist_id
SET se.location_headquarters_id = s.location_headquarters_id
WHERE se.location_headquarters_id IS NULL AND s.location_headquarters_id IS NOT NULL;

UPDATE sales_entries se
INNER JOIN headquarters h ON h.id = se.headquarters_id
SET se.location_headquarters_id = h.location_headquarters_id
WHERE se.location_headquarters_id IS NULL AND h.location_headquarters_id IS NOT NULL;

ALTER TABLE stockists
    MODIFY COLUMN name VARCHAR(150) NULL;

ALTER TABLE locations
    MODIFY COLUMN code VARCHAR(20) NULL;