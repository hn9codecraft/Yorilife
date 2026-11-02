-- Optional stockist contact details. Existing codes and headquarters links stay as they are.

ALTER TABLE stockists
    ADD COLUMN address VARCHAR(255) NULL AFTER code,
    ADD COLUMN pincode VARCHAR(6) NULL AFTER address,
    ADD COLUMN phone VARCHAR(20) NULL AFTER pincode,
    ADD COLUMN email VARCHAR(255) NULL AFTER phone;
