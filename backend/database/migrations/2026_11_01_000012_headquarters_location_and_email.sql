-- Link a headquarters to one Location state and store its email.
-- Existing codes, managers and stockists are left as they are.
-- A state name that already matches a location is linked. Other states stay unset.

ALTER TABLE headquarters
    ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER state,
    ADD COLUMN email VARCHAR(254) NULL AFTER phone,
    ADD KEY headquarters_location_id_index (location_id),
    ADD CONSTRAINT headquarters_location_id_foreign FOREIGN KEY (location_id) REFERENCES locations (id);

UPDATE headquarters h
INNER JOIN locations l ON l.name = h.state
SET h.location_id = l.id
WHERE h.location_id IS NULL;
