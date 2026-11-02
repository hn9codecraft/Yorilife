-- Short unique codes for Location states. Existing rows keep their ids.

ALTER TABLE locations
    ADD COLUMN code VARCHAR(20) NULL AFTER name;

UPDATE locations SET code = 'GUJ' WHERE name = 'Gujarat' AND code IS NULL;
UPDATE locations SET code = 'AP' WHERE name = 'Andhra Pradesh' AND code IS NULL;
UPDATE locations SET code = 'ARP' WHERE name = 'Arunachal Pradesh' AND code IS NULL;
UPDATE locations SET code = 'ASM' WHERE name = 'Assam' AND code IS NULL;
UPDATE locations SET code = 'BIH' WHERE name = 'Bihar' AND code IS NULL;
UPDATE locations SET code = 'CG' WHERE name = 'Chhattisgarh' AND code IS NULL;
UPDATE locations SET code = CONCAT('L', id) WHERE code IS NULL;

ALTER TABLE locations
    MODIFY code VARCHAR(20) NOT NULL,
    ADD UNIQUE KEY locations_code_unique (code);
