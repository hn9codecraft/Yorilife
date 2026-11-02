-- Sprint 5: headquarters contact details. Existing rows keep NULL until edited.
-- Nothing here drops data or changes manager and stockist relationships.

ALTER TABLE headquarters
    ADD COLUMN state VARCHAR(100) NULL AFTER name,
    ADD COLUMN address VARCHAR(255) NULL AFTER state,
    ADD COLUMN pincode CHAR(6) NULL AFTER address,
    ADD COLUMN phone VARCHAR(10) NULL AFTER pincode;
