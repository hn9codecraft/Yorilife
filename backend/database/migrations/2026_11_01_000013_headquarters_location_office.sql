-- Link an operational headquarters to one location-headquarters name.
-- Existing offices keep their manager, stockist, and code. Names already stored
-- on a location are copied into location_headquarters once, without a second office.

ALTER TABLE headquarters
    ADD COLUMN location_headquarters_id BIGINT UNSIGNED NULL AFTER location_id,
    ADD CONSTRAINT headquarters_location_headquarters_id_foreign
        FOREIGN KEY (location_headquarters_id) REFERENCES location_headquarters (id) ON DELETE SET NULL;

INSERT INTO location_headquarters (location_id, name, position)
SELECT h.location_id, h.name, 1
FROM headquarters h
WHERE h.location_id IS NOT NULL
  AND h.name <> ''
  AND NOT EXISTS (
      SELECT 1 FROM location_headquarters lh
      WHERE lh.location_id = h.location_id AND lh.name = h.name
  )
GROUP BY h.location_id, h.name;

UPDATE headquarters h
INNER JOIN location_headquarters lh ON lh.location_id = h.location_id AND lh.name = h.name
SET h.location_headquarters_id = lh.id
WHERE h.location_headquarters_id IS NULL;
