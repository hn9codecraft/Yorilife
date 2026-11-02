-- Contact details belong to the location-headquarters name. Existing office
-- codes, managers, and stockists stay as they are. A linked office's details
-- are copied onto the name once and are not rewritten later by this migration.

ALTER TABLE location_headquarters
    ADD COLUMN code VARCHAR(50) NULL AFTER name,
    ADD COLUMN address VARCHAR(255) NULL AFTER code,
    ADD COLUMN pincode VARCHAR(6) NULL AFTER address,
    ADD COLUMN phone VARCHAR(10) NULL AFTER pincode,
    ADD COLUMN email VARCHAR(255) NULL AFTER phone;

UPDATE location_headquarters lh
INNER JOIN (
    SELECT location_headquarters_id, MIN(id) AS id
    FROM headquarters
    WHERE location_headquarters_id IS NOT NULL
    GROUP BY location_headquarters_id
) picked ON picked.location_headquarters_id = lh.id
INNER JOIN headquarters h ON h.id = picked.id
SET lh.code = h.code,
    lh.address = h.address,
    lh.pincode = h.pincode,
    lh.phone = h.phone,
    lh.email = h.email
WHERE lh.code IS NULL;

ALTER TABLE location_headquarters
    ADD UNIQUE KEY location_headquarters_code_unique (code);
