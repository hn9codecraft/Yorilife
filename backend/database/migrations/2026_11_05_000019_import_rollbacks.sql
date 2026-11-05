-- Import rollbacks can now run more than once (one per chosen set of records).
-- Additive: a new import_rollbacks table with one row per rollback run, filled
-- from the existing single rollback summaries. Records that an earlier partial
-- rollback left unselected go back to pending so they can still be rolled back;
-- nothing is deleted.

CREATE TABLE import_rollbacks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    restored INT UNSIGNED NOT NULL DEFAULT 0,
    deleted INT UNSIGNED NOT NULL DEFAULT 0,
    skipped INT UNSIGNED NOT NULL DEFAULT 0,
    not_selected INT UNSIGNED NOT NULL DEFAULT 0,
    items JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY import_rollbacks_import_id_index (import_id),
    CONSTRAINT import_rollbacks_import_id_foreign FOREIGN KEY (import_id) REFERENCES imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO import_rollbacks (import_id, user_id, restored, deleted, skipped, not_selected, items, created_at)
SELECT id, rolled_back_by,
    COALESCE(JSON_EXTRACT(rollback_summary, '$.restored'), 0),
    COALESCE(JSON_EXTRACT(rollback_summary, '$.deleted'), 0),
    COALESCE(JSON_EXTRACT(rollback_summary, '$.skipped'), 0),
    COALESCE(JSON_EXTRACT(rollback_summary, '$.notSelected'), 0),
    COALESCE(JSON_EXTRACT(rollback_summary, '$.items'), JSON_ARRAY()),
    COALESCE(rolled_back_at, CURRENT_TIMESTAMP)
FROM imports
WHERE rollback_summary IS NOT NULL;

UPDATE import_changes
SET rollback_status = 'pending', rollback_note = NULL
WHERE rollback_status = 'skipped' AND rollback_note = 'Not selected, so it was left as imported.';