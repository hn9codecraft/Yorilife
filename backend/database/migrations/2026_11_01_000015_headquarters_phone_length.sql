-- Headquarters phone numbers can include a country code. Existing 10-digit
-- values stay as they are. Nothing is dropped.

ALTER TABLE headquarters
    MODIFY phone VARCHAR(20) NULL;

ALTER TABLE location_headquarters
    MODIFY phone VARCHAR(20) NULL;
