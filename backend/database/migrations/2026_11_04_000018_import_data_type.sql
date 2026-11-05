-- Excel import: record the Data Type of each row error and each change so
-- the consolidated preview, error report and rollback list can show it.
-- Additive only: two nullable columns, no existing data is changed.

ALTER TABLE import_errors ADD COLUMN data_type VARCHAR(40) NULL AFTER line_number;

ALTER TABLE import_changes ADD COLUMN data_type VARCHAR(40) NULL AFTER line_number;
